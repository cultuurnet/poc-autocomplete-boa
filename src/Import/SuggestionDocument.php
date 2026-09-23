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

    /**
     * The strings a user would type meaning "this document", for the
     * Elasticsearch completion suggester.
     *
     * Deliberately *not* searchText(): the completion FST matches a prefix of a
     * whole input, never a word in the middle of one, so feeding it the joined
     * haystack would mean "gent" only completes documents whose haystack starts
     * with "gent" - the postcode and house number would swallow everything
     * else. Each name has to be its own input instead, which is also why the
     * aliases are listed separately rather than concatenated: "Liège" and
     * "Luik" are two things a user types, not one.
     *
     * Text is handed over unfolded on purpose. The completion field carries the
     * folding analyser, so Elasticsearch folds it the same way every other field
     * is folded, and the raw form is what the suggester echoes back as the
     * matched text.
     *
     * @return list<string> non-empty, deduplicated, primary name first
     */
    public function completionInputs(): array
    {
        $inputs = [];
        $seen = [];

        foreach ([$this->primaryName(), ...$this->aliases] as $input) {
            $input = trim($input);

            // An empty input makes Elasticsearch reject the whole document, and
            // duplicates (a street whose alias repeats its name) would cost FST
            // entries without ever changing a result.
            //
            // The seen-set is kept beside the list rather than being the list:
            // a postcode document's primary name is "2230", and PHP would turn
            // that array key back into the *integer* 2230, which json_encode
            // then writes as a bare number and the completion field rejects as
            // a non-string input. Appending to a real list keeps the type.
            if ($input === '' || isset($seen[$input])) {
                continue;
            }

            $seen[$input] = true;
            $inputs[] = $input;
        }

        return $inputs;
    }
}
