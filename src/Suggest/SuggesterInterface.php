<?php

declare(strict_types=1);

namespace App\Suggest;

use App\Model\SuggestQuery;
use App\Model\SuggestResult;

interface SuggesterInterface
{
    /**
     * Engine identifier used in the API response and the benchmark output.
     */
    public function name(): string;

    public function suggest(SuggestQuery $query): SuggestResult;

    /**
     * Whether the backing store is reachable and holds data.
     *
     * @return array{ok: bool, detail: string, documents: int}
     */
    public function health(): array;
}
