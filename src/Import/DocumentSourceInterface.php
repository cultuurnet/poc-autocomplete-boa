<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Something that turns a CSV export into indexable documents.
 *
 * Two exports feed the same index - the address register and the UiTdatabank
 * place export - and the import pipeline (progress reporting, per-engine
 * timing, failure handling) is identical for both. This is the seam that lets
 * ImportPipeline stay unaware of which file it is streaming.
 */
interface DocumentSourceInterface
{
    /**
     * @param callable(string, int): void|null $progress receives a phase label and a row count
     *
     * @return iterable<SuggestionDocument>
     */
    public function documents(ImportOptions $options, ?callable $progress = null): iterable;
}
