<?php

declare(strict_types=1);

namespace App\Import;

use App\Model\SuggestionType;
use App\Support\Normalizer;

/**
 * The canonical indexable document.
 *
 * The CSV is read once and turned into these; each indexer then maps them onto
 * its own storage. Keeping one document shape means MySQL and Elasticsearch
 * index identical content, which is the whole point of the comparison.
 */
final class SuggestionDocument
{
    /**
     * @param list<string> $aliases alternative names (FR/DE, sub-localities)
     */
    public function __construct(
        public readonly string $id,
        public readonly SuggestionType $type,
        public readonly string $label,
        public readonly ?string $streetName,
        public readonly ?string $houseNumber,
        public readonly ?string $boxNumber,
        public readonly ?string $postcode,
        public readonly ?string $postName,
        public readonly string $municipalityName,
        public readonly string $nisCode,
        public readonly ?float $lat,
        public readonly ?float $lon,
        public readonly int $popularity,
        public readonly array $aliases = [],
    ) {
    }

    /**
     * Everything a user could type to find this document, folded and deduped.
     * Stored on the document so MySQL FULLTEXT and the Elasticsearch catch-all
     * field search the exact same string.
     */
    public function searchText(): string
    {
        return Normalizer::haystack([
            $this->streetName,
            $this->houseNumber,
            $this->postcode,
            $this->postName,
            $this->municipalityName,
            ...$this->aliases,
        ]);
    }

    /**
     * The name part only, used for prefix matching and for boosting hits that
     * match the head of the primary name over hits that only match the city.
     */
    public function primaryName(): string
    {
        return $this->streetName ?? ($this->type === SuggestionType::Postcode
            ? (string) $this->postcode
            : $this->municipalityName);
    }
}
