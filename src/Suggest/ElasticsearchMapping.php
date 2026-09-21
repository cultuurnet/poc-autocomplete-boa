<?php

declare(strict_types=1);

namespace App\Suggest;

/**
 * The single definition of the Elasticsearch index: settings, analysers, mappings.
 *
 * The indexer creates the index from this, and the benchmark/docs render it, so
 * there is exactly one place where "what does the ES side actually do" is stated.
 * If this drifts from src/Support/Normalizer.php the comparison stops being fair,
 * because the two engines would then be indexing different text.
 */
final class ElasticsearchMapping
{
    /**
     * Edge n-grams from a single character, so the very first keystroke already
     * narrows the candidate set instead of falling through to a full scan.
     */
    public const MIN_GRAM = 1;

    /**
     * 20 covers almost every Belgian street token; the ones that do not fit
     * ("Kortrijksepoortstraat" is 21 characters) are rescued by preserve_original
     * below, so no token is ever unfindable by its full spelling.
     */
    public const MAX_GRAM = 20;

    /**
     * The search-side analyser. Named here because the suggester's behaviour is
     * only correct as long as every n-grammed field is *searched* with this one.
     */
    public const SEARCH_ANALYZER = 'folding';

    public const INDEX_ANALYZER = 'autocomplete_index';

    /**
     * Folding for keyword fields. A normaliser, not an analyser: it folds the
     * whole value into one term rather than tokenising it, which is what an
     * exact "the user typed the complete name" lookup needs.
     */
    public const KEYWORD_NORMALIZER = 'folding_keyword';

    /**
     * Full create-index body.
     *
     * @return array{settings: array<string, mixed>, mappings: array<string, mixed>}
     */
    public static function definition(): array
    {
        return [
            'settings' => self::settings(),
            'mappings' => self::mappings(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        return [
            'index' => [
                // Single-node POC: one shard means no cross-shard IDF skew (with
                // several shards the same query can score differently depending on
                // which shard a document landed in, which would show up as noise in
                // the MySQL comparison), and no replicas because there is nowhere to
                // put them and they would only slow the import down.
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
                // Only the *plain* ngram tokenizer/filter are gated on this setting,
                // not edge_ngram, but it is set explicitly so that swapping the
                // filter type during tuning does not fail index creation.
                'max_ngram_diff' => self::MAX_GRAM - self::MIN_GRAM,
            ],
            'analysis' => [
                'char_filter' => [
                    // Normalizer replaces every non-alphanumeric run with a space
                    // *before* tokenising, so "Rue de l'Église" becomes four tokens
                    // (rue, de, l, eglise). The standard tokenizer on its own keeps
                    // the apostrophe inside the word ("l'eglise"), which would leave
                    // the query side and the index side with different terms for
                    // every apostrophised French street name. This char filter puts
                    // them back in lockstep. It has to run before asciifolding and
                    // therefore must match on \p{L}/\p{N}, not on [a-z0-9], or it
                    // would eat the accented characters before they can be folded.
                    'strip_punctuation' => [
                        'type' => 'pattern_replace',
                        'pattern' => '[^\p{L}\p{N}]+',
                        'replacement' => ' ',
                    ],
                ],
                'filter' => [
                    'autocomplete_edge_ngram' => [
                        'type' => 'edge_ngram',
                        'min_gram' => self::MIN_GRAM,
                        'max_gram' => self::MAX_GRAM,
                        // Without this, a token longer than max_gram is only indexed
                        // as its first 20 characters and typing the whole word stops
                        // matching it - the exact opposite of what the user expects.
                        'preserve_original' => true,
                    ],
                ],
                'analyzer' => [
                    // lowercase + asciifolding is the ES equivalent of
                    // Normalizer::normalize(); asciifolding covers the FOLD map
                    // (including the multi-character ss/ae/oe expansions).
                    self::SEARCH_ANALYZER => [
                        'type' => 'custom',
                        'char_filter' => ['strip_punctuation'],
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase', 'asciifolding'],
                    ],
                    // Identical chain plus the edge n-grams. Index time only.
                    self::INDEX_ANALYZER => [
                        'type' => 'custom',
                        'char_filter' => ['strip_punctuation'],
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase', 'asciifolding', 'autocomplete_edge_ngram'],
                    ],
                ],
                'normalizer' => [
                    // The same folding, applied to a keyword field as a whole
                    // instead of per token: "Sint-Genesius-Rode" is stored as the
                    // single term "sint genesius rode", which is byte-for-byte
                    // what Normalizer::normalize() produces for the query side.
                    // That equality is the entire point - it is what lets the
                    // exact-name term lookup in the suggester ever hit.
                    // trim is needed because the char filter leaves a space
                    // behind for a leading or trailing punctuation character.
                    self::KEYWORD_NORMALIZER => [
                        'type' => 'custom',
                        'char_filter' => ['strip_punctuation'],
                        'filter' => ['lowercase', 'asciifolding', 'trim'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mappings(): array
    {
        return [
            // The indexer and this file are written together; anything else
            // appearing in a document means they have drifted, and a mapping
            // explosion during a 4.2M row import is far more expensive to
            // discover afterwards than a rejected bulk item is now.
            'dynamic' => 'strict',
            'properties' => [
                'id' => ['type' => 'keyword'],
                'doc_type' => ['type' => 'keyword'],

                // Display values. No query touches them - the searchable copy of
                // both lives in search_text and primary_name - so the analysed
                // index is pure cost.
                //
                // street_name keeps a keyword subfield: it is doc_values rather
                // than an inverted index, and it is what a collapse or a terms
                // aggregation on the street would read. label gets none - it is a
                // rendered string, so grouping or sorting on it means nothing that
                // grouping on street_name does not mean better, and at 82k
                // documents the subfield cost 3.8 MB of the index.
                'label' => self::storedText(),
                'street_name' => self::storedText(keyword: true),

                'municipality_name' => self::foldedText(),
                'post_name' => self::foldedText(),

                // Multi-valued: FR/DE translations and sub-localities. N-grammed
                // exactly like primary_name, and boosted by its own clause in the
                // suggester, so "Gand" ranks the document the way "Gent" does
                // instead of only scraping through the recall gate.
                'aliases' => self::autocompleteText(),

                // Keyword for the exact "2230" term lookup, plus an n-grammed text
                // subfield so a half-typed "22" is a cheap term match on an indexed
                // n-gram instead of a prefix scan over every distinct postcode.
                'postcode' => [
                    'type' => 'keyword',
                    'fields' => [
                        'text' => self::autocompleteText(),
                    ],
                ],

                // Kept verbatim (no normaliser) because they are display/identity
                // values, not search targets: house numbers reach the query through
                // search_text, which is already folded by SuggestionDocument. Not
                // indexed for the same reason - see the note on label above.
                'house_number' => ['type' => 'keyword', 'index' => false],
                'box_number' => ['type' => 'keyword', 'index' => false],
                'nis_code' => ['type' => 'keyword'],

                'location' => ['type' => 'geo_point'],
                'popularity' => ['type' => 'integer'],

                // The catch-all recall gate, mirroring the MySQL FULLTEXT column.
                // It is fed the already-deduplicated haystack from
                // SuggestionDocument::searchText(), so both engines see one string.
                //
                // The .folded subfield holds the same haystack tokenised into whole
                // words instead of edge n-grams. Type-ahead needs both: the token
                // the user is still typing is a prefix (parent field), every token
                // before it is a finished word (.folded). Gating a finished word
                // against the n-grammed parent is what made "gent kort" also match
                // Gentbrugge and Gentse.
                'search_text' => self::autocompleteText([
                    'folded' => [
                        'type' => 'text',
                        'analyzer' => self::SEARCH_ANALYZER,
                        'norms' => false,
                    ],
                ]),

                'primary_name' => [
                    'type' => 'text',
                    'analyzer' => self::INDEX_ANALYZER,
                    // THE critical line, repeated on every n-grammed field: if the
                    // query were also run through autocomplete_index, typing "gent"
                    // would search for g, ge, gen AND gent, so every street starting
                    // with "g" would match and precision would quietly collapse
                    // while the result set still looked plausible. Index n-grams,
                    // search whole words.
                    'search_analyzer' => self::SEARCH_ANALYZER,
                    // Edge n-grams make the token count proportional to the number
                    // of *characters*, so BM25's length normalisation would punish
                    // long street names for being long rather than for being a worse
                    // match. The length signal is kept on the .folded subfield,
                    // which is tokenised normally.
                    'norms' => false,
                    'fields' => [
                        // Folded, so the single term stored here is exactly what
                        // SuggestQuery::normalized() produces. Without the
                        // normaliser this subfield holds "Goorbaan" while the query
                        // side sends "goorbaan", and the exact-name term clause
                        // would silently never match anything.
                        'keyword' => [
                            'type' => 'keyword',
                            'normalizer' => self::KEYWORD_NORMALIZER,
                            'ignore_above' => 256,
                        ],
                        // No n-grams: this is what match_phrase_prefix and fuzzy
                        // matching need, because both want real word terms.
                        'folded' => [
                            'type' => 'text',
                            'analyzer' => self::SEARCH_ANALYZER,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Settings to hold for the duration of a bulk import.
     *
     * Refreshing every second while 4.2M documents stream in creates (and then
     * has to merge away) thousands of tiny segments; -1 defers all of that to
     * the single explicit refresh at the end.
     *
     * @return array{index: array<string, mixed>}
     */
    public static function importSettings(): array
    {
        return [
            'index' => [
                'refresh_interval' => '-1',
                'number_of_replicas' => 0,
            ],
        ];
    }

    /**
     * Settings to restore once the import is done, so the measured index behaves
     * like a normal near-real-time index rather than a frozen bulk target.
     *
     * @return array{index: array<string, mixed>}
     */
    public static function steadyStateSettings(): array
    {
        return [
            'index' => [
                'refresh_interval' => '1s',
            ],
        ];
    }

    /**
     * Plain analysed text with an exact-value subfield for aggregations/sorting.
     *
     * @return array<string, mixed>
     */
    private static function foldedText(): array
    {
        return [
            'type' => 'text',
            'analyzer' => self::SEARCH_ANALYZER,
            'fields' => [
                'keyword' => ['type' => 'keyword', 'ignore_above' => 256],
            ],
        ];
    }

    /**
     * Prefix-matchable text: n-grams at index time, whole words at search time.
     *
     * @param array<string, mixed> $fields optional subfields
     *
     * @return array<string, mixed>
     */
    private static function autocompleteText(array $fields = []): array
    {
        $mapping = [
            'type' => 'text',
            'analyzer' => self::INDEX_ANALYZER,
            'search_analyzer' => self::SEARCH_ANALYZER,
            'norms' => false,
        ];

        if ($fields !== []) {
            $mapping['fields'] = $fields;
        }

        return $mapping;
    }

    /**
     * Carried in _source and never searched.
     *
     * @param bool $keyword add the exact-value subfield, for aggregating,
     *                      sorting or collapsing - all of which read doc_values
     *                      rather than the inverted index
     *
     * @return array<string, mixed>
     */
    private static function storedText(bool $keyword = false): array
    {
        $mapping = ['type' => 'text', 'index' => false];

        if ($keyword) {
            $mapping['fields'] = [
                'keyword' => ['type' => 'keyword', 'ignore_above' => 256],
            ];
        }

        return $mapping;
    }
}
