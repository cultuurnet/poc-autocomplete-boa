<?php

declare(strict_types=1);

namespace App\Suggest;

/**
 * `index_prefixes` on an otherwise ordinary text field.
 *
 * The method: the field is analysed normally - whole words, one term per word
 * - and Elasticsearch additionally maintains a hidden `._index_prefix`
 * subfield holding every 1..19 character prefix of every term. Nothing about
 * the query has to know this: when a `prefix` query, a `match_phrase_prefix`
 * or the trailing term of a `match_bool_prefix` hits the field, ES silently
 * rewrites it into a term lookup against that hidden subfield. Verified on the
 * cluster: `match_bool_prefix` on search_text.prefixes for "korte linden"
 * profiles as
 *
 *   #search_text.prefixes:korte #search_text.prefixes._index_prefix:linden
 *
 * - two term queries, no expansion, no scan. That rewrite is the entire point
 * of the method.
 *
 * Good at: giving the incumbent's lookup cost without the incumbent's damage
 * to the visible index. The *searchable* field stays one term per word, so
 * BM25 term frequency and field length stay meaningful and norms can stay on
 * where they matter; the prefix explosion is pushed into a separate hidden
 * field the scorer never consults. Config is one mapping line instead of a
 * custom analyser, filter and char_filter chain.
 *
 * Cannot do: the prefix index is still an index, so the disk and merge cost is
 * comparable to the edge-n-gram approach - this is a cleanliness and
 * correctness win, not a storage win. max_chars is capped at 19 by
 * Elasticsearch, so a longer prefix falls back to a real prefix scan (rare;
 * Belgian street tokens are short). And because the analysed terms are whole
 * words, its fuzzy fallback cannot recover a typo inside a half-typed token
 * the way the edge-n-gram index can - see
 * AbstractElasticsearchQuerySuggester::fuzzyGateClause().
 *
 * The question it answers: is the hand-built edge-n-gram analyser obsolete?
 * If this method matches the incumbent on quality and latency, the analyser
 * chain is 60 lines of settings that Elasticsearch will do for you in one.
 */
final class ElasticsearchPrefixesSuggester extends AbstractElasticsearchQuerySuggester
{
    private const ENGINE = 'es-prefixes';

    public function name(): string
    {
        return self::ENGINE;
    }

    /**
     * The gate, as one match_bool_prefix over the index_prefixes field.
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
        $field = 'search_text.prefixes';

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
            // The ordered signal. match_phrase_prefix needs real word terms at
            // real positions, which .prefixes has and the n-grammed parent does
            // not - and its trailing term is served from the prefix index rather
            // than expanded, so unlike the incumbent's equivalent clause this one
            // costs a term lookup instead of up to max_expansions of them.
            [
                'match_phrase_prefix' => [
                    'primary_name.prefixes' => [
                        'query' => $text,
                        'max_expansions' => self::NAME_MAX_EXPANSIONS,
                        'boost' => self::BOOST_NAME_PHRASE_PREFIX,
                    ],
                ],
            ],
            // The order-independent signal, carrying "gent kort" where the
            // phrase-prefix clause cannot fire. Left at its OR default on
            // purpose: this is a ranking clause, and requiring all tokens here
            // would silently duplicate the gate.
            [
                'match_bool_prefix' => [
                    'primary_name.prefixes' => [
                        'query' => $text,
                        'boost' => self::BOOST_NAME_MATCH,
                    ],
                ],
            ],
        ];
    }

    protected function fuzzyField(): string
    {
        return 'search_text.prefixes';
    }
}
