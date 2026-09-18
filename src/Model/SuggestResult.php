<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The outcome of one suggest call against one engine.
 */
final class SuggestResult
{
    /**
     * @param list<Suggestion>     $suggestions
     * @param array<string, mixed> $debug the query actually sent to the engine
     */
    public function __construct(
        public readonly string $engine,
        public readonly array $suggestions,
        public readonly float $tookMs,
        public readonly int $total = 0,
        public readonly array $debug = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'engine' => $this->engine,
            'took_ms' => round($this->tookMs, 2),
            'total' => $this->total,
            'count' => count($this->suggestions),
            'suggestions' => array_map(static fn (Suggestion $s): array => $s->toArray(), $this->suggestions),
            'debug' => $this->debug,
        ];
    }
}
