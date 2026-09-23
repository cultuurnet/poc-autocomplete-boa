<?php

declare(strict_types=1);

namespace App\Import;

use App\Model\SuggestionType;

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

    /**
     * Remove every document of one type, leaving the rest of the index alone.
     *
     * Two exports write into the same index, so prepare(true) - which drops the
     * whole thing - is too blunt for either of them: neither import may take
     * the other's documents with it. This is how both --recreate flags reset
     * their own types, and it is what makes a row that disappeared from an
     * export disappear here too (re-indexing alone only ever overwrites ids
     * that still exist).
     *
     * @return int documents removed
     */
    public function deleteType(SuggestionType $type): int;

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
