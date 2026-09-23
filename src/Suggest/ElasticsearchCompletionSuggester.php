<?php

declare(strict_types=1);

namespace App\Suggest;

use App\Model\SuggestQuery;
use App\Model\SuggestResult;
use App\Model\Suggestion;

/**
 * The completion suggester: a finite state transducer, not a search.
 *
 * The method: the `suggest` field is not an inverted index at all. At index
 * time every input string is compiled into an in-memory FST keyed by the
 * characters of the string; at query time the typed prefix walks that automaton
 * and the arcs it reaches are the answers, each carrying the integer `weight`
 * stored with it. There is no scoring, no term statistics, no query - only a
 * walk and a weight.
 *
 * Good at: latency, by a margin nothing else here can approach. The structure
 * is held in memory, the walk is proportional to the length of what was typed
 * and not to the size of the corpus, and the result comes back already sorted.
 * This is the floor of the comparison - the fastest an autocomplete can
 * physically be on this data.
 *
 * What it structurally CANNOT do, all of which the other four can:
 *
 *  - Multi-token reordering. The FST matches from the start of an input
 *    string, so "gent kort" finds nothing: no input begins with "gent kort".
 *    Only prefixes of a whole indexed input match. Confirmed on the cluster -
 *    "gent" returns the Gent municipality document and not one of the streets
 *    inside it, even though every one of those streets has "gent" in its
 *    search text.
 *  - House numbers and address detail. Anything not compiled into an input
 *    string is invisible; there is nothing to match "12a" against.
 *  - A total. The suggest API returns the options it was asked for and no
 *    count, so `total` here is the number of rows returned and nothing more.
 *    The UI's "1000+" indicator is meaningless for this method.
 *  - Filtering. The only filter is the category context, which is why
 *    doc_type had to be indexed as one. Contexts are mandatory once declared:
 *    omitting them is an error, not an unfiltered search.
 *  - Ranking on match quality. Results are ordered by the indexed weight -
 *    popularity - full stop. A document whose input the user typed in full
 *    ranks below a more popular document that matched one character, and there
 *    is no boost, function or clause that can change that. It cannot be given
 *    the shared function_score wrapper the other four use, because there is no
 *    query for a function_score to wrap.
 *
 * Fuzziness is the one place where it has a native equivalent rather than a
 * gap: the completion suggester takes its own `fuzzy` option, which builds a
 * Levenshtein automaton and intersects it with the FST in a single pass. So
 * there is no two-pass fallback here and `passes` is always 1 - the fuzziness
 * is in the first and only request. Worth knowing when reading the debug
 * panel: enabling it also changes the absolute scale of the returned scores
 * (an exact prefix match came back at 4x its weight in testing), so scores are
 * only comparable within one fuzzy setting.
 *
 * The question it answers: how fast could this be if quality were free? It is
 * the latency floor, not a quality competitor, and a reader who sees it win on
 * milliseconds and lose on every query with two words in it has understood the
 * comparison correctly.
 */
final class ElasticsearchCompletionSuggester extends AbstractElasticsearchSuggester
{
    private const ENGINE = 'es-completion';

    /**
     * The named suggester inside the request. Only ever one, but the API keys
     * results by this name, so it is a constant rather than a literal in two
     * places.
     */
    private const SUGGESTER = 'suggestions';

    private const FIELD = 'suggest';

    public function name(): string
    {
        return self::ENGINE;
    }

    public function suggest(SuggestQuery $query): SuggestResult
    {
        if ($query->isEmpty()) {
            return new SuggestResult(self::ENGINE, [], 0.0, 0, ['skipped' => 'empty query']);
        }

        $body = $this->buildBody($query);

        $started = hrtime(true);
        $response = $this->client->search(['index' => $this->index, 'body' => $body])->asArray();
        $elapsedMs = (hrtime(true) - $started) / 1e6;

        $engineTookMs = (int) ($response['took'] ?? 0);

        $options = $this->options($response);
        $suggestions = $this->toCompletionSuggestions($options);

        return new SuggestResult(
            engine: self::ENGINE,
            suggestions: $suggestions,
            tookMs: $elapsedMs,
            // Not a total: the suggest API does not report one. This is the row
            // count, and the debug below says so rather than letting the UI
            // present it as the same number the other four methods report.
            total: count($suggestions),
            debug: [
                'index' => $this->index,
                'query' => $body,
                'es_took_ms' => $engineTookMs,
                'client_took_ms' => round($elapsedMs, 3),
                'overhead_ms' => round($elapsedMs - $engineTookMs, 3),
                // Always 1. Kept, with the same key the other four use, because
                // the point of the debug panel is that the methods can be read
                // side by side; a missing key reads as "unknown", a 1 reads as
                // "one round trip", which is the fact.
                'passes' => 1,
                // Always false: there is no second pass to fall back to. The
                // fuzziness, if any, is in the single request above.
                'fuzzy_fallback' => false,
                'fuzzy_native' => $query->fuzzy,
                'total_relation' => 'n/a',
                'track_total_hits' => null,
                'options_returned' => count($options),
                'duplicates_dropped' => count($options) - count($suggestions),
                'max_score' => $suggestions === [] ? null : $suggestions[0]->score,
                'timed_out' => (bool) ($response['timed_out'] ?? false),
                'note' => 'ranked by indexed weight (popularity) only; no total; '
                    . 'filtering via category context; matches from the start of an indexed input',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBody(SuggestQuery $query): array
    {
        $completion = [
            'field' => self::FIELD,
            'size' => max(1, $query->limit),
            // Off on purpose. skip_duplicates deduplicates on the suggestion
            // *text*, not on the document, so it would collapse the hundred
            // distinct "Kerkstraat" documents in a hundred different
            // municipalities into one row - a recall bug dressed as tidiness.
            // Same-document duplicates are dropped in PHP instead, where the
            // key is the document id.
            'skip_duplicates' => false,
            // Mandatory, not optional: a completion field that declares
            // contexts rejects a query that omits them. This is also the only
            // filtering the method has - there is no bool/filter here.
            'contexts' => [
                'doc_type' => $query->typeValues(),
            ],
        ];

        if ($query->fuzzy) {
            // The native equivalent of the other four methods' fallback pass,
            // and structurally better: one automaton intersection instead of a
            // second round trip. min_length 3 is the suggester's own guard
            // against fuzzing a one-character prefix into most of the FST;
            // prefix_length 1 pins the first character, matching the
            // prefix_length the query methods use.
            $completion['fuzzy'] = [
                'fuzziness' => 'AUTO',
                'prefix_length' => 1,
                'transpositions' => true,
                'min_length' => 3,
                'unicode_aware' => false,
            ];
        }

        return [
            // The suggest phase and the query phase are independent; asking for
            // zero hits stops Elasticsearch running an empty match_all search
            // alongside the FST walk and charging this method for it.
            'size' => 0,
            // _source filtering does apply to completion options, so the same
            // fields come back as for the other four and Suggestion is built
            // from the same data.
            '_source' => self::SOURCE_FIELDS,
            'suggest' => [
                self::SUGGESTER => [
                    // Locative tokens only, for the same reason the four query
                    // methods gate on them: an indexed input is a name, never a
                    // house number, so feeding "kerkstraat 12" to the FST walks
                    // it straight off the end of the automaton and returns
                    // nothing. This gives the method the best prefix it could
                    // possibly be asked for - it still cannot rank on the number
                    // it just dropped, which is a property of the method and one
                    // of the things the comparison is meant to show.
                    'prefix' => implode(' ', $query->locativeTokens()),
                    'completion' => $completion,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return list<array<string, mixed>>
     */
    private function options(array $response): array
    {
        $entries = $response['suggest'][self::SUGGESTER] ?? [];
        $options = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            foreach (is_array($entry['options'] ?? null) ? $entry['options'] : [] as $option) {
                if (is_array($option)) {
                    $options[] = $option;
                }
            }
        }

        return $options;
    }

    /**
     * Completion options to Suggestions, one document at a time.
     *
     * A document is indexed with several inputs (the name plus its FR/DE
     * aliases), and the suggester counts *options*, not documents, so a
     * document that matched under two of its inputs occupies two of the ten
     * slots. Dropping the duplicate is obviously right; refilling the freed
     * slot by over-fetching is not, and is not done here - it would be a
     * correction the other four methods do not get, and it would hide the fact
     * that es-completion can answer a ten-row request with seven rows. That is
     * a real property of the method and belongs in the result, not in a
     * workaround.
     *
     * @param list<array<string, mixed>> $options
     *
     * @return list<Suggestion>
     */
    private function toCompletionSuggestions(array $options): array
    {
        $suggestions = [];
        $seen = [];

        foreach ($options as $option) {
            $id = (string) ($option['_id'] ?? '');

            if ($id !== '' && isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            // `text` is the indexed input that matched, which for this method is
            // the whole of the "why this result" explanation - there is no
            // _explanation to ask for, because there was no scoring.
            $suggestions[] = $this->toSuggestion($option, [
                '_matched_input' => (string) ($option['text'] ?? ''),
                '_score_is' => 'indexed weight (popularity), not match quality',
            ]);
        }

        return $suggestions;
    }
}
