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
