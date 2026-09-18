<?php

declare(strict_types=1);

namespace App\Import;

/**
 * A write target for the import. Documents are pushed in one at a time; the
 * implementation buffers and flushes in batches.
 */
interface IndexerInterface
{
    public function name(): string;

    /**
     * Create the schema. When $recreate is true, drop whatever is there first.
     */
    public function prepare(bool $recreate): void;

    public function add(SuggestionDocument $document): void;

    /**
     * Write any buffered documents.
     */
    public function flush(): void;

    /**
     * Called once after the last document: build secondary indexes, restore
     * refresh settings, and make the data searchable.
     */
    public function finish(): void;

    public function count(): int;
}
