<?php

declare(strict_types=1);

namespace App;

use App\Import\ElasticsearchIndexer;
use App\Import\IndexerInterface;
use App\Import\MysqlIndexer;
use App\Suggest\ElasticsearchBoolPrefixSuggester;
use App\Suggest\ElasticsearchCompletionSuggester;
use App\Suggest\ElasticsearchPrefixesSuggester;
use App\Suggest\ElasticsearchSaytSuggester;
use App\Suggest\ElasticsearchSuggester;
use App\Suggest\MysqlSuggester;
use App\Suggest\SuggesterInterface;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use InvalidArgumentException;
use PDO;

/**
 * Hand-rolled service locator. A POC with a dozen services does not need a DI
 * container; it does need lazy construction, because the web request must not
 * fail when only one of the two backends is up.
 *
 * It is also the single registry of autocomplete *methods*. Six methods, two
 * backends: the five Elasticsearch methods differ only in which mapped field
 * and query type they use, and all five hit the same index on the same
 * connection. Keeping that list here rather than in each caller is what stops
 * the HTTP API, the benchmark and the health command from drifting apart on
 * which methods exist and what they are called; adding a method is one entry in
 * self::METHODS plus one factory in suggesters().
 */
final class Container
{
    /**
     * The registry, in the order everything renders it: MySQL first as the
     * thing we are trying to replace, then the incumbent edge n-gram index that
     * the other four are candidates against, then the four candidates.
     *
     * `backend` is the coarse grouping the *infrastructure* cares about (one
     * MySQL connection, one Elasticsearch connection) as opposed to the method
     * key, which is what the *query* cares about. Health checks and --engine=es
     * are both expressed in terms of it, so it lives next to the method rather
     * than being re-derived from a str_starts_with('es-') somewhere downstream.
     *
     * The labels and descriptions are shipped to the browser and rendered
     * verbatim in the method picker. They live here and not in app.js because a
     * method the front end offers but the API does not know is a 400 waiting to
     * happen, and vice versa.
     *
     * @var array<string, array{label: string, backend: string, description: string}>
     */
    private const METHODS = [
        'mysql' => [
            'label' => 'MySQL',
            'backend' => 'mysql',
            'description' => 'FULLTEXT boolean mode with a prefix wildcard on the last token.',
        ],
        'elasticsearch' => [
            'label' => 'ES edge n-gram',
            'backend' => 'elasticsearch',
            'description' => 'Index-time edge n-grams: every prefix is a real term, so matching is a term lookup.',
        ],
        'es-prefixes' => [
            'label' => 'ES index_prefixes',
            'backend' => 'elasticsearch',
            'description' => 'index_prefixes on a plain text field: Lucene keeps a hidden prefix subfield for match_phrase_prefix.',
        ],
        'es-sayt' => [
            'label' => 'ES search_as_you_type',
            'backend' => 'elasticsearch',
            'description' => 'search_as_you_type shingles the field into _2gram/_3gram subfields and cross-matches them.',
        ],
        'es-bool-prefix' => [
            'label' => 'ES match_bool_prefix',
            'backend' => 'elasticsearch',
            'description' => 'match_bool_prefix over the analysed field: all tokens as terms, the last one as a prefix.',
        ],
        'es-completion' => [
            'label' => 'ES completion (FST)',
            'backend' => 'elasticsearch',
            'description' => 'The completion suggester: an in-memory FST, fastest but prefix-only from the start of the input.',
        ],
    ];

    private ?PDO $pdo = null;
    private ?Client $elasticsearch = null;

    public function __construct(public readonly Config $config = new Config())
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= new PDO(
            $this->config->mysqlDsn(),
            $this->config->mysqlUser,
            $this->config->mysqlPassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ],
        );
    }

    public function elasticsearch(): Client
    {
        return $this->elasticsearch ??= ClientBuilder::create()
            ->setHosts([$this->config->elasticsearchHost])
            ->setRetries(1)
            ->build();
    }

    public function mysqlSuggester(): MysqlSuggester
    {
        return new MysqlSuggester($this->pdo(), $this->config->mysqlTable);
    }

    public function elasticsearchSuggester(): ElasticsearchSuggester
    {
        return new ElasticsearchSuggester($this->elasticsearch(), $this->config->elasticsearchIndex);
    }

    /**
     * Method key => factory, in registry order.
     *
     * Factories and not instances: the whole reason this class exists is that
     * one dead store must not stop the other from answering, and building six
     * suggesters eagerly would open a MySQL socket before the caller has even
     * decided it wants an Elasticsearch method. Every caller wraps the
     * invocation in its own try, because *connecting* is one of the failures
     * worth reporting per method rather than per request.
     *
     * The five Elasticsearch factories deliberately share elasticsearch(): they
     * query the same index over the same HTTP client, so giving each its own
     * connection pool would measure connection setup rather than query methods.
     *
     * @return array<string, callable(): SuggesterInterface>
     */
    public function suggesters(): array
    {
        return [
            'mysql' => fn (): SuggesterInterface => $this->mysqlSuggester(),
            'elasticsearch' => fn (): SuggesterInterface => $this->elasticsearchSuggester(),
            'es-prefixes' => fn (): SuggesterInterface => new ElasticsearchPrefixesSuggester(
                $this->elasticsearch(),
                $this->config->elasticsearchIndex,
            ),
            'es-sayt' => fn (): SuggesterInterface => new ElasticsearchSaytSuggester(
                $this->elasticsearch(),
                $this->config->elasticsearchIndex,
            ),
            'es-bool-prefix' => fn (): SuggesterInterface => new ElasticsearchBoolPrefixSuggester(
                $this->elasticsearch(),
                $this->config->elasticsearchIndex,
            ),
            'es-completion' => fn (): SuggesterInterface => new ElasticsearchCompletionSuggester(
                $this->elasticsearch(),
                $this->config->elasticsearchIndex,
            ),
        ];
    }

    /**
     * Builds exactly one method. Throws for an unknown key on purpose: by the
     * time anything gets here the key has been validated against methodKeys(),
     * so an unknown one is a bug and not user input.
     */
    public function suggester(string $key): SuggesterInterface
    {
        $factory = $this->suggesters()[$key] ?? null;

        if ($factory === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown suggest method "%s", expected one of: %s.',
                $key,
                implode(', ', $this->methodKeys()),
            ));
        }

        return $factory();
    }

    /**
     * Method keys in registry order, without touching a connection.
     *
     * Validating ?engine= and rendering the method picker both need the list of
     * methods and nothing else; going through suggesters() for that would be
     * harmless today (the factories are closures) but invites someone to make
     * it eager again later.
     *
     * @return list<string>
     */
    public function methodKeys(): array
    {
        return array_keys(self::METHODS);
    }

    public function hasMethod(string $key): bool
    {
        return isset(self::METHODS[$key]);
    }

    /**
     * The registry as a flat, ordered list for the API and the UI. A list and
     * not a keyed map because JSON objects have no guaranteed order in every
     * client, and the order here is meaningful (baseline first).
     *
     * @return list<array{key: string, label: string, backend: string, description: string}>
     */
    public function methods(): array
    {
        $methods = [];

        foreach (self::METHODS as $key => $meta) {
            $methods[] = ['key' => $key] + $meta;
        }

        return $methods;
    }

    /**
     * Method keys belonging to one backend, in registry order. This is what
     * `--engine=es` expands to and what lets the health endpoint check one
     * representative method per backend instead of five near-identical ones.
     *
     * @return list<string>
     */
    public function methodKeysForBackend(string $backend): array
    {
        $keys = [];

        foreach (self::METHODS as $key => $meta) {
            if ($meta['backend'] === $backend) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Backend name => the method whose health() speaks for that backend.
     *
     * The first method registered for a backend wins, which is why registry
     * order puts the plain 'mysql' and 'elasticsearch' methods first: they are
     * the ones whose health() answers the question a health check is actually
     * asking ("is the store up and does it hold documents"), rather than one
     * method's opinion about one field in a mapping.
     *
     * @return array<string, string>
     */
    public function backends(): array
    {
        $backends = [];

        foreach (self::METHODS as $key => $meta) {
            $backends[$meta['backend']] ??= $key;
        }

        return $backends;
    }

    public function mysqlIndexer(): MysqlIndexer
    {
        return new MysqlIndexer($this->pdo(), $this->config->mysqlTable);
    }

    public function elasticsearchIndexer(): ElasticsearchIndexer
    {
        return new ElasticsearchIndexer($this->elasticsearch(), $this->config->elasticsearchIndex);
    }

    /**
     * Indexers stay one per backend, not one per method: the five Elasticsearch
     * methods read different fields of the *same* document in the same index,
     * so there is exactly one import to run.
     *
     * @return array<string, IndexerInterface>
     */
    public function indexers(): array
    {
        return [
            'mysql' => $this->mysqlIndexer(),
            'elasticsearch' => $this->elasticsearchIndexer(),
        ];
    }
}
