<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One autocomplete suggestion, engine agnostic.
 *
 * Both suggesters map their native hit format onto this so the comparison UI
 * and the benchmark can treat the two result sets identically.
 */
final class Suggestion
{
    public function __construct(
        public readonly string $id,
        public readonly SuggestionType $type,
        public readonly string $label,
        public readonly ?string $streetName = null,
        public readonly ?string $houseNumber = null,
        public readonly ?string $postcode = null,
        public readonly ?string $postName = null,
        public readonly ?string $municipalityName = null,
        public readonly ?string $nisCode = null,
        public readonly ?float $lat = null,
        public readonly ?float $lon = null,
        public readonly float $score = 0.0,
        public readonly int $popularity = 0,
        /** @var array<string, mixed> free-form per-engine scoring detail, shown in the UI */
        public readonly array $debug = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'filter' => $this->type->filter(),
            'label' => $this->label,
            'street_name' => $this->streetName,
            'house_number' => $this->houseNumber,
            'postcode' => $this->postcode,
            'post_name' => $this->postName,
            'municipality_name' => $this->municipalityName,
            'nis_code' => $this->nisCode,
            'coordinates' => $this->lat === null || $this->lon === null
                ? null
                : ['lat' => $this->lat, 'lon' => $this->lon],
            'score' => round($this->score, 4),
            'popularity' => $this->popularity,
            'debug' => $this->debug,
        ];
    }
}
