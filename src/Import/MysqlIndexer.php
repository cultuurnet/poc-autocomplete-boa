<?php

declare(strict_types=1);

namespace App\Import;

use App\Model\SuggestionType;
use App\Support\Normalizer;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Writes suggestion documents into a single flat MySQL table.
 *
 * The whole design is shaped by the fact that the address-level import is 4.2M
 * rows: every per-row cost is paid four million times. Hence multi-row INSERTs
 * with a cached prepared statement, and hence the FULLTEXT index being built
 * once at the end instead of maintained during the load.
 */
final class MysqlIndexer implements IndexerInterface
{
    /**
     * 2000 rows x 16 columns = 32k placeholders. MySQL refuses a prepared
     * statement with more than 65535 of them, so 4095 rows is the hard ceiling
     * for this column list: do not raise the batch size without recounting.
     * The other ceiling is max_allowed_packet (256M in docker/mysql/my.cnf);
     * a 2000-row batch is a few hundred kilobytes, so that one is not close.
     */
    private const BATCH_SIZE = 2_000;

    /** Column order used by every INSERT; must match rowValues(). */
    private const COLUMNS = [
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
        'lat',
        'lon',
        'popularity',
        'search_text',
        'primary_name_norm',
    ];

    private const SCHEMA_FILE = __DIR__ . '/../../sql/schema.sql';
    private const FULLTEXT_INDEX = 'ft_search_text';

    /** @var list<SuggestionDocument> */
    private array $buffer = [];

    /**
     * A full batch always produces the exact same SQL, so it is worth keeping
     * the server-side prepared statement around instead of re-preparing it
     * ~2100 times during an address-level import.
     */
    private ?PDOStatement $batchStatement = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'location_suggestions',
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $this->table) !== 1) {
            throw new InvalidArgumentException(sprintf('Unsafe MySQL table name: %s', $this->table));
        }
    }

    public function name(): string
    {
        return 'mysql';
    }

    public function prepare(bool $recreate): void
    {
        $schema = $this->schema();

        if ($recreate) {
            foreach ($schema['drop'] as $statement) {
                $this->pdo->exec($statement);
            }
        }

        // Deliberately only the `create` section: the FULLTEXT index is added
        // by finish(). See the comment at the top of sql/schema.sql.
        foreach ($schema['create'] as $statement) {
            $this->pdo->exec($statement);
        }
    }

    public function deleteType(SuggestionType $type): int
    {
        // Buffered documents are for the type being imported, so letting them
        // sit through a delete of that same type would silently un-delete part
        // of the previous import.
        $this->flush();

        $statement = $this->pdo->prepare(sprintf('DELETE FROM `%s` WHERE `doc_type` = ?', $this->table));
        $statement->execute([$type->value]);

        return $statement->rowCount();
    }

    public function add(SuggestionDocument $document): void
    {
        $this->buffer[] = $document;

        if (count($this->buffer) >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $rows = $this->buffer;
        $this->buffer = [];

        $parameters = [];

        foreach ($rows as $document) {
            foreach ($this->rowValues($document) as $value) {
                $parameters[] = $value;
            }
        }

        // One transaction per batch. The multi-row INSERT is atomic by itself,
        // but the explicit boundary means a failure halfway through the import
        // leaves the table in a known state instead of relying on autocommit
        // semantics we would then have to reason about.
        $this->pdo->beginTransaction();

        try {
            $this->statementFor(count($rows))->execute($parameters);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function finish(): void
    {
        $this->flush();

        if (!$this->hasFulltextIndex()) {
            foreach ($this->schema()['fulltext'] as $statement) {
                $this->pdo->exec($statement);
            }
        }

        // The ranking query sorts on a computed expression over a fulltext
        // candidate set; without fresh statistics the optimizer regularly picks
        // idx_doc_type over the fulltext index and the first benchmark run
        // measures a table scan.
        // ANALYZE TABLE returns a result set. PDO::exec() cannot consume one, so
        // it would stay pending on the connection and every later query on this
        // PDO instance would fail with "unbuffered queries are active".
        $analyze = $this->pdo->query(sprintf('ANALYZE TABLE `%s`', $this->table));

        if ($analyze !== false) {
            $analyze->fetchAll();
            $analyze->closeCursor();
        }
    }

    public function count(): int
    {
        $statement = $this->pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $this->table));

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * Reuse the cached statement for full batches; the trailing partial batch
     * gets a one-off statement with its own placeholder count.
     */
    private function statementFor(int $rowCount): PDOStatement
    {
        if ($rowCount !== self::BATCH_SIZE) {
            return $this->pdo->prepare($this->insertSql($rowCount));
        }

        return $this->batchStatement ??= $this->pdo->prepare($this->insertSql(self::BATCH_SIZE));
    }

    /**
     * ON DUPLICATE KEY UPDATE rather than INSERT IGNORE: re-running the import
     * without --recreate should refresh documents whose content changed, not
     * silently keep the old row. The `AS d` row alias is the MySQL 8.0.19+
     * replacement for the deprecated VALUES() function.
     */
    private function insertSql(int $rowCount): string
    {
        $row = '(' . implode(', ', array_fill(0, count(self::COLUMNS), '?')) . ')';

        $updates = [];

        foreach (self::COLUMNS as $column) {
            if ($column === 'id') {
                continue;
            }

            $updates[] = sprintf('`%s` = d.`%s`', $column, $column);
        }

        return sprintf(
            'INSERT INTO `%s` (`%s`) VALUES %s AS d ON DUPLICATE KEY UPDATE %s',
            $this->table,
            implode('`, `', self::COLUMNS),
            implode(', ', array_fill(0, $rowCount, $row)),
            implode(', ', $updates),
        );
    }

    /**
     * @return list<string|int|float|null> in the order of self::COLUMNS
     */
    private function rowValues(SuggestionDocument $document): array
    {
        return [
            $document->id,
            $document->type->value,
            $document->label,
            $document->placeName,
            $document->streetName,
            $document->houseNumber,
            $document->boxNumber,
            $document->postcode,
            $document->postName,
            $document->municipalityName,
            $document->nisCode,
            $document->lat,
            $document->lon,
            $document->popularity,
            // Folded once here so the query side never has to fold a column.
            $document->searchText(),
            Normalizer::normalize($document->primaryName()),
        ];
    }

    private function hasFulltextIndex(): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
        );
        $statement->execute([$this->table, self::FULLTEXT_INDEX]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Parse sql/schema.sql into its named sections.
     *
     * @return array{drop: list<string>, create: list<string>, fulltext: list<string>}
     */
    private function schema(): array
    {
        $sql = @file_get_contents(self::SCHEMA_FILE);

        if ($sql === false) {
            throw new RuntimeException(sprintf('Cannot read schema file %s', self::SCHEMA_FILE));
        }

        $sql = str_replace('{{table}}', $this->table, $sql);

        $parts = preg_split(
            '/^--\s*@section\s+([a-z_]+)\s*$/mi',
            $sql,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if ($parts === false) {
            throw new RuntimeException('Cannot parse ' . self::SCHEMA_FILE);
        }

        $sections = [];

        // $parts[0] is the file header before the first marker; after that the
        // array alternates section name, section body.
        for ($i = 1; $i < count($parts); $i += 2) {
            $sections[strtolower($parts[$i])] = $this->statements($parts[$i + 1] ?? '');
        }

        foreach (['drop', 'create', 'fulltext'] as $required) {
            if (($sections[$required] ?? []) === []) {
                throw new RuntimeException(sprintf('Schema section "%s" is missing or empty', $required));
            }
        }

        /** @var array{drop: list<string>, create: list<string>, fulltext: list<string>} $sections */
        return $sections;
    }

    /**
     * Splitting on `;` is safe here and only here: the DDL in sql/schema.sql
     * contains no string literal that could hold a semicolon.
     *
     * @return list<string>
     */
    private function statements(string $body): array
    {
        $body = preg_replace('/^\s*--.*$/m', '', $body) ?? '';

        $statements = [];

        foreach (explode(';', $body) as $statement) {
            $statement = trim($statement);

            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
