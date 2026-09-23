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
     * @param string|null  $placeName the venue name, on Place documents only.
     *                                Kept apart from $streetName because a place
     *                                has both: "Yper Museum" at "Grote Markt 34".
     * @param list<string> $aliases   alternative names (FR/DE, sub-localities)
     */
    public function __construct(
        public readonly string $id,
        public readonly SuggestionType $type,
        public readonly string $label,
        public readonly ?string $placeName,
        public readonly ?string $streetName,
        public readonly ?string $houseNumber,
        public readonly ?string $boxNumber,
        public readonly ?string $postcode,
        public readonly ?string $postName,
        public readonly string $municipalityName,
        // Nullable since the place export carries no NIS code: a UiTdatabank
        // place names its municipality but never identifies it.
        public readonly ?string $nisCode,
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
            $this->placeName,
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
        // Place first: a place document also carries a street name, but what
        // someone types to find it is the venue, not the street it stands on.
        return $this->placeName ?? $this->streetName ?? ($this->type === SuggestionType::Postcode
            ? (string) $this->postcode
            : $this->municipalityName);
    }
}
