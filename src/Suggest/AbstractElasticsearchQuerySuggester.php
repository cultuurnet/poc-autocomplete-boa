<?php

declare(strict_types=1);

namespace App\Suggest;

use App\Model\SuggestQuery;
use App\Model\SuggestResult;
use App\Support\Normalizer;

/**
 * The four query-DSL autocomplete methods, minus the bit that differs.
 *
 * All four solve the same problem the same way: the user types one string that
 * is an arbitrary mix of street, postcode and municipality ("gent kort",
 * "2230 goorb", "korte linden aalst"), in any order, with the last word usually
 * half finished. There is no field to parse the query into, so the query is
 * built as
 *
 *   recall gate (every token must appear somewhere)
 *     x precision signals (where did the tokens appear, and how)
 *     x a damped popularity prior
 *
 * with the three stages kept separate on purpose: the gate decides *whether* a
 * document can appear at all, and nothing below it can smuggle a document back
 * in. That makes the ranking safe to tune - a boost can reorder results but can
 * never widen them.
 *
 * What a subclass may change, and nothing else:
 *
 *   gateClauses()   which field and which query type carries the recall gate
 *   nameClauses()   how the primary name is prefix-matched
 *   fuzzyField()    which field the fuzzy fallback runs against
 *
 * Everything else - the doc_type filter, size, track_total_hits, _source,
 * explain, the function_score wrapper, the postcode/municipality/post_name
 * signals and every boost constant below - is held constant across the four
 * methods, because a method that ranks worse than the incumbent is a *finding*
 * and hand-tuning it away destroys the measurement. Resist the urge. If a
 * method needs a different boost to look good, write that down; do not change
 * the number.
 *
 * The auxiliary signals (postcode, municipality, post_name) are held constant
 * too even though they are text clauses and the brief would allow them to
 * vary. They run against fields that none of the five index-time strategies
 * touch, so varying them would leak a second variable into a one-variable
 * experiment for no gain.
 */
abstract class AbstractElasticsearchQuerySuggester extends AbstractElasticsearchSuggester
{
    // --- Scoring weights -----------------------------------------------------
    // Deliberately a small, flat set of constants rather than tuned magic
    // numbers inline: the whole point of the POC is being able to explain why a
    // result is where it is, and to move one number at a time.

    /** The name literally starts with what has been typed. Nothing beats this. */
    /**
     * The name is *exactly* what has been typed, not merely started by it.
     * Above BOOST_NAME_PHRASE_PREFIX because a finished word is a much stronger
     * statement of intent than a prefix: once "goorbaan" is fully typed, the
     * street called Goorbaan should outrank every Goorbaanstraat. The two
     * clauses stack - an exact hit is also a prefix hit.
     *
     * Shared rather than per-method on purpose: it reads primary_name.keyword,
     * which every method sees identically, so it cannot be what makes one
     * method look better than another.
     */
    protected const BOOST_NAME_EXACT = 12.0;

    protected const BOOST_NAME_PHRASE_PREFIX = 8.0;

    /** Some of the typed words prefix-match words of the name, in any order. */
    protected const BOOST_NAME_MATCH = 3.0;

    /** A complete 4-digit postcode was typed and this document carries it. */
    protected const BOOST_POSTCODE_EXACT = 6.0;

    /** A 1-3 digit last token that is plausibly a postcode being typed. */
    protected const BOOST_POSTCODE_PARTIAL = 3.0;

    /**
     * A house number or box number the document actually carries. Only ever
     * earned at address level; below the postcode and name boosts because
     * getting the street right matters more than getting the number right.
     */
    protected const BOOST_ADDRESS_DETAIL = 4.0;

    protected const BOOST_MUNICIPALITY = 1.5;

    /**
     * An alternative name for this document: the FR/DE translation, or the
     * sub-locality a postcode covers. Deliberately below BOOST_NAME_MATCH and
     * not "scored like a name hit": DocumentSource also files the municipality
     * name under aliases, so a clause weighted like the real name would count
     * the city twice for every document in the index.
     */
    protected const BOOST_ALIAS = 1.8;

    protected const BOOST_POST_NAME = 1.2;

    /** Low: fuzzy is a safety net, never an argument for promoting a document. */
    protected const BOOST_FUZZY = 0.4;

    /**
     * Bounds the term expansion of the fuzzy clause. Left generous enough to
     * find the intended word, tight enough that a typo cannot turn one keystroke
     * into a thousand-term disjunction.
     */
    protected const FUZZY_MAX_EXPANSIONS = 30;

    /**
     * How many terms match_phrase_prefix may expand its trailing term into.
     * Shared so the ordered-name signal costs every method the same.
     */
    protected const NAME_MAX_EXPANSIONS = 20;

    final public function suggest(SuggestQuery $query): SuggestResult
    {
        $engine = $this->name();

        if ($query->isEmpty()) {
            // No round trip: an empty box is not a query, and charging the engine
            // for it would flatter whichever side has the cheaper no-op.
            return new SuggestResult($engine, [], 0.0, 0, ['skipped' => 'empty query']);
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
            engine: $engine,
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
     * The recall gate: every token must be present somewhere in the document.
     *
     * This is the direct equivalent of the MySQL side's `+2230* +goorb*` boolean
     * query, which is what makes the result sets comparable at all. How the
     * trailing half-typed token is turned into a prefix - an edge-n-gram term
     * lookup, an index_prefixes term lookup, a shingled search_as_you_type term
     * lookup, or an honest prefix scan - is the entire subject of the
     * comparison, and it is the one thing a subclass decides.
     *
     * One asymmetry to expect in the numbers, and not to "fix": the incumbent's
     * edge-n-gram index makes *every* token a prefix, because every token was
     * expanded at index time and the query side never knows which one the user
     * is still typing. match_bool_prefix and bool_prefix make only the *last*
     * token a prefix, which is the correct type-ahead reading but a narrower
     * one. So "korte linden" matches 5 documents through the n-gram gate and 3
     * through the other three gates on the real index - the two extra are
     * documents where "korte" is itself a prefix of a longer word. Higher
     * recall, lower precision; which of those is better is a product question
     * the benchmark is meant to inform, not a defect in either gate.
     *
     * @param list<string> $wholeWords the tokens the user has finished typing
     * @param string|null  $prefix     the token still being typed, if it is
     *                                 still part of the place name
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function gateClauses(array $wholeWords, ?string $prefix): array;

    /**
     * The gate, decomposed once here so every method is asked the same question.
     *
     * Type-ahead splits a query in two: the last token is whatever the user is
     * halfway through typing and matches as a prefix, every token before it is
     * a word they finished and has to match whole. That split is computed here
     * rather than in each subclass, because if two methods disagreed about
     * *which* token is the prefix they would no longer be answering the same
     * question and the comparison would be measuring the disagreement.
     *
     * Only the locative tokens reach the gate. A house number or a box
     * reference ("kerkstraat 12 bus 5") describes a level of detail no street
     * document carries, so requiring it finds nothing at all for a completely
     * ordinary way of writing an address - see SuggestQuery::locativeTokens().
     * Those tokens are not discarded; addressDetailClauses() still ranks on
     * them, which is what puts number 59 at the top of an address-level index.
     *
     * And the trailing token stops being a prefix once the user has moved past
     * the name: in "goorbaan 5" the street is finished, so "goorbaan" is gated
     * as a whole word and $prefix is null.
     *
     * @return list<array<string, mixed>>
     */
    final protected function gateMust(SuggestQuery $query, bool $fuzzyFallback): array
    {
        // The fallback pass makes fuzziness itself the gate, and edit distance
        // over whole words is the whole point of it, so the split does not
        // apply - but the locative filter still does, or a house number would
        // re-gate the very query the fallback exists to rescue.
        if ($fuzzyFallback) {
            return [$this->fuzzyGateClause(implode(' ', $query->locativeTokens()))];
        }

        $wholeWords = $query->locativeTokens();
        $prefix = $query->isTypingLocative() ? array_pop($wholeWords) : null;

        return $this->gateClauses($wholeWords, $prefix);
    }

    /**
     * How this method prefix-matches the primary name, as ranking signals only.
     *
     * Every implementation returns exactly two clauses at the two shared name
     * boosts: an order-sensitive "the name continues what was typed" clause at
     * BOOST_NAME_PHRASE_PREFIX, and an order-independent "some typed words
     * prefix words of the name" clause at BOOST_NAME_MATCH. Same two signals,
     * same two weights, different machinery.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function nameClauses(string $text): array;

    /**
     * The whole-word field this method's fuzzy pass runs against.
     *
     * Always the method's own gate field, so `?fuzzy=1` changes exactly one
     * thing per method and nothing else.
     */
    abstract protected function fuzzyField(): string;

    /**
     * The search request body. Identical for all four methods but for the
     * clauses the subclass supplies.
     *
     * @return array<string, mixed>
     */
    final protected function buildBody(SuggestQuery $query, bool $fuzzyFallback): array
    {
        $text = $query->normalized();

        return array_filter([
            'size' => max(1, $query->limit),
            'track_total_hits' => self::TRACK_TOTAL_HITS,
            '_source' => self::SOURCE_FIELDS,
            'explain' => $this->explain ? true : null,
            'query' => [
                'function_score' => [
                    'query' => [
                        'bool' => [
                            'filter' => $this->docTypeFilter($query->typeValues()),
                            'must' => $this->gateMust($query, $fuzzyFallback),
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
     * Ranking signals. None of these can add a document to the result set.
     *
     * Order matters only for readability of the debug panel: name signals
     * first, then the address-component signals, then the fuzzy re-rank.
     *
     * @return list<array<string, mixed>>
     */
    final protected function precisionClauses(SuggestQuery $query, string $text, bool $fuzzyFallback): array
    {
        // The whole name, typed out. A term lookup, so it only fires on an
        // exact match of the complete folded value - primary_name.keyword
        // carries the folding normaliser precisely so that this comparison can
        // succeed. Field-identical across methods, so it ranks every method's
        // candidates the same way.
        $clauses = [
            [
                'term' => [
                    'primary_name.keyword' => [
                        'value' => $text,
                        'boost' => self::BOOST_NAME_EXACT,
                    ],
                ],
            ],
        ];

        foreach ($this->nameClauses($text) as $clause) {
            $clauses[] = $clause;
        }

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

        // Alternative names: the FR/DE translation, or the sub-locality a
        // postcode covers. Run against the plain aliases field, which every
        // method shares, for the same reason as the exact-name clause above.
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
            $clauses[] = ['match' => [$this->fuzzyField() => $this->fuzzyOptions($text, 'or', self::BOOST_FUZZY)]];
        }

        return $clauses;
    }

    /**
     * Rank on the house number and box reference the gate deliberately ignores.
     *
     * This is the other half of the locative/detail split in gateMust(): the
     * detail tokens cannot narrow a search over streets and municipalities, but
     * at address level they are exactly what distinguishes number 59 from
     * number 61, so they belong in the ranking even though they cannot be
     * required.
     *
     * On search_text.folded rather than on any method's own gate field: house
     * numbers are short and numeric, and prefix-matching them would let "5"
     * score 59, 5A and 512 alike. It is also the field every method shares,
     * which keeps this signal out of the comparison.
     *
     * @return list<array<string, mixed>>
     */
    final protected function addressDetailClauses(SuggestQuery $query): array
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
     * The fuzzy fallback gate, shared verbatim by all four methods.
     *
     * A plain `match` and not each method's own prefix query, and that is a
     * measured decision rather than a shortcut: Elasticsearch does not apply
     * `fuzziness` to the trailing prefix term of a `match_bool_prefix` (nor of
     * a `bool_prefix` multi_match), so "antwrepen" - a single transposition,
     * and the single most common class of typo - returns nothing from those
     * query types no matter how the fuzziness is configured. Verified against
     * the cluster, not assumed. A `match` treats every token as a whole word
     * and does recover it.
     *
     * The cost is real and worth stating: on the three whole-word methods the
     * fallback only fires once the trailing token is a near-complete word,
     * whereas the incumbent's fallback runs against an edge-n-gram index and so
     * still recovers a typo in a half-typed token. That asymmetry is a property
     * of the index-time strategies being compared, not a bug in this class.
     *
     * @return array<string, mixed>
     */
    final protected function fuzzyGateClause(string $text): array
    {
        return ['match' => [$this->fuzzyField() => $this->fuzzyOptions($text, 'and', 1.0)]];
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
    final protected function postcodeClauses(SuggestQuery $query): array
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
     * @return array<string, mixed>
     */
    final protected function fuzzyOptions(string $text, string $operator, float $boost): array
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
}
