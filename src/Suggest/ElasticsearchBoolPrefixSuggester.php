<?php

declare(strict_types=1);

namespace App\Suggest;

/**
 * Pure query time. The cheapest possible index, the most expensive possible
 * query - the control that prices the other four.
 *
 * The method: nothing at all is done at index time. The fields are plain
 * analysed text, one term per word, no n-grams and no prefix index of any
 * kind. `match_bool_prefix` then turns the trailing half-typed token into a
 * real Lucene prefix query, which has to walk the term dictionary and OR
 * together every term that starts with those characters. Verified on the
 * cluster: the same query that profiles as a term lookup on a prefix-indexed
 * field profiles here as
 *
 *   #search_text.folded:korte search_text.folded:linden*
 *
 * - a MultiTermQuery, the scan the other three methods exist to avoid.
 *
 * Good at: costing nothing to build. There is no extra field, no extra
 * segment data, no import slowdown and nothing to keep in sync; any existing
 * text field can be queried this way today. For short result sets over a small
 * term dictionary it is also perfectly fast, which is worth knowing before
 * anyone reaches for an analyser chain.
 *
 * Cannot do: stay fast as the term dictionary grows or the prefix shortens.
 * One character against 4.2M documents is a scan over an enormous slice of the
 * dictionary, and that cost lands on every keystroke. That is the number this
 * method exists to produce.
 *
 * Explicitly NOT done here: no `terminate_after`, no minimum query length, no
 * `max_expansions` on the gate, no early-exit guard of any kind. Every one of
 * those would make this method look better than it is and would make it a
 * different method from the other four. If it is slow on one- and
 * two-character queries, that is the result.
 *
 * The question it answers: what, in milliseconds, do the three index-time
 * strategies actually buy? Everything above this line's latency is the price
 * of the index; everything below it is not worth paying for.
 */
final class ElasticsearchBoolPrefixSuggester extends AbstractElasticsearchQuerySuggester
{
    private const ENGINE = 'es-bool-prefix';

    public function name(): string
    {
        return self::ENGINE;
    }

    /**
     * Same clause as es-prefixes, same minimum_should_match, pointed at the
     * plain whole-word subfield instead of the prefix-indexed one. The two
     * methods differ in the mapping and nowhere else, which is what makes the
     * latency difference between them attributable to index_prefixes alone.
     *
     * @return array<string, mixed>
     */
    protected function gateClause(string $text, bool $fuzzyFallback): array
    {
        if ($fuzzyFallback) {
            return $this->fuzzyGateClause($text);
        }

        return [
            'match_bool_prefix' => [
                'search_text.folded' => [
                    'query' => $text,
                    'minimum_should_match' => '100%',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function nameClauses(string $text): array
    {
        return [
            // Byte for byte the incumbent's ordered-name clause: primary_name
            // .folded is the same plain field in both. Where the incumbent and
            // this method diverge is the gate, and keeping this clause identical
            // is what isolates that.
            [
                'match_phrase_prefix' => [
                    'primary_name.folded' => [
                        'query' => $text,
                        'max_expansions' => self::NAME_MAX_EXPANSIONS,
                        'boost' => self::BOOST_NAME_PHRASE_PREFIX,
                    ],
                ],
            ],
            [
                'match_bool_prefix' => [
                    'primary_name.folded' => [
                        'query' => $text,
                        'boost' => self::BOOST_NAME_MATCH,
                    ],
                ],
            ],
        ];
    }

    protected function fuzzyField(): string
    {
        return 'search_text.folded';
    }
}
