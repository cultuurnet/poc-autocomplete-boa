<?php

declare(strict_types=1);

namespace App\Suggest;

/**
 * Edge n-grams at index time. The incumbent, and the baseline.
 *
 * The method: every token of every indexed field is expanded into all of its
 * prefixes (1..20 characters) at index time, and the query is analysed with
 * whole words only. "goorb" is then an ordinary term lookup against the
 * indexed prefix of "goorbaan" - no prefix expansion, no scan, the same cost
 * as looking up any other word.
 *
 * Good at: everything the other four are good at, plus the two things they are
 * not. It is the only method whose fuzzy fallback still works on a half-typed
 * token, because the n-grams are real terms that a fuzzy query can reach; and
 * it is the only one whose prefix matching survives inside a multi-token
 * out-of-order query on the catch-all field without any per-field setup.
 *
 * Cannot do: it pays for all of that in the index. Every token costs up to 20
 * terms, so the inverted index and the import time are several times what a
 * plain analysed field costs, and the term dictionary is full of one- and
 * two-character terms with enormous posting lists. It also permanently
 * distorts BM25 - term frequency and field length both become functions of how
 * many *characters* a document has - which is why norms are off on every
 * n-grammed field here and why scoring leans on the explicit boosts instead.
 *
 * The question it answers: what does the hand-built analysis chain buy, and is
 * anything in Elasticsearch 8 able to match it without paying for it? The
 * other four methods are measured against this one; it is not measured against
 * them.
 */
final class ElasticsearchSuggester extends AbstractElasticsearchQuerySuggester
{
    private const ENGINE = 'elasticsearch';

    public function name(): string
    {
        return self::ENGINE;
    }

    /**
     * search_text is n-grammed at index time and searched with whole words, so
     * "2230 goorb" becomes the two terms "2230" and "goorb", both of which have
     * to be found - "goorb" against the indexed prefix of "goorbaan". A plain
     * `match` with operator `and` is all that is needed: the prefix behaviour is
     * already baked into the index, which is precisely what distinguishes this
     * method from the three query-time ones.
     *
     * @return array<string, mixed>
     */
    protected function gateClause(string $text, bool $fuzzyFallback): array
    {
        if (!$fuzzyFallback) {
            return ['match' => ['search_text' => ['query' => $text, 'operator' => 'and']]];
        }

        return $this->fuzzyGateClause($text);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function nameClauses(string $text): array
    {
        return [
            // Strongest signal by a wide margin: the name reads as a continuation
            // of what was typed ("korte linden" -> "Korte Lindenstraat"). Run
            // against primary_name.folded, not the n-grammed parent, because a
            // phrase-prefix query needs real word terms at real positions -
            // against an n-gram index its trailing prefix expansion would be both
            // redundant and expensive.
            [
                'match_phrase_prefix' => [
                    'primary_name.folded' => [
                        'query' => $text,
                        'max_expansions' => self::NAME_MAX_EXPANSIONS,
                        'boost' => self::BOOST_NAME_PHRASE_PREFIX,
                    ],
                ],
            ],
            // Weaker, order-independent version of the same idea, on the
            // n-grammed field: it rewards a document for each typed word that
            // prefixes a word of its name, which is what carries "gent kort"
            // (where the phrase-prefix clause cannot fire because the city comes
            // first).
            [
                'match' => [
                    'primary_name' => [
                        'query' => $text,
                        'operator' => 'or',
                        'boost' => self::BOOST_NAME_MATCH,
                    ],
                ],
            ],
        ];
    }

    protected function fuzzyField(): string
    {
        return 'search_text';
    }
}
