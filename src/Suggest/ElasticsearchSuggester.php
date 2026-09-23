<?php

declare(strict_types=1);

namespace App\Suggest;

use App\Model\SuggestQuery;
use App\Model\Suggestion;
use App\Model\SuggestionType;
use App\Model\SuggestResult;
use App\Support\Normalizer;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use InvalidArgumentException;

/**
 * Elasticsearch 8 side of the comparison.
 *
 * The shape of the problem: the user types one string that is an arbitrary mix
 * of street, postcode and municipality ("gent kort", "2230 goorb",
 * "korte linden aalst"), in any order, with the last word usually half finished.
 * There is no field to parse the query into, so the query is built as
 *
 *   recall gate (every token must appear somewhere)
 *     x precision signals (where did the tokens appear, and how)
 *     x a damped popularity prior
 *
 * with the three stages kept separate on purpose: the gate decides *whether* a
 * document can appear at all, and nothing below it can smuggle a document back
 * in. That makes the ranking safe to tune - a boost can reorder results but can
 * never widen them.
 */
final class ElasticsearchSuggester implements SuggesterInterface
{
    private const ENGINE = 'elasticsearch';

    /**
     * Counting every one of the 4.2M documents that match "straat" on every
     * keystroke is pure waste: the UI shows ten rows and a "many" indicator, and
     * an exact total costs a full collection over the matching set. 1000 is
     * enough to tell "a handful" from "too many to list" and is where
     * Elasticsearch stops counting; the relation ("eq"/"gte") goes into debug so
     * the UI can say "1000+".
     */
    private const TRACK_TOTAL_HITS = 1000;

    /**
     * Only what Suggestion needs. Fetching the n-grammed fields back would mean
     * shipping the whole search_text haystack for every hit for nothing.
     *
     * @var list<string>
     */
    private const SOURCE_FIELDS = [
        'id',
        'doc_type',
        'label',
        'place_name',
        'street_name',
        'house_number',
        'box_number',
        'postcode',
        'post_name',
        'municipality_name',
        'nis_code',
        'location',
        'popularity',
    ];

    // --- Scoring weights -----------------------------------------------------
    // Deliberately a small, flat set of constants rather than tuned magic
    // numbers inline: the whole point of the POC is being able to explain why a
    // result is where it is, and to move one number at a time.

    /**
     * The name is *exactly* what has been typed, not merely started by it.
     * Above BOOST_NAME_PHRASE_PREFIX because a finished word is a much stronger
     * statement of intent than a prefix: once "goorbaan" is fully typed, the
     * street called Goorbaan should outrank every Goorbaanstraat. The two
     * clauses stack - an exact hit is also a prefix hit.
     */
    private const BOOST_NAME_EXACT = 12.0;

    /** The name literally starts with what has been typed. Nothing beats this. */
    private const BOOST_NAME_PHRASE_PREFIX = 8.0;

    /** Some of the typed words prefix-match words of the name, in any order. */
    private const BOOST_NAME_MATCH = 3.0;

    /** A complete 4-digit postcode was typed and this document carries it. */
    private const BOOST_POSTCODE_EXACT = 6.0;

    /** A 1-3 digit last token that is plausibly a postcode being typed. */
    private const BOOST_POSTCODE_PARTIAL = 3.0;

    /**
     * A house number or box number the document actually carries. Only ever
     * earned at address level; below the postcode and name boosts because
     * getting the street right matters more than getting the number right.
     */
    private const BOOST_ADDRESS_DETAIL = 4.0;

    private const BOOST_MUNICIPALITY = 1.5;

    /**
     * An alternative name for this document: the FR/DE translation, or the
     * sub-locality a postcode covers. Deliberately below BOOST_NAME_MATCH and
     * not "scored like a name hit": DocumentSource also files the municipality
     * name under aliases, so a clause weighted like the real name would count
     * the city twice for every document in the index.
     */
    private const BOOST_ALIAS = 1.8;

    private const BOOST_POST_NAME = 1.2;

    /** Low: fuzzy is a safety net, never an argument for promoting a document. */
    private const BOOST_FUZZY = 0.4;

    /**
     * Bounds the term expansion of the fuzzy clause. Left generous enough to
     * find the intended word, tight enough that a typo cannot turn one keystroke
     * into a thousand-term disjunction.
     */
    private const FUZZY_MAX_EXPANSIONS = 30;

    /**
     * Whether to ask Elasticsearch to explain each hit. Off by default because
     * an explanation is several times the size of the hit itself; the UI flips
     * it on when someone opens the "why this result" panel.
     */
    public bool $explain = false;

    public function __construct(
        private readonly Client $client,
        private readonly string $index,
    ) {
        if (preg_match('/^[a-z0-9_\-]+$/', $this->index) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Invalid Elasticsearch index name: "%s"', $this->index),
            );
        }
    }

    public function name(): string
    {
        return self::ENGINE;
    }

    public function suggest(SuggestQuery $query): SuggestResult
    {
        if ($query->isEmpty()) {
            // No round trip: an empty box is not a query, and charging the engine
            // for it would flatter whichever side has the cheaper no-op.
            return new SuggestResult(self::ENGINE, [], 0.0, 0, ['skipped' => 'empty query']);
        }

        $body = $this->buildBody($query, fuzzyFallback: false);

        $started = hrtime(true);
        $response = $this->client->search(['index' => $this->index, 'body' => $body])->asArray();
        $elapsedMs = (hrtime(true) - $started) / 1e6;

        $engineTookMs = (int) ($response['took'] ?? 0);
        $passes = 1;
        $usedFallback = false;

        // The strict gate is unforgiving by design, which means a single typo
        // produces zero results rather than a slightly worse list. Adding the
        // fuzzy clause as a `should` in the first pass cannot fix that - a
        // `should` can only reorder documents the `must` already admitted - so
        // recovery has to be a second query where fuzziness *is* the gate.
        // Paying for it only on an empty result set keeps the common path at one
        // round trip.
        if ($query->fuzzy && $this->hitCount($response) === 0) {
            $body = $this->buildBody($query, fuzzyFallback: true);

            $started = hrtime(true);
            $response = $this->client->search(['index' => $this->index, 'body' => $body])->asArray();
            $elapsedMs += (hrtime(true) - $started) / 1e6;

            $engineTookMs += (int) ($response['took'] ?? 0);
            ++$passes;
            $usedFallback = true;
        }

        $suggestions = $this->toSuggestions($response);

        return new SuggestResult(
            engine: self::ENGINE,
            suggestions: $suggestions,
            tookMs: $elapsedMs,
            total: (int) ($response['hits']['total']['value'] ?? count($suggestions)),
            debug: [
                'index' => $this->index,
                'query' => $body,
                // Two clocks on purpose. `es_took_ms` is what Elasticsearch spent
                // inside the search phase; `took_ms` is what the PHP process waited
                // for. The gap is HTTP, JSON (de)serialisation and PSR-18 overhead,
                // and for a 10-row autocomplete it is frequently larger than the
                // search itself - which is exactly the kind of thing a "which engine
                // is faster" comparison has to show rather than hide.
                'es_took_ms' => $engineTookMs,
                'client_took_ms' => round($elapsedMs, 3),
                'overhead_ms' => round($elapsedMs - $engineTookMs, 3),
                'passes' => $passes,
                'fuzzy_fallback' => $usedFallback,
                'total_relation' => (string) ($response['hits']['total']['relation'] ?? 'eq'),
                'track_total_hits' => self::TRACK_TOTAL_HITS,
                'max_score' => $response['hits']['max_score'] ?? null,
                'timed_out' => (bool) ($response['timed_out'] ?? false),
            ],
        );
    }

    /**
     * @return array{ok: bool, detail: string, documents: int}
     */
    public function health(): array
    {
        try {
            $health = $this->client->cluster()
                ->health(['index' => $this->index, 'timeout' => '2s'])
                ->asArray();
        } catch (ClientResponseException $e) {
            return [
                'ok' => false,
                'detail' => $e->getCode() === 404
                    ? sprintf('index "%s" does not exist - run the import', $this->index)
                    : sprintf('client error: %s', $this->brief($e->getMessage())),
                'documents' => 0,
            ];
        } catch (NoNodeAvailableException | ServerResponseException $e) {
            return [
                'ok' => false,
                'detail' => sprintf('cluster unreachable: %s', $this->brief($e->getMessage())),
                'documents' => 0,
            ];
        }

        $status = (string) ($health['status'] ?? 'unknown');
        $documents = $this->documentCount();

        return [
            'ok' => $status !== 'red' && $documents > 0,
            'detail' => sprintf(
                'cluster %s, index "%s", %d document(s)',
                $status,
                $this->index,
                $documents,
            ),
            'documents' => $documents,
        ];
    }

    /**
     * The search request body.
     *
     * @return array<string, mixed>
     */
    private function buildBody(SuggestQuery $query, bool $fuzzyFallback): array
    {
        $text = $query->normalized();

        return array_filter([
            'size' => max(1, $query->limit),
            'track_total_hits' => self::TRACK_TOTAL_HITS,
            '_source' => self::SOURCE_FIELDS,
            'explain' => $this->explain ? true : null,
            // The hard grouping from SuggestionType::rankTier(), which the
            // function_score below cannot express: scores here are unbounded
            // (a good name match reaches four figures), so no weight is large
            // enough to guarantee one type always outranks another. Sorting on
            // an indexed byte does guarantee it, and keeping _score as the
            // secondary key leaves the ranking inside a tier exactly as it was.
            // _score being part of the sort is also what keeps it populated on
            // every hit; only hits.max_score goes null under a field sort.
            'sort' => [
                // unmapped_type matters: sorting on a field an index does not
                // have is a 400, so without it every query against an index
                // built before rank_tier existed fails outright instead of
                // simply falling back to score order. A mapping addition should
                // cost a re-import, not an outage.
                ['rank_tier' => ['order' => 'asc', 'unmapped_type' => 'byte']],
                ['_score' => 'desc'],
            ],
            'query' => [
                'function_score' => [
                    'query' => [
                        'bool' => [
                            'filter' => [
                                ['terms' => ['doc_type' => $query->typeValues()]],
                            ],
                            'must' => $this->gateClauses($query, $fuzzyFallback),
                            'should' => $this->precisionClauses($query, $text, $fuzzyFallback),
                            // Explicit: `should` is pure ranking here. With a `must`
                            // present ES defaults to this anyway, but leaving it
                            // implicit is how someone later "simplifies" the must
                            // away and silently turns the gate into a suggestion.
                            'minimum_should_match' => 0,
                        ],
                    ],
                    'functions' => $this->scoringFunctions(),
                    'score_mode' => 'multiply',
                    'boost_mode' => 'multiply',
                    // Hard ceiling on the combined function value so no amount of
                    // popularity can overrun a genuinely better textual match.
                    'max_boost' => 15.0,
                ],
            ],
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * The recall gate: every token must be present somewhere in the document.
     *
     * Type-ahead splits the query in two. The last token is whatever the user is
     * halfway through typing, so it matches as a prefix, against the n-grammed
     * search_text. Every token before it is a word they finished, so it has to
     * match a whole word, against search_text.folded.
     *
     * Only the *locative* tokens reach the gate at all. A house number or a box
     * reference ("kerkstraat 12 bus 5") describes a level of detail no street
     * document carries, so requiring it returned nothing for a completely
     * ordinary way of writing an address - see SuggestQuery::locativeTokens().
     * Those tokens are not thrown away; precisionClauses() still ranks on them,
     * which is what puts number 59 at the top of an address-level index.
     *
     * Running the finished tokens against the n-grammed field too - which is
     * what this used to do - quietly widened recall: in "gent kort" the complete
     * word "gent" also matched Gentbrugge and Gentse, because both contain an
     * indexed n-gram "gent". The gate is the one place in this query where
     * nothing downstream can undo a mistake, so it is worth being exact about.
     *
     * The MySQL side issues `+gent* +kort*` and therefore keeps the older, wider
     * semantics. That asymmetry is deliberate and is noted in the README: this
     * is a precision fix on the engine the POC recommends, not a change to the
     * measurement.
     *
     * @return list<array<string, mixed>>
     */
    private function gateClauses(SuggestQuery $query, bool $fuzzyFallback): array
    {
        // The fallback pass makes fuzziness itself the gate, and edit distance
        // over whole words is the whole point of it, so the prefix/whole-word
        // split does not apply here: one fuzzy clause over the lot.
        //
        // The locative/detail split does still apply, and for the same reason it
        // applies below. `operator => and` means every term in the text is
        // required, so feeding the complete query back in re-imposed the house
        // number this method spends the rest of its body keeping out - and it
        // did so in the one pass that only ever runs because the strict gate
        // already came back empty. "kerkstraat 12 9000 gent" found nothing while
        // "kerkstraat 9000 gent" found three.
        if ($fuzzyFallback) {
            $locative = implode(' ', $query->locativeTokens());

            return [['match' => ['search_text' => $this->fuzzyOptions($locative, 'and', 1.0)]]];
        }

        $clauses = [];
        $wholeWords = $query->locativeTokens();
        $prefix = null;

        // The token still being typed is only a prefix while it is still part of
        // the place name. In "goorbaan 5" the user has finished with the street
        // and moved on to the number, so "goorbaan" is gated as a whole word.
        if ($query->isTypingLocative()) {
            $prefix = array_pop($wholeWords);
        }

        if ($wholeWords !== []) {
            $clauses[] = [
                'match' => [
                    'search_text.folded' => [
                        'query' => implode(' ', $wholeWords),
                        'operator' => 'and',
                    ],
                ],
            ];
        }

        if ($prefix !== null) {
            $clauses[] = [
                'match' => [
                    'search_text' => [
                        'query' => $prefix,
                        'operator' => 'and',
                    ],
                ],
            ];
        }

        return $clauses;
    }

    /**
     * Ranking signals. None of these can add a document to the result set.
     *
     * @return list<array<string, mixed>>
     */
    private function precisionClauses(SuggestQuery $query, string $text, bool $fuzzyFallback): array
    {
        $clauses = [];

        // The whole name, typed out. A term lookup, so it only fires on an exact
        // match of the complete folded value - primary_name.keyword carries the
        // folding normaliser precisely so that this comparison can succeed.
        $clauses[] = [
            'term' => [
                'primary_name.keyword' => [
                    'value' => $text,
                    'boost' => self::BOOST_NAME_EXACT,
                ],
            ],
        ];

        // Strongest signal by a wide margin: the name reads as a continuation of
        // what was typed ("korte linden" -> "Korte Lindenstraat"). Run against
        // primary_name.folded, not the n-grammed parent, because a phrase-prefix
        // query needs real word terms at real positions - against an n-gram index
        // its trailing prefix expansion would be both redundant and expensive.
        $clauses[] = [
            'match_phrase_prefix' => [
                'primary_name.folded' => [
                    'query' => $text,
                    'max_expansions' => 20,
                    'boost' => self::BOOST_NAME_PHRASE_PREFIX,
                ],
            ],
        ];

        // Weaker, order-independent version of the same idea, on the n-grammed
        // field: it rewards a document for each typed word that prefixes a word
        // of its name, which is what carries "gent kort" (where the phrase-prefix
        // clause cannot fire because the city comes first).
        $clauses[] = [
            'match' => [
                'primary_name' => [
                    'query' => $text,
                    'operator' => 'or',
                    'boost' => self::BOOST_NAME_MATCH,
                ],
            ],
        ];

        foreach ($this->postcodeClauses($query) as $clause) {
            $clauses[] = $clause;
        }

        // Low boosts: matching the city is what nearly every candidate in a
        // multi-token query already does, so it separates almost nothing. Its job
        // is to break ties in favour of the document whose *own* municipality was
        // named rather than one that only mentions it through an alias.
        $clauses[] = [
            'match' => [
                'municipality_name' => [
                    'query' => $text,
                    'operator' => 'or',
                    'boost' => self::BOOST_MUNICIPALITY,
                ],
            ],
        ];
        $clauses[] = [
            'match' => [
                'post_name' => [
                    'query' => $text,
                    'operator' => 'or',
                    'boost' => self::BOOST_POST_NAME,
                ],
            ],
        ];

        foreach ($this->addressDetailClauses($query) as $clause) {
            $clauses[] = $clause;
        }

        // Alternative names. Without this the field is indexed and never read:
        // "Gand" or "Anvers" would pass the gate through search_text and then be
        // ranked on popularity alone, because no precision clause could see why
        // the document matched.
        $clauses[] = [
            'match' => [
                'aliases' => [
                    'query' => $text,
                    'operator' => 'or',
                    'boost' => self::BOOST_ALIAS,
                ],
            ],
        ];

        // In the fallback pass fuzziness is already the gate, so repeating it
        // here would just double-count the same evidence.
        if ($query->fuzzy && !$fuzzyFallback) {
            // Honest note: because the gate above is strict, this clause can only
            // ever re-rank documents that already matched exactly. It is kept
            // because it is nearly free on an already-small candidate set and it
            // makes the scoring identical in shape across both passes - the real
            // recall work happens in the fallback query.
            //
            // No manual short-token guard: fuzziness AUTO is already
            // length-aware (0 edits under 3 characters, 1 up to 5, 2 above), and
            // prefix_length 1 pins the first character, which both keeps the term
            // expansion cheap and reflects that people rarely mistype the letter
            // they just started a word with.
            $clauses[] = ['match' => ['search_text' => $this->fuzzyOptions($text, 'or', self::BOOST_FUZZY)]];
        }

        return $clauses;
    }

    /**
     * House numbers and box references: ranking only, never recall.
     *
     * At street level these never match - a street document holds no house
     * number - and that is fine, because at street level they are not what
     * distinguishes one answer from another. At `--level=address` they are: the
     * whole point of typing "goorbaan 59" is that 59 comes first out of the two
     * hundred addresses on the street.
     *
     * Matched against search_text.folded rather than the n-grammed parent so
     * that "59" means 59 and not also 590 and 591, and rather than against the
     * house_number field, which is `index: false` and holds the raw register
     * value ("12A") against folded query tokens.
     *
     * @return list<array<string, mixed>>
     */
    private function addressDetailClauses(SuggestQuery $query): array
    {
        $clauses = [];

        foreach ($query->detailTokens() as $token) {
            // The marker word itself is not in any haystack - SuggestionDocument
            // never puts box_number into search_text - so a clause on it could
            // only ever score zero.
            if (Normalizer::isBoxMarkerToken($token) || $token === 'b') {
                continue;
            }

            $clauses[] = [
                'match' => [
                    'search_text.folded' => [
                        'query' => $token,
                        'boost' => self::BOOST_ADDRESS_DETAIL,
                    ],
                ],
            ];
        }

        return $clauses;
    }

    /**
     * Postcode handling, token by token.
     *
     * Note there is deliberately no clause on house_number: that field is an
     * unfolded keyword holding the raw register value ("12A"), while query tokens
     * arrive lowercased from Normalizer, so a term lookup would miss exactly the
     * numbers that have a letter suffix. House numbers reach the query through
     * search_text, which is folded on both sides.
     *
     * @return list<array<string, mixed>>
     */
    private function postcodeClauses(SuggestQuery $query): array
    {
        $clauses = [];
        $lastToken = $query->lastToken();
        $seen = [];

        foreach ($query->tokens as $token) {
            // A repeated token would otherwise contribute its boost twice.
            if (isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;

            if (Normalizer::isPostcodeToken($token)) {
                $clauses[] = [
                    'term' => [
                        'postcode' => ['value' => $token, 'boost' => self::BOOST_POSTCODE_EXACT],
                    ],
                ];

                continue;
            }

            // A short numeric token is ambiguous - "22" is as likely a house
            // number as the start of "2230". Only the token still being typed
            // gets the postcode reading, because a *finished* short number in the
            // middle of a query is almost always a house number.
            if ($token === $lastToken && preg_match('/^[1-9][0-9]{0,2}$/', $token) === 1) {
                $clauses[] = [
                    // Against postcode.text, whose edge n-grams turn this into a
                    // single term lookup instead of a prefix scan.
                    'match' => [
                        'postcode.text' => ['query' => $token, 'boost' => self::BOOST_POSTCODE_PARTIAL],
                    ],
                ];
            }
        }

        return $clauses;
    }

    /**
     * Popularity prior and per-type prior, both multiplicative.
     *
     * popularity is the number of addresses behind a document, which spans four
     * orders of magnitude (a cul-de-sac with 4 houses against Antwerpen with a
     * quarter of a million). log1p flattens that into roughly 0.4 - 12, and the
     * factor is what tunes how flat: counter-intuitively a *larger* factor damps
     * harder, because under a logarithm the ratio between two values shrinks as
     * both are scaled up. At 0.5 a large city ends up worth about 1.8x a village
     * rather than 100x, which is the intent - popularity should break ties, not
     * decide the ranking.
     *
     * The per-type weights fix a structural problem: a municipality document
     * competes against the 3000 street documents inside it, and every one of
     * those streets also contains the city name, so on text alone the city can
     * lose to its own streets. Nudging municipality and postcode documents up
     * (and individual house numbers down) makes a bare "aalst" answer with the
     * town. Weights are small on purpose - they are a prior, and the gate plus
     * the name boosts still win whenever the user actually typed a street.
     *
     * Place sits between postcode and street: someone who types a venue name
     * means the venue, and a place is a more specific answer than the street it
     * stands on, so it outranks both street and address documents. It stays
     * below municipality and postcode because a bare "gent" is still a question
     * about the city, not about the 56 places called Gent.
     *
     * @return list<array<string, mixed>>
     */
    private function scoringFunctions(): array
    {
        return [
            [
                'field_value_factor' => [
                    'field' => 'popularity',
                    'modifier' => 'log1p',
                    'factor' => 0.5,
                    'missing' => 1,
                ],
            ],
            ['filter' => ['term' => ['doc_type' => SuggestionType::Municipality->value]], 'weight' => 1.6],
            ['filter' => ['term' => ['doc_type' => SuggestionType::Postcode->value]], 'weight' => 1.35],
            ['filter' => ['term' => ['doc_type' => SuggestionType::Place->value]], 'weight' => 1.2],
            ['filter' => ['term' => ['doc_type' => SuggestionType::Street->value]], 'weight' => 1.0],
            ['filter' => ['term' => ['doc_type' => SuggestionType::Address->value]], 'weight' => 0.75],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fuzzyOptions(string $text, string $operator, float $boost): array
    {
        return [
            'query' => $text,
            'operator' => $operator,
            'fuzziness' => 'AUTO',
            'prefix_length' => 1,
            'max_expansions' => self::FUZZY_MAX_EXPANSIONS,
            // "antwrepen" is one transposition from "antwerpen" but two plain
            // edits, so this is the difference between the fallback working and
            // not working for the most common class of typo.
            'fuzzy_transpositions' => true,
            'boost' => $boost,
        ];
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return list<Suggestion>
     */
    private function toSuggestions(array $response): array
    {
        $hits = $response['hits']['hits'] ?? [];
        $suggestions = [];

        foreach (is_array($hits) ? $hits : [] as $hit) {
            $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
            $score = (float) ($hit['_score'] ?? 0.0);

            $debug = [
                '_id' => (string) ($hit['_id'] ?? ''),
                '_score' => $score,
            ];

            if (isset($hit['_explanation'])) {
                $debug['_explanation'] = $hit['_explanation'];
            }

            $suggestions[] = new Suggestion(
                id: (string) ($source['id'] ?? $hit['_id'] ?? ''),
                // from() and not tryFrom(): a doc_type the application does not
                // know means the index was built by a different version of the
                // code, and silently dropping those hits would show up as a
                // mysterious recall gap in the comparison instead of an error.
                type: SuggestionType::from((string) ($source['doc_type'] ?? '')),
                label: (string) ($source['label'] ?? ''),
                placeName: $this->nullableString($source['place_name'] ?? null),
                streetName: $this->nullableString($source['street_name'] ?? null),
                houseNumber: $this->nullableString($source['house_number'] ?? null),
                postcode: $this->nullableString($source['postcode'] ?? null),
                postName: $this->nullableString($source['post_name'] ?? null),
                municipalityName: $this->nullableString($source['municipality_name'] ?? null),
                nisCode: $this->nullableString($source['nis_code'] ?? null),
                lat: isset($source['location']['lat']) ? (float) $source['location']['lat'] : null,
                lon: isset($source['location']['lon']) ? (float) $source['location']['lon'] : null,
                score: $score,
                popularity: (int) ($source['popularity'] ?? 0),
                debug: $debug,
            );
        }

        return $suggestions;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function hitCount(array $response): int
    {
        $hits = $response['hits']['hits'] ?? [];

        return is_array($hits) ? count($hits) : 0;
    }

    private function documentCount(): int
    {
        try {
            return (int) ($this->client->count(['index' => $this->index])->asArray()['count'] ?? 0);
        } catch (ClientResponseException | ServerResponseException | NoNodeAvailableException) {
            return 0;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Elasticsearch error messages carry the whole response body; health() feeds
     * a status line, not a debugger.
     */
    private function brief(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return mb_strlen($message) > 200 ? mb_substr($message, 0, 200) . '...' : $message;
    }
}
