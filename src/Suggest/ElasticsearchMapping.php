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
 *
 * ## Why one index carries five autocomplete methods
 *
 * The index-time n-gram field below (`search_text` / `primary_name`) is only one
 * of the ways Elasticsearch can answer a prefix query; the point of this POC is
 * to find out which of them is actually the best fit for Belgian addresses. The
 * only honest way to compare them is to run them over the *same* documents, in
 * the same index, on the same hardware, on the same day - so instead of building
 * five indices that would immediately drift apart, every method gets its own
 * field here and shares one set of documents:
 *
 *   - `search_text` / `primary_name`   index-time edge n-grams (the current one)
 *   - `*.prefixes`                     index_prefixes, ES's built-in prefix index
 *   - `*_sayt`                         search_as_you_type (n-grams + shingles)
 *   - `suggest`                        the completion suggester (FST in memory)
 *   - (no field)                       plain match_phrase_prefix, query-time only
 *
 * That is deliberately paid for in disk: the extra fields roughly double the
 * postings this index has to hold, and the completion field additionally wants
 * its FST resident in heap on every node. A production deployment would keep
 * exactly one of them. The cost is not a guess either - it is attributable per
 * field with `POST /location_suggestions/_disk_usage?run_expensive_tasks=true`,
 * which walks the segments and reports stored/doc-values/postings bytes for each
 * field separately, so the README can quote what each method really costs.
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
     * Lower bound of the `index_prefixes` range on the `.prefixes` subfields.
     * 1 for the same reason as MIN_GRAM: the first keystroke must already be a
     * term lookup, not a scan.
     */
    public const PREFIX_MIN_CHARS = 1;

    /**
     * Upper bound of `index_prefixes`. 19 is the hard ceiling Elasticsearch
     * enforces (max_chars must be < 20), not a tuning choice - and it is also
     * why this method has no equivalent of the MAX_GRAM=20 cliff: above the
     * ceiling, index_prefixes does not stop matching, it silently falls back to
     * an ordinary prefix query on the field's real terms. So a 21-character
     * "Kortrijksepoortstraat" is still findable by its full spelling *without*
     * the preserve_original workaround the edge-n-gram filter needs, it just
     * costs a term scan for those rare long prefixes instead of a term lookup.
     * That difference - a graceful slowdown versus a correctness patch - is one
     * of the things the comparison is meant to surface.
     */
    public const PREFIX_MAX_CHARS = 19;

    /**
     * search_as_you_type builds a shingle subfield per size, so 3 means the
     * field silently becomes four Lucene fields (root, _2gram, _3gram,
     * _index_prefix). 3 is the ES default and covers "sint pieters nieuwstraat"
     * style multi-word prefixes; raising it buys little here because Belgian
     * address queries are short, and every increment is another postings list.
     */
    public const SAYT_MAX_SHINGLE_SIZE = 3;

    /**
     * Completion inputs are truncated to this many characters at index time.
     * 50 comfortably clears the longest Belgian street name, and keeping it low
     * matters more than usual: the completion index is an FST that has to be
     * loaded into heap in full, so every extra character is resident memory on
     * a node rather than bytes on a disk.
     */
    public const COMPLETION_MAX_INPUT_LENGTH = 50;

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
                //
                // .prefixes is the alternative-method subfield and changes nothing
                // about the two above: the root field still analyses and scores
                // exactly as it did, so the `elasticsearch` method's queries and
                // results are unaffected by it being here.
                'search_text' => self::autocompleteText([
                    'folded' => [
                        'type' => 'text',
                        'analyzer' => self::SEARCH_ANALYZER,
                        'norms' => false,
                    ],
                    'prefixes' => self::prefixIndexedText(),
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
                        // Method 2 of 5. Same source text as the n-grammed root
                        // field, a completely different way of storing it.
                        'prefixes' => self::prefixIndexedText(),
                    ],
                ],

                // Method 3 of 5: search_as_you_type. Two top-level fields rather
                // than subfields of primary_name/search_text, because ES refuses
                // search_as_you_type inside `fields` - it is not a single Lucene
                // field but a small family of them (root, _2gram, _3gram,
                // _index_prefix) that the type generates for itself, and a
                // multi-field is not allowed to fan out like that. Hence the
                // `_sayt` suffix instead of `primary_name.sayt`; the indexer has
                // to emit the same text twice, once per field.
                //
                // Both are given the folding analyser rather than the default
                // `standard` so this method sees the same folded, punctuation-
                // stripped tokens as every other method. "Rue de l'Église" has
                // to tokenise identically everywhere or the comparison measures
                // analysis differences instead of method differences.
                'primary_name_sayt' => self::searchAsYouType(),
                'search_text_sayt' => self::searchAsYouType(),

                // Method 4 of 5: the completion suggester. Structurally unlike
                // the others - it is not a query at all but an in-memory FST
                // consulted through the _search `suggest` section, which is why
                // it is fast and why it is inflexible.
                'suggest' => [
                    'type' => 'completion',
                    // Folding again, for the same reason as above. It applies to
                    // both the stored inputs and the typed prefix.
                    'analyzer' => self::SEARCH_ANALYZER,
                    // Keep the whitespace boundary significant, so "gent brug"
                    // cannot complete out of "gentbrugge". Dropping separators
                    // would raise recall on typo-ish input, but this POC is
                    // comparing precision at the top of a 5-row dropdown.
                    'preserve_separators' => true,
                    'max_input_length' => self::COMPLETION_MAX_INPUT_LENGTH,
                    // The suggest API cannot take a query, a filter, or a
                    // post_filter - it only walks the FST - so the type filter
                    // that every other method expresses as a `term` clause has
                    // to be baked into the index as a category context. `path`
                    // makes it read the value straight off the document's own
                    // doc_type field, so the indexer does not have to duplicate
                    // it into the suggest object.
                    //
                    // No geo context here on purpose, even though distance
                    // ranking would suit an address autocomplete: a geo context
                    // is indexed per input, and once a query supplies a geo
                    // context, documents indexed without one are simply not in
                    // the candidate set. Our corpus has documents with no
                    // coordinates at all (municipalities and postcodes, mostly),
                    // and quietly making those unreachable would corrupt the
                    // recall figures this whole exercise exists to produce.
                    'contexts' => [
                        [
                            'name' => 'doc_type',
                            'type' => 'category',
                            'path' => 'doc_type',
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

    /**
     * Plain folded text with Lucene's own prefix index switched on.
     *
     * The n-gram approach above expands one token into up to 20 terms in the
     * main postings list, which is what forces the search_analyzer split, the
     * disabled norms and the preserve_original rescue. index_prefixes does the
     * same job as a *side* index maintained by Lucene: the field's own terms
     * stay whole (so scoring, norms and phrase queries still behave like normal
     * text), and a `prefix` query is transparently rewritten against the hidden
     * prefix field. That also means there is no index/search analyser asymmetry
     * to get wrong - one analyser, both sides.
     *
     * @return array<string, mixed>
     */
    private static function prefixIndexedText(): array
    {
        return [
            'type' => 'text',
            'analyzer' => self::SEARCH_ANALYZER,
            'index_prefixes' => [
                'min_chars' => self::PREFIX_MIN_CHARS,
                'max_chars' => self::PREFIX_MAX_CHARS,
            ],
        ];
    }

    /**
     * A search_as_you_type field: edge n-grams plus word shingles.
     *
     * The interesting difference from every other method here is that this one
     * indexes *word order*. The shingle subfields turn "sint pieters" into a
     * single term, so a multi-word prefix is a term lookup rather than a phrase
     * query over positions - which is exactly the case our long Flemish street
     * names hit constantly, and exactly where match_phrase_prefix gets slow.
     * The price is the four Lucene fields it silently creates per declaration.
     *
     * @return array<string, mixed>
     */
    private static function searchAsYouType(): array
    {
        return [
            'type' => 'search_as_you_type',
            'analyzer' => self::SEARCH_ANALYZER,
            'max_shingle_size' => self::SAYT_MAX_SHINGLE_SIZE,
        ];
    }
}
