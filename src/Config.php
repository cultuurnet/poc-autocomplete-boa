<?php

declare(strict_types=1);

namespace App;

/**
 * Environment-backed configuration. No framework, no .env parser: docker
 * compose injects the variables and the defaults below are the compose values,
 * so the CLI also works when run straight from the host against forwarded ports.
 */
final class Config
{
    public readonly ?string $csvPath;
    public readonly string $mysqlHost;
    public readonly int $mysqlPort;
    public readonly string $mysqlDatabase;
    public readonly string $mysqlUser;
    public readonly string $mysqlPassword;
    public readonly string $mysqlTable;
    public readonly string $elasticsearchHost;
    public readonly string $elasticsearchIndex;

    public function __construct()
    {
        // No default. Which extract to load is a per-run choice, so it is a CLI
        // option (--csv/-f); CSV_PATH only pins it for an environment that always
        // reads the same file. Guessing a filename here just moved the failure to
        // the first fopen, and made "which file did it even try?" a code question.
        $this->csvPath = self::envOrNull('CSV_PATH');
        $this->mysqlHost = self::env('MYSQL_HOST', '127.0.0.1');
        $this->mysqlPort = (int) self::env('MYSQL_PORT', '3306');
        $this->mysqlDatabase = self::env('MYSQL_DATABASE', 'autocomplete');
        $this->mysqlUser = self::env('MYSQL_USER', 'autocomplete');
        $this->mysqlPassword = self::env('MYSQL_PASSWORD', 'autocomplete');
        $this->mysqlTable = self::env('MYSQL_TABLE', 'location_suggestions');
        $this->elasticsearchHost = self::env('ELASTICSEARCH_HOST', 'http://127.0.0.1:9200');
        $this->elasticsearchIndex = self::env('ELASTICSEARCH_INDEX', 'location_suggestions');
    }

    public function mysqlDsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->mysqlHost,
            $this->mysqlPort,
            $this->mysqlDatabase,
        );
    }

    private static function env(string $key, string $default): string
    {
        return self::envOrNull($key) ?? $default;
    }

    private static function envOrNull(string $key): ?string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? null : $value;
    }
}
