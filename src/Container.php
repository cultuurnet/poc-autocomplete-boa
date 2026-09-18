<?php

declare(strict_types=1);

namespace App;

use App\Import\DocumentSource;
use App\Import\ElasticsearchIndexer;
use App\Import\IndexerInterface;
use App\Import\MysqlIndexer;
use App\Suggest\ElasticsearchSuggester;
use App\Suggest\MysqlSuggester;
use App\Suggest\SuggesterInterface;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use PDO;

/**
 * Hand-rolled service locator. A POC with eight services does not need a DI
 * container; it does need lazy construction, because the web request must not
 * fail when only one of the two engines is up.
 */
final class Container
{
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

    public function documentSource(): DocumentSource
    {
        return new DocumentSource($this->config->csvPath);
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
     * @return array<string, SuggesterInterface>
     */
    public function suggesters(): array
    {
        return [
            'mysql' => $this->mysqlSuggester(),
            'elasticsearch' => $this->elasticsearchSuggester(),
        ];
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
