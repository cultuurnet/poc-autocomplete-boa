<?php

declare(strict_types=1);

namespace App\Suggest;

/**
 * The `search_as_you_type` field type - Elasticsearch's own answer.
 *
 * The method: one mapping line produces four indexed fields. The root is the
 * text analysed normally *plus* index_prefixes; `._2gram` and `._3gram` are
 * the same text passed through shingle filters, so consecutive word pairs and
 * triples become single terms; `._index_prefix` prefixes the 3-shingles. The
 * canonical query is a `bool_prefix` multi_match over the root and the two
 * shingle fields, which scores a document once per field (most_fields
 * semantics) and lets the shingles reward word *adjacency* without a phrase
 * query.
 *
 * Good at: multi-word prefix matching with zero analyser configuration, and
 * word-order awareness that comes for free - "korte linden" scores above
 * "linden korte" because the 2-shingle "korte linden" exists as a term. It is
 * the only method here where adjacency is an indexed fact rather than a
 * position calculation at query time.
 *
 * Cannot do: it is the most expensive index of the five. Three analysed fields
 * plus a prefix index on one of them, from a single source string, and there
 * is no way to ask for fewer without dropping to max_shingle_size 2. The
 * shingle fields also make relevance harder to reason about - a document can
 * score three times for the same evidence - which is why the ranking here
 * leans on the same explicit boosts as every other method rather than on the
 * multi_match's own field weighting. And like the other whole-word methods its
 * fuzzy fallback cannot rescue a typo in a half-typed token.
 *
 * Note on minimum_should_match: multi_match with type bool_prefix uses
 * most_fields semantics, so `minimum_should_match` is applied *inside* each
 * field's match_bool_prefix, not across the three fields. "100%" therefore
 * means "this field contains all of the typed tokens", and the top-level
 * should-of-three-fields still admits a document that satisfies any one of
 * them - which in practice is the root field. Without it the gate would
 * default to OR and stop gating; verified on the cluster.
 *
 * The question it answers: does the hand-built analysis chain earn its
 * complexity? This is the control. If the built-in type wins, the POC's answer
 * is "use the built-in and delete ElasticsearchMapping's analyser section".
 */
final class ElasticsearchSaytSuggester extends AbstractElasticsearchQuerySuggester
{
    private const ENGINE = 'es-sayt';

    /**
     * The gate, as one bool_prefix multi_match across the shingle family.
     *
     * Two shapes, not one, and the second is the one main added for the
     * incumbent: once the user has moved past the name ("goorbaan 5"), the
     * trailing locative token is a finished word, and a bool_prefix multi_match would still
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
        if ($prefix === null) {
            // The root search_as_you_type field is an ordinary analysed field;
            // the shingle subfields only matter once something is a prefix.
            return [['match' => ['search_text_sayt' => ['query' => implode(' ', $wholeWords), 'operator' => 'and']]]];
        }

        return [
            [
                'multi_match' => [
                    'query' => implode(' ', [...$wholeWords, $prefix]),
                    'type' => 'bool_prefix',
                    'fields' => self::GATE_FIELDS,
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
            // The ordered signal, against the root of the family. The root is an
            // ordinary analysed field carrying index_prefixes, so this behaves
            // exactly like the es-prefixes clause and is served from the prefix
            // index. Running it against the shingle subfields instead would
            // double-count adjacency, which the gate's _2gram/_3gram scoring
            // already rewards.
            [
                'match_phrase_prefix' => [
                    'primary_name_sayt' => [
                        'query' => $text,
                        'max_expansions' => self::NAME_MAX_EXPANSIONS,
                        'boost' => self::BOOST_NAME_PHRASE_PREFIX,
                    ],
                ],
            ],
            // The order-independent signal, in the shape the field type was
            // designed for. OR by default, as a ranking clause must be.
            [
                'multi_match' => [
                    'query' => $text,
                    'type' => 'bool_prefix',
                    'fields' => self::NAME_FIELDS,
                    'boost' => self::BOOST_NAME_MATCH,
                ],
            ],
        ];
    }

    /**
     * The root of the family, which is analysed with whole words. The shingle
     * subfields are deliberately not fuzzy-matched: an edit inside a shingle is
     * an edit inside a two- or three-word string, where AUTO's length thresholds
     * mean something quite different from what they mean on a word.
     */
    protected function fuzzyField(): string
    {
        return 'search_text_sayt';
    }
}
