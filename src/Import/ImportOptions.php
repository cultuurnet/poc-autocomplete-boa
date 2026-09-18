<?php

declare(strict_types=1);

namespace App\Import;

/**
 * What to read out of the CSV.
 */
final class ImportOptions
{
    /**
     * @param int|null     $limit      stop after this many CSV rows (null = whole file)
     * @param list<string> $postcodes  only keep these postcodes (empty = all)
     * @param bool         $addresses  also emit one document per house number
     * @param bool         $streets    emit aggregated street documents
     * @param bool         $regions    emit municipality and postcode documents
     */
    public function __construct(
        public readonly ?int $limit = null,
        public readonly array $postcodes = [],
        public readonly bool $addresses = false,
        public readonly bool $streets = true,
        public readonly bool $regions = true,
    ) {
    }

    public function matchesPostcode(string $postcode): bool
    {
        return $this->postcodes === [] || in_array($postcode, $this->postcodes, true);
    }
}
