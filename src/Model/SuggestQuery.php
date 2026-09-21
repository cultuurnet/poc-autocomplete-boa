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

    /**
     * @param list<SuggestionType> $types document types to search
     */
    public function __construct(
        public readonly string $raw,
        public readonly int $limit = 10,
        public readonly array $types = [],
        public readonly bool $fuzzy = true,
    ) {
        $this->tokens = Normalizer::tokenize($raw);

        $detailIndices = self::detailIndices($this->tokens);
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
        $this->typingLocative = $this->tokens !== []
            && !in_array(array_key_last($this->tokens), $detailIndices, true);
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
    private static function detailIndices(array $tokens): array
    {
        $indices = [];

        foreach ($tokens as $index => $token) {
            $isDetail = Normalizer::isHouseNumberToken($token)
                || Normalizer::isBoxMarkerToken($token)
                // See Normalizer::isBoxMarkerToken(): the bare "b" is only a box
                // marker when it directly follows the house number, as in
                // "12 b 5". Anywhere else it is someone typing a name.
                || ($token === 'b' && in_array($index - 1, $indices, true));

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
