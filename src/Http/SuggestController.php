<?php

declare(strict_types=1);

namespace App\Http;

use App\Container;
use App\Model\SuggestionType;
use App\Model\SuggestQuery;
use App\Model\SuggestResult;
use App\Suggest\ElasticsearchSuggester;
use App\Suggest\SuggesterInterface;
use Throwable;

/**
 * Turns query-string parameters into one SuggestQuery and runs it past every
 * requested method, returning plain arrays. Nothing here touches the
 * superglobals or writes output: the front controller reads the request once
 * at the edge and JsonResponse does the writing, which keeps this class
 * trivially callable from a test or a CLI harness.
 *
 * The comparison only means something if every method gets a byte-identical
 * request, so the query is parsed once and the same SuggestQuery instance goes
 * to all of them.
 *
 * Which methods exist is not decided here: Container is the registry, and this
 * class only validates ?engine= against it. A hardcoded list in a second place
 * is how the UI ends up offering a method the API rejects.
 */
final class SuggestController
{
    private const MIN_LIMIT = 1;
    private const MAX_LIMIT = 50;
    private const DEFAULT_LIMIT = 10;

    /**
     * Longer than any real address query. The cap exists so a pasted essay
     * cannot turn into an expensive fulltext query on either engine.
     */
    private const MAX_QUERY_LENGTH = 120;

    /** Engine exception messages can carry an entire ES response body. */
    private const MAX_ERROR_LENGTH = 400;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * GET /api/suggest
     *
     * @param array<string, mixed> $params raw query string parameters
     *
     * @return array<string, mixed>
     */
    public function suggest(array $params): array
    {
        $query = new SuggestQuery(
            self::rawQuery($params['q'] ?? null),
            self::limit($params['limit'] ?? null),
            self::types($params['types'] ?? null),
            self::fuzzy($params['fuzzy'] ?? null),
            $this->container->config->houseNumbersIndexed,
        );

        $engines = $this->engines($params['engine'] ?? null);
        $explain = self::flag($params['explain'] ?? null, default: false);

        // The engines run one after the other -- PHP has no threads here -- but
        // that does not distort the comparison: each suggester measures itself
        // with hrtime around its own call, so took_ms is wall time spent in
        // that engine alone and is unaffected by what ran before it. The total
        // below is the only number that includes all of them plus PHP overhead.
        // It does mean ?engine=all is six sequential round trips, which is why
        // the UI asks for one method per request instead of batching them.
        $startedAt = hrtime(true);

        $results = [];

        foreach ($engines as $engine) {
            $results[$engine] = $this->runEngine($engine, $query, $explain);
        }

        return [
            'query' => $query->raw,
            'normalized' => $query->normalized(),
            'limit' => $query->limit,
            'types' => $query->typeValues(),
            'fuzzy' => $query->fuzzy,
            'explain' => $explain,
            'engines' => $results,
            'total_ms' => self::elapsedMs($startedAt),
        ];
    }

    /**
     * GET /api/health
     *
     * Reports *backends*, not methods. The five Elasticsearch methods share one
     * client and one index, so checking all five would be five round trips that
     * can only ever answer the same question -- is the cluster up and does the
     * index hold documents -- while making the health strip five times longer
     * for no extra information. The per-method truth that a backend check
     * cannot give you (is this method's field actually mapped?) shows up the
     * moment you query the method, as that column's error string.
     *
     * The 'engines' key therefore keeps its original shape and its original two
     * entries: the health strip in app.js indexes straight into it, and the
     * point of the key is the backend health it has always reported.
     *
     * 'methods' is the registry, so the picker can be built from one request
     * that the UI already makes rather than from a hardcoded copy in app.js.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $engines = [];

        foreach ($this->container->backends() as $backend => $method) {
            try {
                $engines[$backend] = ['engine' => $backend] + $this->suggester($method)->health();
            } catch (Throwable $e) {
                $engines[$backend] = [
                    'engine' => $backend,
                    'ok' => false,
                    'detail' => self::errorMessage($e),
                    'documents' => 0,
                ];
            }
        }

        return [
            'engines' => $engines,
            'methods' => $this->container->methods(),
        ];
    }

    /**
     * One engine's slice of the response. A failing engine degrades to an empty
     * result set carrying an error string rather than taking the request down
     * with it -- with one store rebuilding or one container stopped, the other
     * column is exactly what you still want to look at.
     *
     * @return array<string, mixed>
     */
    private function runEngine(string $engine, SuggestQuery $query, bool $explain): array
    {
        $startedAt = hrtime(true);

        try {
            // An empty box is "no opinion", not "match everything". Bouncing a
            // wildcard off both stores every time someone backspaces to nothing
            // only adds noise to the latency history.
            if ($query->isEmpty()) {
                return (new SuggestResult($engine, [], 0.0))->toArray();
            }

            $suggester = $this->suggester($engine);

            // MySQL reports its ranking components unconditionally - they are a
            // handful of floats it has already computed. Elasticsearch has to be
            // asked, and the answer is several times the size of the hit, so it
            // stays off unless the caller says otherwise. Note this also makes
            // the request more expensive to serve: never set it while measuring.
            if ($explain && $suggester instanceof ElasticsearchSuggester) {
                $suggester->explain = true;
            }

            return $suggester->suggest($query)->toArray();
        } catch (Throwable $e) {
            // Same keys as SuggestResult::toArray() plus 'error', so the
            // frontend can render a failed column without a second code path.
            return [
                'engine' => $engine,
                'took_ms' => self::elapsedMs($startedAt),
                'total' => 0,
                'count' => 0,
                'suggestions' => [],
                'debug' => [],
                'error' => self::errorMessage($e),
            ];
        }
    }

    /**
     * One method, built on demand. Container::suggesters() hands back factories
     * rather than instances precisely so this call can happen inside the
     * per-engine try block above: a dead MySQL must not throw before the
     * Elasticsearch methods exist, and connecting is itself one of the failures
     * worth showing in a column rather than in a 500.
     */
    private function suggester(string $engine): SuggesterInterface
    {
        return $this->container->suggester($engine);
    }

    private static function rawQuery(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim($value), 0, self::MAX_QUERY_LENGTH);
    }

    private static function limit(mixed $value): int
    {
        if (!is_string($value) && !is_int($value)) {
            return self::DEFAULT_LIMIT;
        }

        $limit = (int) $value;

        if ($limit < self::MIN_LIMIT) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * @return list<SuggestionType> empty means "every type", which is what
     *                              SuggestQuery::typeValues() expands it to
     */
    private static function types(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $types = [];

        foreach (explode(',', $value) as $candidate) {
            $type = SuggestionType::tryFrom(strtolower(trim($candidate)));

            if ($type !== null && !in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    private static function fuzzy(mixed $value): bool
    {
        return self::flag($value, default: true);
    }

    /**
     * A query-string boolean. Absent means the default; anything falsy-looking
     * means off, everything else means on.
     */
    private static function flag(mixed $value, bool $default): bool
    {
        if (!is_string($value)) {
            return $default;
        }

        return !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * Accepts "all", one method key, or a comma separated subset. Anything
     * unrecognised falls back to the full registry rather than 400-ing: this
     * endpoint is driven by a URL people hand-edit while poking at the POC, and
     * a typo that shows you everything is friendlier than one that shows you an
     * error page.
     *
     * The intersection is taken registry-first, so the response always lists
     * the engines in the canonical order no matter what order the caller asked
     * for them in -- the UI renders columns straight from that order and should
     * not have them jump around between requests.
     *
     * @return list<string>
     */
    private function engines(mixed $value): array
    {
        $all = $this->container->methodKeys();

        if (!is_string($value) || trim($value) === '' || strtolower(trim($value)) === 'all') {
            return $all;
        }

        $requested = array_map(static fn (string $e): string => strtolower(trim($e)), explode(',', $value));
        $engines = array_values(array_intersect($all, $requested));

        return $engines === [] ? $all : $engines;
    }

    private static function elapsedMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }

    private static function errorMessage(Throwable $e): string
    {
        $message = $e->getMessage() === '' ? $e::class : $e::class . ': ' . $e->getMessage();

        return mb_substr($message, 0, self::MAX_ERROR_LENGTH);
    }
}
