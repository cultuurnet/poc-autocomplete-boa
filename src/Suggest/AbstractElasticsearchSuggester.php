<?php

declare(strict_types=1);

namespace App\Suggest;

use App\Model\Suggestion;
use App\Model\SuggestionType;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use InvalidArgumentException;

/**
 * Everything the Elasticsearch suggesters do *except* match text.
 *
 * The POC compares five retrieval methods against one index. That comparison is
 * only worth anything if the retrieval method is the sole variable, so every
 * part of the request that is *not* the text match - the document-type filter,
 * the page size, the total-hit accounting, the returned fields, the popularity
 * prior, the per-type weights - is defined exactly once, here, and inherited.
 * A reader who wants to check that the five methods really were asked the same
 * question reads this file, not five near-identical files.
 *
 * Abstract class rather than a trait, deliberately. A trait would copy this
 * code into five classes with nothing stopping one of them from quietly
 * overriding a weight or dropping the filter, and the measurement would go
 * wrong silently - the worst possible failure mode for a benchmark. An abstract
 * class states the relationship in the type system: the subclasses are the same
 * suggester with one method swapped out, and anything they change they have to
 * change by overriding something visible.
 *
 * What lives *here* versus in AbstractElasticsearchQuerySuggester: this class
 * holds the plumbing (connection, health, hit mapping) and the *definition* of
 * the held-constant parts; the query subclass assembles them into a search
 * body. The split exists because the completion suggester is not a search at
 * all - it has no query, no filter, no function_score - and inheriting a
 * buildBody() it can never call would be a lie about what it does.
 */
abstract class AbstractElasticsearchSuggester implements SuggesterInterface
{
    /**
     * Counting every one of the 4.2M documents that match "straat" on every
     * keystroke is pure waste: the UI shows ten rows and a "many" indicator, and
     * an exact total costs a full collection over the matching set. 1000 is
     * enough to tell "a handful" from "too many to list" and is where
     * Elasticsearch stops counting; the relation ("eq"/"gte") goes into debug so
     * the UI can say "1000+".
     */
    protected const TRACK_TOTAL_HITS = 1000;

    /**
     * Only what Suggestion needs. Fetching the n-grammed fields back would mean
     * shipping the whole search_text haystack for every hit for nothing.
     *
     * @var list<string>
     */
    protected const SOURCE_FIELDS = [
        'id',
        'doc_type',
        'label',
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

    /**
     * Whether to ask Elasticsearch to explain each hit. Off by default because
     * an explanation is several times the size of the hit itself; the UI flips
     * it on when someone opens the "why this result" panel.
     *
     * A public property and not a constructor argument, because the container
     * builds every suggester with the same two arguments and the panel is a
     * per-request decision made long after construction.
     */
    public bool $explain = false;

    public function __construct(
        protected readonly Client $client,
        protected readonly string $index,
    ) {
        if (preg_match('/^[a-z0-9_\-]+$/', $this->index) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Invalid Elasticsearch index name: "%s"', $this->index),
            );
        }
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
     * The recall filter, identical for every method.
     *
     * It is a `filter` and not a `must` because which document types the user
     * ticked is a yes/no question about the document, not evidence about how
     * well it matched; scoring it would let a type checkbox reorder results.
     *
     * @param list<string> $typeValues
     *
     * @return list<array<string, mixed>>
     */
    final protected function docTypeFilter(array $typeValues): array
    {
        return [
            ['terms' => ['doc_type' => $typeValues]],
        ];
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
     * final, and shared by every query-based method: the moment one method gets
     * its own popularity curve the latency and quality numbers stop being
     * comparable, and the temptation to "fix" a method that ranks badly by
     * nudging this is exactly what the comparison exists to resist.
     *
     * @return list<array<string, mixed>>
     */
    final protected function scoringFunctions(): array
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
            ['filter' => ['term' => ['doc_type' => SuggestionType::Street->value]], 'weight' => 1.0],
            ['filter' => ['term' => ['doc_type' => SuggestionType::Address->value]], 'weight' => 0.75],
        ];
    }

    /**
     * One Elasticsearch hit-shaped array to one Suggestion.
     *
     * Deliberately written against the three keys that a search hit and a
     * completion option have in common (`_id`, `_score`, `_source`) rather than
     * against the search response, so es-completion produces Suggestion objects
     * indistinguishable from the other four methods' and the UI never has to
     * know which method answered.
     *
     * @param array<string, mixed> $hit
     * @param array<string, mixed> $debugExtra method-specific scoring detail
     */
    final protected function toSuggestion(array $hit, array $debugExtra = []): Suggestion
    {
        $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : [];
        $score = (float) ($hit['_score'] ?? 0.0);

        $debug = [
            '_id' => (string) ($hit['_id'] ?? ''),
            '_score' => $score,
        ];

        if (isset($hit['_explanation'])) {
            $debug['_explanation'] = $hit['_explanation'];
        }

        foreach ($debugExtra as $key => $value) {
            $debug[$key] = $value;
        }

        return new Suggestion(
            id: (string) ($source['id'] ?? $hit['_id'] ?? ''),
            // from() and not tryFrom(): a doc_type the application does not
            // know means the index was built by a different version of the
            // code, and silently dropping those hits would show up as a
            // mysterious recall gap in the comparison instead of an error.
            type: SuggestionType::from((string) ($source['doc_type'] ?? '')),
            label: (string) ($source['label'] ?? ''),
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

    /**
     * @param array<string, mixed> $response
     *
     * @return list<Suggestion>
     */
    final protected function toSuggestions(array $response): array
    {
        $hits = $response['hits']['hits'] ?? [];
        $suggestions = [];

        foreach (is_array($hits) ? $hits : [] as $hit) {
            $suggestions[] = $this->toSuggestion(is_array($hit) ? $hit : []);
        }

        return $suggestions;
    }

    /**
     * @param array<string, mixed> $response
     */
    final protected function hitCount(array $response): int
    {
        $hits = $response['hits']['hits'] ?? [];

        return is_array($hits) ? count($hits) : 0;
    }

    final protected function documentCount(): int
    {
        try {
            return (int) ($this->client->count(['index' => $this->index])->asArray()['count'] ?? 0);
        } catch (ClientResponseException | ServerResponseException | NoNodeAvailableException) {
            return 0;
        }
    }

    final protected function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Elasticsearch error messages carry the whole response body; health() feeds
     * a status line, not a debugger.
     */
    final protected function brief(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return mb_strlen($message) > 200 ? mb_substr($message, 0, 200) . '...' : $message;
    }
}
