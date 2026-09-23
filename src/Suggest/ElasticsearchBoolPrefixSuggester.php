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
     * The gate, as one match_bool_prefix over the plain whole-word field.
     *
     * Two shapes, not one, and the second is the one main added for the
     * incumbent: once the user has moved past the name ("goorbaan 5"), the
     * trailing locative token is a finished word, and match_bool_prefix would still
     * match it as a prefix. A plain `match` with operator `and` gates it as the
     * whole word it is, which is what keeps this method answering the same
     * question as the other four.
     *
     * @param list<string> $wholeWords
     *
     * @return list<array<string, mixed>>
     */
    protected function gateClauses(array $wholeWords, ?string $prefix): array
    {
        $field = 'search_text.folded';

        if ($prefix === null) {
            return [['match' => [$field => ['query' => implode(' ', $wholeWords), 'operator' => 'and']]]];
        }

        return [
            [
                'match_bool_prefix' => [
                    $field => [
                        'query' => implode(' ', [...$wholeWords, $prefix]),
                        // match_bool_prefix is an OR by default, which would turn
                        // the gate into a suggestion. 100% restores "every token
                        // must be found", which is what every other method's gate
                        // means.
                        'minimum_should_match' => '100%',
                    ],
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
