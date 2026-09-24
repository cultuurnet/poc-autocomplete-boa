<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Normalizer;

/**
 * A parsed autocomplete request. Both engines receive exactly this, so any
 * difference in results comes from the engine and not from request handling.
 */
final class SuggestQuery
{
    /** @var list<string> */
    public readonly array $tokens;

    /** @var list<string> */
    private readonly array $locative;

    /** @var list<string> */
    private readonly array $detail;

    private readonly bool $typingLocative;

    private readonly bool $bareNumeric;

    /**
     * @param list<SuggestionType> $types document types to search
     */
    public function __construct(
        public readonly string $raw,
        public readonly int $limit = 10,
        public readonly array $types = [],
        public readonly bool $fuzzy = true,
        /**
         * Whether the index holds house-number documents (Config::$houseNumbersIndexed).
         *
         * The locative/detail split below exists for exactly one reason: on a
         * street-level index no document carries a house number, so requiring
         * "12" returns nothing for a completely ordinary way of writing an
         * address. That premise is false once the index is built with
         * --level=address or --level=all, and then the split costs precision
         * rather than buying recall - the number is the most selective token
         * the user typed, and demoting it to a ranking signal buries the one
         * document they asked for under every other number in the street.
         *
         * Measured on the 4.0M-document index: "kerkstraat 12 gent" gates down
         * to 413 candidates with the split and 2 without, and the two are the
         * right ones.
         *
         * So the split is not dead code to delete, it is behaviour that belongs
         * to one shape of index. The flag names the corpus, not the mechanism,
         * because that is the thing an operator actually knows.
         */
        public readonly bool $houseNumbersIndexed = false,
    ) {
        $this->tokens = Normalizer::tokenize($raw);

        $detailIndices = self::detailIndices($this->tokens, $houseNumbersIndexed);
        $locative = [];
        $detail = [];

        foreach ($this->tokens as $index => $token) {
            if (in_array($index, $detailIndices, true)) {
                $detail[] = $token;
            } else {
                $locative[] = $token;
            }
        }

        // A query made of nothing but address detail is not address detail: on a
        // bare "22" or "12a" the number is all the user has given us, so it has
        // to keep narrowing the search. Without this the split would leave an
        // empty gate and "22" would match every document in the index.
        if ($locative === []) {
            $locative = $this->tokens;
            $detail = [];
            $detailIndices = [];
        }

        $this->locative = $locative;
        $this->detail = $detail;
        $this->bareNumeric = $this->tokens !== [] && array_reduce(
            $this->tokens,
            static fn (bool $carry, string $t): bool => $carry && Normalizer::isNumericToken($t),
            true,
        );

        // Two conditions, and the second one is the whole point of this class
        // knowing what a number looks like.
        //
        // The trailing token is normally a prefix, because it is the one the
        // user is still typing. A trailing *number* is the exception, because
        // the haystack it would be prefix-matched against does not separate
        // house numbers from postcodes - searchText() folds street, number,
        // postcode and municipality into one string - so "kerkstraat 90"
        // prefix-matches the 9050 of Gentbrugge, the 9060 of Zelzate and the
        // 9080 of Zaffelare, and 885 documents enter a gate that 46 documents
        // should have passed. The number is the most selective thing the user
        // typed and prefixing it turns it into the least selective.
        //
        // Unless it is all they typed. A bare "90" really is a postcode being
        // typed - there is no street to attach a house number to - and there
        // the prefix reading is the only one that means anything.
        $this->typingLocative = $this->tokens !== []
            && !in_array(array_key_last($this->tokens), $detailIndices, true)
            && ($this->bareNumeric || !Normalizer::isNumericToken($this->lastToken()));
    }

    /**
     * Which tokens name a place, and which only describe where in that place.
     *
     * "kerkstraat 12 bus 5 gent" is two questions in one string: *which*
     * Kerkstraat (the street, the city) and *where on it* (the number, the box).
     * Only the first half can narrow a search over an index of streets and
     * municipalities - the second half describes a level of detail that a
     * street document does not even carry, so requiring it finds nothing at
     * all. Which is exactly what used to happen.
     *
     * @param list<string> $tokens
     *
     * @return list<int> positions in $tokens that are address detail
     */
    private static function detailIndices(array $tokens, bool $houseNumbersIndexed = false): array
    {
        $indices = [];

        foreach ($tokens as $index => $token) {
            // The house number is only detail while no document carries one.
            // Once the index is built at address level it is the most
            // selective token in the query and belongs in the gate - but the
            // box reference never does, whatever the index holds, because
            // SuggestionDocument does not put box_number into search_text at
            // all. Gating on it matches nothing by construction, which is
            // exactly what "kerkstraat 12 bus 5 gent" did before this
            // distinction existed: zero results on a perfectly ordinary
            // address.
            $isDetail = ($houseNumbersIndexed ? false : Normalizer::isHouseNumberToken($token))
                || Normalizer::isBoxMarkerToken($token)
                // See Normalizer::isBoxMarkerToken(): the bare "b" is only a box
                // marker when it directly follows the house number, as in
                // "12 b 5". Anywhere else it is someone typing a name.
                || ($token === 'b' && $index > 0 && (
                    in_array($index - 1, $indices, true)
                    || Normalizer::isHouseNumberToken($tokens[$index - 1])
                ));

            // The value after a box marker is detail for the same reason as
            // the marker: "bus 5" puts neither token anywhere a document can
            // be matched on.
            if (!$isDetail && $index > 0 && in_array($index - 1, $indices, true)) {
                $previous = $tokens[$index - 1];
                $isDetail = Normalizer::isBoxMarkerToken($previous) || $previous === 'b';
            }

            if ($isDetail) {
                $indices[] = $index;
            }
        }

        return $indices;
    }

    /**
     * @return list<string> the tokens that may narrow the search
     */
    public function locativeTokens(): array
    {
        return $this->locative;
    }

    /**
     * @return list<string> house numbers and box references, which may only
     *                      influence the order of the results
     */
    public function detailTokens(): array
    {
        return $this->detail;
    }

    /**
     * Whether the query is nothing but digits ("90", "2230", "12a").
     *
     * The one case where a number is read as a postcode being typed rather
     * than as a house number: with no street or municipality in the query
     * there is nothing for a house number to be a number *of*.
     */
    public function isBareNumeric(): bool
    {
        return $this->bareNumeric;
    }

    /**
     * The numbers that should rank a document without gating it.
     *
     * detailTokens() only holds these on a street-level index; at address
     * level the number gates instead, and then this is what still tells
     * number 90 from number 9050 inside the candidate set - and what carries
     * the fuzzy fallback, where the number is dropped from the gate so that a
     * mistyped *street* can still be recovered.
     *
     * Postcodes are excluded: postcodeClauses() already scores them against
     * the postcode field, which is the field that actually means postcode,
     * and scoring them here as well would count the same evidence twice.
     *
     * @return list<string>
     */
    public function numberTokens(): array
    {
        $numbers = [];
        $seen = [];

        foreach ($this->tokens as $token) {
            // Appended to a real list, never collected as array keys: PHP turns
            // the key "90" back into the integer 90, and a caller that types
            // its parameters would then be handed an int where it declared a
            // list<string>. The seen-set lives beside the list for that reason.
            if (isset($seen[$token]) || !Normalizer::isNumericToken($token) || Normalizer::isPostcodeToken($token)) {
                continue;
            }

            $seen[$token] = true;
            $numbers[] = $token;
        }

        return $numbers;
    }

    /**
     * Whether the token the user is still typing is part of the place name.
     *
     * False once they have moved on to the house number: in "goorbaan 5" the
     * street name is finished, so it should be matched as a whole word rather
     * than as a prefix.
     */
    public function isTypingLocative(): bool
    {
        return $this->typingLocative;
    }

    public function normalized(): string
    {
        return implode(' ', $this->tokens);
    }

    public function isEmpty(): bool
    {
        return $this->tokens === [];
    }

    /**
     * The token the user is still typing. Type-ahead treats it as a prefix,
     * every earlier token as a complete word.
     */
    public function lastToken(): string
    {
        return $this->tokens === [] ? '' : $this->tokens[array_key_last($this->tokens)];
    }

    /**
     * @return list<string> every token except the one being typed
     */
    public function completeTokens(): array
    {
        return array_slice($this->tokens, 0, -1);
    }

    /**
     * @return list<string> the document types to search, as strings
     */
    public function typeValues(): array
    {
        if ($this->types === []) {
            return SuggestionType::values();
        }

        return array_values(array_map(static fn (SuggestionType $t): string => $t->value, $this->types));
    }
}
