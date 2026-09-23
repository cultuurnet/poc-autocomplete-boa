<?php

declare(strict_types=1);

namespace App\Command;

use App\Container;
use App\Model\SuggestQuery;
use App\Model\Suggestion;
use App\Suggest\SuggesterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Compares every registered autocomplete method on the same queries against the
 * same data.
 *
 * Two questions, because either one alone is misleading: how fast is it, and is
 * the answer any good. A fast engine that ranks "Kerkstraat, 2060 Antwerpen"
 * above "Kerkstraat, 9050 Gentbrugge" for the query "kerkstraat gent" is not a
 * usable autocomplete, and a perfectly ranked engine that takes 400 ms is not
 * an autocomplete at all.
 *
 * Quality is measured twice. Engine agreement needs no ground truth and covers
 * the whole query set: wherever two engines disagree is where a human has to
 * look. The golden set is hand-curated ground truth over a couple of dozen
 * known-item queries and is the only measure that can say which engine is
 * right rather than merely different.
 *
 * Agreement is pairwise by nature, and with six methods the full matrix is
 * fifteen pairs -- unreadable, and mostly answering questions nobody asked.
 * Instead every selected engine is compared against *one* baseline, the first
 * one selected, which turns the matrix into one row per engine and makes the
 * question concrete: "how far does this method drift from the thing it would
 * replace?". Selection order is preserved precisely so the caller picks that
 * baseline: the default --engine=all starts at mysql, so every method is scored
 * against the store we are trying to move off; --engine=elasticsearch,es-sayt
 * instead scores the candidate against the incumbent ES mapping, which is the
 * comparison that matters once MySQL is out of the picture.
 */
#[AsCommand(
    name: 'benchmark',
    description: 'Benchmark the autocomplete methods against each other on latency and result quality',
)]
final class BenchmarkCommand extends Command
{
    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('queries', null, InputOption::VALUE_REQUIRED, 'Query file, one query per line', 'benchmark/queries.txt')
            ->addOption('golden', null, InputOption::VALUE_REQUIRED, 'Curated expectations, JSON', 'benchmark/golden.json')
            ->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'Timed runs per query per engine', '20')
            ->addOption('warmup', null, InputOption::VALUE_REQUIRED, 'Discarded runs per query per engine', '5')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Suggestions requested per query', '10')
            ->addOption(
                'engine',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'all|es|<method>, comma separated; the first one is the agreement baseline (methods: %s)',
                    implode(', ', $this->container->methodKeys()),
                ),
                'all',
            )
            ->addOption('no-fuzzy', null, InputOption::VALUE_NONE, 'Disable fuzzy matching on every engine')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'table|json|csv', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');

        if (!in_array($format, ['table', 'json', 'csv'], true)) {
            (new SymfonyStyle($input, $output))->error('Unknown --format, expected table|json|csv.');

            return Command::INVALID;
        }

        // json and csv go to stdout so the run can be redirected to a file;
        // everything human-readable then has to go to stderr or it corrupts it.
        $statusOutput = $format === 'table' || !$output instanceof ConsoleOutputInterface
            ? $output
            : $output->getErrorOutput();
        $io = new SymfonyStyle($input, $statusOutput);

        $iterations = max(1, (int) $input->getOption('iterations'));
        $warmup = max(0, (int) $input->getOption('warmup'));
        $limit = max(1, (int) $input->getOption('limit'));
        $fuzzy = !$input->getOption('no-fuzzy');

        try {
            $queries = $this->loadQueries((string) $input->getOption('queries'));
            $golden = $this->loadGolden((string) $input->getOption('golden'));
            $engines = $this->selectedEngines((string) $input->getOption('engine'));
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if ($queries === []) {
            $io->error('The query file contains no queries.');

            return Command::INVALID;
        }

        /** @var array<string, string> $unavailable */
        $unavailable = [];
        $suggesters = $this->buildSuggesters($engines, $unavailable);

        foreach ($unavailable as $engine => $reason) {
            $io->warning(sprintf('%s is unavailable: %s', $engine, $reason));
        }

        if ($suggesters === []) {
            $io->error('No engine is available; nothing to benchmark.');

            return Command::FAILURE;
        }

        if ($format === 'table') {
            $io->title('Benchmark');
            $io->definitionList(
                ['engines' => implode(', ', array_keys($suggesters))],
                // Spelled out because it is a silent consequence of the order
                // of --engine, and because an engine that dropped out as
                // unavailable moves it to the next one still standing.
                ['agreement baseline' => count($suggesters) > 1
                    ? (string) array_key_first($suggesters)
                    : 'n/a (needs two engines)'],
                ['queries' => sprintf('%d from %s', count($queries), (string) $input->getOption('queries'))],
                ['golden entries' => sprintf('%d from %s', count($golden), (string) $input->getOption('golden'))],
                ['iterations' => sprintf('%d timed, %d warmup', $iterations, $warmup)],
                ['limit' => (string) $limit],
                ['fuzzy' => $fuzzy ? 'on' : 'off'],
            );
        }

        /** @var array<string, array<string, list<float>>> $samples engine => query => tookMs */
        $samples = [];
        /** @var array<string, array<string, list<Suggestion>>> $captured engine => query => suggestions */
        $captured = [];
        /** @var array<string, int> $errors */
        $errors = array_fill_keys(array_keys($suggesters), 0);

        $this->measure($io, $suggesters, $queries, $iterations, $warmup, $limit, $fuzzy, $samples, $captured, $errors);

        // Golden queries that are not part of the latency set still need one
        // result each; they are scored, not timed, so one run is enough.
        $goldenQueries = array_values(array_unique(array_map(
            static fn (array $entry): string => $entry['query'],
            $golden,
        )));
        $this->captureOnly($suggesters, array_values(array_diff($goldenQueries, $queries)), $limit, $fuzzy, $captured, $errors);

        $latency = $this->latencyReport($samples);
        $agreement = $this->agreementReport($queries, $captured, $limit);
        $quality = $this->goldenReport($golden, $captured, array_keys($suggesters));

        $payload = [
            'config' => [
                'engines' => array_keys($suggesters),
                'queries' => count($queries),
                'iterations' => $iterations,
                'warmup' => $warmup,
                'limit' => $limit,
                'fuzzy' => $fuzzy,
                'percentile_method' => 'linear interpolation between closest ranks (R-7, the numpy/Excel PERCENTILE.INC default)',
            ],
            'unavailable' => $unavailable,
            'errors' => $errors,
            'latency' => $latency,
            'agreement' => $agreement,
            'golden' => $quality,
            'verdict' => $this->verdict($latency, $quality),
        ];

        match ($format) {
            'json' => $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'csv' => $this->writeCsv($output, $payload),
            default => $this->writeTables($io, $output, $payload, $limit),
        };

        return $unavailable === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Warm up, then time.
     *
     * The warmup runs are thrown away on purpose: the first call into either
     * engine pays for opcache/JIT warmup on our side, connection setup, the
     * MySQL query plan cache and buffer pool, the Elasticsearch query cache and
     * segment file handles, and the OS page cache for both. None of that is
     * representative of an autocomplete endpoint under real traffic, where
     * those caches are permanently warm.
     *
     * The timed loop alternates the engines on every single query rather than
     * running one engine's whole set and then the next one's. A GC pause, a
     * noisy neighbour on the host or a background merge then lands on all
     * engines roughly equally instead of being charged entirely to whichever
     * one happened to be running at the time. That matters more with six
     * engines than with two: the run is three times longer, so there is three
     * times as much opportunity for the machine to change underneath it.
     *
     * @param array<string, SuggesterInterface>            $suggesters
     * @param list<string>                                 $queries
     * @param array<string, array<string, list<float>>>    $samples
     * @param array<string, array<string, list<Suggestion>>> $captured
     * @param array<string, int>                           $errors
     */
    private function measure(
        SymfonyStyle $io,
        array $suggesters,
        array $queries,
        int $iterations,
        int $warmup,
        int $limit,
        bool $fuzzy,
        array &$samples,
        array &$captured,
        array &$errors,
    ): void {
        foreach (array_keys($suggesters) as $engine) {
            $samples[$engine] = [];
            $captured[$engine] = [];
        }

        $bar = $io->createProgressBar(count($queries) * ($warmup + $iterations) * count($suggesters));
        $bar->setFormat(' %current%/%max% calls [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $bar->start();

        foreach ($queries as $query) {
            foreach ($suggesters as $suggester) {
                for ($i = 0; $i < $warmup; ++$i) {
                    try {
                        $suggester->suggest(new SuggestQuery($query, $limit, [], $fuzzy));
                    } catch (Throwable) {
                        // Counted below, during the timed runs.
                    }

                    $bar->advance();
                }
            }
        }

        for ($i = 0; $i < $iterations; ++$i) {
            foreach ($queries as $query) {
                foreach ($suggesters as $engine => $suggester) {
                    try {
                        $result = $suggester->suggest(new SuggestQuery($query, $limit, [], $fuzzy));
                    } catch (Throwable $e) {
                        ++$errors[$engine];
                        $this->recordError($engine, $query, $e);
                        $bar->advance();

                        continue;
                    }

                    // tookMs is what the suggester itself measured, which is the
                    // number the API would report; it excludes our own loop.
                    $samples[$engine][$query][] = $result->tookMs;
                    $captured[$engine][$query] = $result->suggestions;
                    $bar->advance();
                }
            }
        }

        $bar->finish();
        $io->newLine(2);
    }

    /**
     * A benchmark that hides its failures is worse than no benchmark: a query
     * that throws silently looks identical to one that is merely slow. Keep the
     * distinct messages (and one example query each) so the summary can say
     * what actually went wrong instead of only how often.
     *
     * @var array<string, array<string, array{count: int, query: string}>>
     */
    private array $errorMessages = [];

    private function recordError(string $engine, string $query, Throwable $e): void
    {
        $key = $e::class . ': ' . $e->getMessage();

        if (isset($this->errorMessages[$engine][$key])) {
            ++$this->errorMessages[$engine][$key]['count'];

            return;
        }

        $this->errorMessages[$engine][$key] = ['count' => 1, 'query' => $query];
    }

    /**
     * One untimed run per query, purely to have a result list to score.
     *
     * @param array<string, SuggesterInterface>              $suggesters
     * @param list<string>                                   $queries
     * @param array<string, array<string, list<Suggestion>>> $captured
     * @param array<string, int>                             $errors
     */
    private function captureOnly(
        array $suggesters,
        array $queries,
        int $limit,
        bool $fuzzy,
        array &$captured,
        array &$errors,
    ): void {
        foreach ($queries as $query) {
            foreach ($suggesters as $engine => $suggester) {
                try {
                    $captured[$engine][$query] = $suggester->suggest(new SuggestQuery($query, $limit, [], $fuzzy))->suggestions;
                } catch (Throwable $e) {
                    ++$errors[$engine];
                    $this->recordError($engine, $query, $e);
                }
            }
        }
    }

    /**
     * @param array<string, array<string, list<float>>> $samples
     *
     * @return array{overall: array<string, array<string, float|int>>, per_query: array<string, array<string, array<string, float|int>>>}
     */
    private function latencyReport(array $samples): array
    {
        $overall = [];
        $perQuery = [];

        foreach ($samples as $engine => $byQuery) {
            $pooled = [];

            foreach ($byQuery as $query => $values) {
                $pooled = [...$pooled, ...$values];
                $perQuery[$engine][$query] = $this->summary($values);
            }

            // Every query contributes the same number of samples, so pooling
            // weights the queries equally -- no query dominates the aggregate
            // just by being in the file more often.
            $overall[$engine] = $this->summary($pooled);
        }

        return ['overall' => $overall, 'per_query' => $perQuery];
    }

    /**
     * @param list<float> $values
     *
     * @return array<string, float|int>
     */
    private function summary(array $values): array
    {
        if ($values === []) {
            return ['n' => 0, 'min' => 0.0, 'p50' => 0.0, 'p90' => 0.0, 'p95' => 0.0, 'p99' => 0.0, 'max' => 0.0, 'mean' => 0.0, 'stddev' => 0.0];
        }

        sort($values);

        $n = count($values);
        $mean = array_sum($values) / $n;
        $variance = 0.0;

        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return [
            'n' => $n,
            'min' => $values[0],
            'p50' => $this->percentile($values, 50),
            'p90' => $this->percentile($values, 90),
            'p95' => $this->percentile($values, 95),
            'p99' => $this->percentile($values, 99),
            'max' => $values[$n - 1],
            'mean' => $mean,
            'stddev' => $n > 1 ? sqrt($variance / ($n - 1)) : 0.0,
        ];
    }

    /**
     * Percentile by linear interpolation between closest ranks (method R-7,
     * the default in numpy.percentile and Excel's PERCENTILE.INC).
     *
     * Nearest-rank would be defensible too, but with 20 iterations it snaps p95
     * and p99 onto the same single sample, which makes the tail look like it
     * has more resolution than it does. Interpolation at least degrades
     * smoothly. Either way: with 20 iterations p99 is an extrapolation of the
     * top two samples, so raise --iterations before quoting it.
     *
     * @param list<float> $sorted ascending, non-empty
     */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);

        if ($n === 1) {
            return $sorted[0];
        }

        $rank = ($p / 100) * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);

        if ($low === $high) {
            return $sorted[$low];
        }

        return $sorted[$low] + ($rank - $low) * ($sorted[$high] - $sorted[$low]);
    }

    /**
     * How far each engine drifts from the baseline, per query.
     *
     * No ground truth needed, which is what makes this measure worth having:
     * it covers every query in the file, not just the couple of dozen someone
     * had the patience to curate. It cannot tell you who is right, only where
     * to look.
     *
     * Star-shaped rather than all-pairs. Fifteen pairwise comparisons over six
     * engines is a matrix nobody reads, and the interesting question is not
     * "how much do es-sayt and es-completion resemble each other" but "how far
     * has each candidate moved from the thing it is replacing". The baseline is
     * the first engine that actually ran, i.e. the first one named in --engine
     * minus any that turned out to be unavailable -- so the default `all` keeps
     * measuring everything against MySQL exactly as this report always did,
     * while `--engine=elasticsearch,es-prefixes,es-sayt` re-centres it on the
     * incumbent ES mapping without needing a second option to say so.
     *
     * With a single engine there is no baseline to compare against and every
     * aggregate would be a division by zero; the report comes back empty and
     * the renderers say so rather than printing zeroes that look like total
     * disagreement.
     *
     * @param list<string>                                   $queries
     * @param array<string, array<string, list<Suggestion>>> $captured
     *
     * @return array{baseline: string|null, engines: list<string>, comparable: int, per_engine: array<string, array<string, mixed>>, per_query: list<array<string, mixed>>}
     */
    private function agreementReport(array $queries, array $captured, int $k): array
    {
        $engines = array_keys($captured);
        $baseline = $engines === [] ? null : (string) $engines[0];
        $compared = array_values(array_slice($engines, 1));

        $rows = [];
        $perEngine = [];
        /** @var array<string, true> $comparableQueries */
        $comparableQueries = [];

        foreach ($compared as $engine) {
            $overlaps = [];
            $jaccards = [];
            $spearmans = [];
            $sameTop1 = 0;

            foreach ($queries as $query) {
                $left = $this->ids($captured[(string) $baseline][$query] ?? []);
                $right = $this->ids($captured[$engine][$query] ?? []);

                // Neither side has an opinion: counting that as perfect
                // agreement would let a pair of broken engines score 1.000.
                if ($left === [] && $right === []) {
                    continue;
                }

                $topK = array_slice($left, 0, $k);
                $topKOther = array_slice($right, 0, $k);
                $shared = array_values(array_intersect($topK, $topKOther));
                $union = array_values(array_unique([...$topK, ...$topKOther]));

                $overlap = count($shared);
                $jaccard = $union === [] ? 0.0 : $overlap / count($union);
                $spearman = $this->spearman($topK, $topKOther);
                $identical = ($left[0] ?? null) !== null && ($left[0] ?? null) === ($right[0] ?? null);

                if ($identical) {
                    ++$sameTop1;
                }

                $overlaps[] = $overlap;
                $jaccards[] = $jaccard;

                if ($spearman !== null) {
                    $spearmans[] = $spearman;
                }

                $comparableQueries[$query] = true;

                $rows[] = [
                    'engine' => $engine,
                    'query' => $query,
                    'overlap' => $overlap,
                    'k' => min($k, max(count($topK), count($topKOther))),
                    'jaccard' => $jaccard,
                    'spearman' => $spearman,
                    'same_top1' => $identical,
                    'baseline_top1' => $this->label($captured[(string) $baseline][$query] ?? []),
                    'top1' => $this->label($captured[$engine][$query] ?? []),
                ];
            }

            $perEngine[$engine] = [
                'engine' => $engine,
                'comparable' => count($overlaps),
                'mean_overlap' => $overlaps === [] ? 0.0 : array_sum($overlaps) / count($overlaps),
                'mean_jaccard' => $jaccards === [] ? 0.0 : array_sum($jaccards) / count($jaccards),
                // null, not 0.0: "no two shared ids anywhere, so rank
                // correlation is undefined" is a different statement from
                // "the rankings are uncorrelated".
                'mean_spearman' => $spearmans === [] ? null : array_sum($spearmans) / count($spearmans),
                'identical_top1' => $sameTop1,
            ];
        }

        return [
            'baseline' => $baseline,
            'engines' => $compared,
            // Distinct queries, not rows: with five compared engines the row
            // count is five times the number of queries, which would make
            // "queries compared" nonsense in the header.
            'comparable' => count($comparableQueries),
            'per_engine' => $perEngine,
            'per_query' => $rows,
        ];
    }

    /**
     * Spearman rank correlation over the ids both engines returned.
     *
     * Only shared ids can be correlated, and they are re-ranked 1..n within
     * that shared subset so both lists rank the same items. There are no ties,
     * so the closed-form 1 - 6*sum(d^2)/(n*(n^2-1)) is exact. Fewer than two
     * shared ids means there is nothing to correlate.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private function spearman(array $a, array $b): ?float
    {
        $shared = array_values(array_intersect($a, $b));

        if (count($shared) < 2) {
            return null;
        }

        $rankA = array_flip(array_values(array_intersect($a, $shared)));
        $rankB = array_flip(array_values(array_intersect($b, $shared)));

        $n = count($shared);
        $sumD2 = 0;

        foreach ($shared as $id) {
            $sumD2 += ($rankA[$id] - $rankB[$id]) ** 2;
        }

        return 1 - (6 * $sumD2) / ($n * ($n ** 2 - 1));
    }

    /**
     * Score both engines against the curated expectations.
     *
     * An entry is a hit at k when any of its expectations matches any of the
     * top k suggestions -- the expectations are alternatives ("either of these
     * two answers is correct"), not a set that all has to be retrieved.
     *
     * @param list<array{query: string, expect: list<string>, note: string}> $golden
     * @param array<string, array<string, list<Suggestion>>>                 $captured
     * @param list<string>                                                   $engines
     *
     * @return array{per_engine: array<string, array<string, float|int>>, per_entry: list<array<string, mixed>>}
     */
    private function goldenReport(array $golden, array $captured, array $engines): array
    {
        $perEngine = [];
        $entries = [];

        foreach ($engines as $engine) {
            $perEngine[$engine] = ['entries' => count($golden), 'hit@1' => 0, 'hit@3' => 0, 'hit@10' => 0, 'mrr' => 0.0];
        }

        foreach ($golden as $entry) {
            $row = ['query' => $entry['query'], 'note' => $entry['note'], 'expect' => $entry['expect'], 'engines' => []];

            foreach ($engines as $engine) {
                $suggestions = $captured[$engine][$entry['query']] ?? [];
                $rank = $this->firstMatchRank($entry['expect'], $suggestions);

                if ($rank !== null) {
                    if ($rank <= 1) {
                        ++$perEngine[$engine]['hit@1'];
                    }

                    if ($rank <= 3) {
                        ++$perEngine[$engine]['hit@3'];
                    }

                    if ($rank <= 10) {
                        ++$perEngine[$engine]['hit@10'];
                    }

                    $perEngine[$engine]['mrr'] += 1 / $rank;
                }

                $row['engines'][$engine] = [
                    'rank' => $rank,
                    'top1' => $this->label($suggestions),
                ];
            }

            $entries[] = $row;
        }

        foreach ($engines as $engine) {
            $perEngine[$engine]['mrr'] = $golden === [] ? 0.0 : $perEngine[$engine]['mrr'] / count($golden);
        }

        return ['per_engine' => $perEngine, 'per_entry' => $entries];
    }

    /**
     * 1-based rank of the first suggestion that satisfies any expectation.
     *
     * Matching rule, also documented in benchmark/golden.json so the file is
     * self-explanatory: an expectation that starts with a known document type
     * prefix is an exact document id, anything else is a case-insensitive
     * substring of the suggestion label.
     *
     * @param list<string>     $expectations
     * @param list<Suggestion> $suggestions
     */
    private function firstMatchRank(array $expectations, array $suggestions): ?int
    {
        foreach ($suggestions as $index => $suggestion) {
            foreach ($expectations as $expectation) {
                $isId = preg_match('/^(address|street|municipality|postcode):/', $expectation) === 1;

                $matched = $isId
                    ? $suggestion->id === $expectation
                    : str_contains(mb_strtolower($suggestion->label), mb_strtolower($expectation));

                if ($matched) {
                    return $index + 1;
                }
            }
        }

        return null;
    }

    /**
     * @param array{overall: array<string, array<string, float|int>>, per_query: array<string, mixed>} $latency
     * @param array{per_engine: array<string, array<string, float|int>>, per_entry: list<mixed>}       $quality
     *
     * @return array<string, mixed>
     */
    private function verdict(array $latency, array $quality): array
    {
        $verdict = [];
        $overall = $latency['overall'];

        if ($overall !== []) {
            $byP50 = $overall;
            uasort($byP50, static fn (array $x, array $y): int => $x['p50'] <=> $y['p50']);
            $fast = (string) array_key_first($byP50);
            $slow = (string) array_key_last($byP50);
            $fastP50 = (float) $overall[$fast]['p50'];
            $fastP95 = (float) $overall[$fast]['p95'];
            $comparable = count($overall) > 1;

            // Fastest-first, not selection order: with six engines the useful
            // thing to read off the verdict is the ranking, and the selection
            // order is already the order of every table above.
            $p50 = [];

            foreach ($byP50 as $engine => $stats) {
                $p50[$engine] = $stats['p50'];
            }

            $verdict['latency'] = [
                'fastest' => $fast,
                'slowest' => $comparable ? $slow : null,
                // The spread between the extremes. Reporting a factor per
                // engine would be five numbers saying the same thing; the
                // p50_ms ranking below is where per-engine detail belongs.
                'p50_factor' => $comparable && $fastP50 > 0 ? round((float) $overall[$slow]['p50'] / $fastP50, 2) : null,
                'p95_factor' => $comparable && $fastP95 > 0 ? round((float) $overall[$slow]['p95'] / $fastP95, 2) : null,
                'p50_ms' => $p50,
            ];
        }

        /** @var array<string, array<string, float|int>> $hits */
        $hits = $quality['per_engine'];

        if ($hits !== []) {
            // A tie for the lead names nobody rather than the first engine to
            // reach the score: with six engines on a couple of dozen golden
            // entries, ties at the top are the normal case, and quietly
            // awarding them to whichever one was listed first would invent a
            // winner out of the --engine order.
            $best = null;
            $bestScore = -1;
            $tied = false;

            foreach ($hits as $engine => $scores) {
                $score = (int) $scores['hit@10'];

                if ($score > $bestScore) {
                    $best = (string) $engine;
                    $bestScore = $score;
                    $tied = false;

                    continue;
                }

                if ($score === $bestScore) {
                    $tied = true;
                }
            }

            $verdict['golden'] = [
                'more_hits' => $tied ? null : $best,
                'hit@10' => array_map(static fn (array $s): int => (int) $s['hit@10'], $hits),
                'hit@1' => array_map(static fn (array $s): int => (int) $s['hit@1'], $hits),
                'mrr' => array_map(static fn (array $s): float => round((float) $s['mrr'], 4), $hits),
            ];
        }

        return $verdict;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeTables(SymfonyStyle $io, OutputInterface $output, array $payload, int $k): void
    {
        /** @var array{overall: array<string, array<string, float|int>>, per_query: array<string, array<string, array<string, float|int>>>} $latency */
        $latency = $payload['latency'];

        $io->section('Latency (ms, as reported by the engine)');
        $io->table(
            ['engine', 'n', 'min', 'p50', 'p90', 'p95', 'p99', 'max', 'mean', 'stddev'],
            array_map(
                fn (string $engine, array $s): array => [
                    $engine,
                    (string) $s['n'],
                    $this->ms($s['min']),
                    $this->ms($s['p50']),
                    $this->ms($s['p90']),
                    $this->ms($s['p95']),
                    $this->ms($s['p99']),
                    $this->ms($s['max']),
                    $this->ms($s['mean']),
                    $this->ms($s['stddev']),
                ],
                array_keys($latency['overall']),
                array_values($latency['overall']),
            ),
        );
        $io->comment('Percentiles use linear interpolation between closest ranks (R-7).');

        if ($output->isVerbose() && $latency['per_query'] !== []) {
            $engines = array_keys($latency['per_query']);
            $queries = array_keys($latency['per_query'][$engines[0]]);
            $headers = ['query'];

            foreach ($engines as $engine) {
                $headers[] = $engine . ' p50';
                $headers[] = $engine . ' p95';
            }

            $rows = [];

            foreach ($queries as $query) {
                $row = [$query];

                foreach ($engines as $engine) {
                    $s = $latency['per_query'][$engine][$query] ?? null;
                    $row[] = $s === null ? '-' : $this->ms($s['p50']);
                    $row[] = $s === null ? '-' : $this->ms($s['p95']);
                }

                $rows[] = $row;
            }

            $io->section('Latency per query (ms)');
            $io->table($headers, $rows);
        } else {
            $io->comment('Run with -v for the per-query latency breakdown.');
        }

        /** @var array{baseline: string|null, engines: list<string>, comparable: int, per_engine: array<string, array<string, mixed>>, per_query: list<array<string, mixed>>} $agreement */
        $agreement = $payload['agreement'];

        if ($agreement['per_engine'] === []) {
            $io->section('Engine agreement');
            $io->text('Only one engine ran, so there is nothing to compare it against.');
        } else {
            $baseline = (string) $agreement['baseline'];

            $io->section(sprintf('Engine agreement (baseline %s, k=%d)', $baseline, $k));
            $io->text(sprintf(
                '%d queries were comparable: %s or the other engine returned something for them.',
                $agreement['comparable'],
                $baseline,
            ));
            $io->table(
                ['engine', 'compared', 'mean overlap@' . $k, 'mean Jaccard', 'mean Spearman', 'identical top-1'],
                array_map(
                    static fn (array $s): array => [
                        (string) $s['engine'],
                        (string) $s['comparable'],
                        sprintf('%.2f of %d', (float) $s['mean_overlap'], $k),
                        sprintf('%.3f', (float) $s['mean_jaccard']),
                        $s['mean_spearman'] === null ? 'n/a' : sprintf('%.3f', (float) $s['mean_spearman']),
                        sprintf('%d of %d', (int) $s['identical_top1'], (int) $s['comparable']),
                    ],
                    array_values($agreement['per_engine']),
                ),
            );

            $disagreements = array_values(array_filter(
                $agreement['per_query'],
                static fn (array $row): bool => !$row['same_top1'] || $row['overlap'] < $row['k'],
            ));

            // Worst first, and with five engines the same query shows up once
            // per engine: that repetition is the signal, because a query every
            // engine disagrees with the baseline on is a query where the
            // baseline itself is probably the odd one out.
            usort($disagreements, static fn (array $x, array $y): int => $x['overlap'] <=> $y['overlap']);

            $shown = $output->isVerbose() ? $disagreements : array_slice($disagreements, 0, 20);

            if ($shown !== []) {
                $io->text(sprintf(
                    'Engine/query pairs that disagree with %s (%d of %d, %s):',
                    $baseline,
                    count($disagreements),
                    count($agreement['per_query']),
                    $output->isVerbose() ? 'all shown' : 'worst 20 shown, -v for all',
                ));
                $io->table(
                    ['engine', 'query', 'overlap', 'jaccard', 'spearman', 'top1 ' . $baseline, 'top1 engine'],
                    array_map(
                        static fn (array $row): array => [
                            (string) $row['engine'],
                            (string) $row['query'],
                            sprintf('%d/%d', $row['overlap'], $row['k']),
                            sprintf('%.2f', $row['jaccard']),
                            $row['spearman'] === null ? '-' : sprintf('%.2f', $row['spearman']),
                            (string) $row['baseline_top1'],
                            (string) $row['top1'],
                        ],
                        $shown,
                    ),
                );
            }
        }

        /** @var array{per_engine: array<string, array<string, float|int>>, per_entry: list<array<string, mixed>>} $quality */
        $quality = $payload['golden'];

        $io->section('Golden set');
        $io->table(
            ['engine', 'entries', 'hit@1', 'hit@3', 'hit@10', 'MRR'],
            array_map(
                static fn (string $engine, array $s): array => [
                    $engine,
                    (string) $s['entries'],
                    sprintf('%d (%.0f%%)', $s['hit@1'], $s['entries'] > 0 ? 100 * $s['hit@1'] / $s['entries'] : 0),
                    sprintf('%d (%.0f%%)', $s['hit@3'], $s['entries'] > 0 ? 100 * $s['hit@3'] / $s['entries'] : 0),
                    sprintf('%d (%.0f%%)', $s['hit@10'], $s['entries'] > 0 ? 100 * $s['hit@10'] / $s['entries'] : 0),
                    sprintf('%.3f', $s['mrr']),
                ],
                array_keys($quality['per_engine']),
                array_values($quality['per_engine']),
            ),
        );

        $engines = array_keys($quality['per_engine']);
        $misses = array_values(array_filter(
            $quality['per_entry'],
            static function (array $entry) use ($engines): bool {
                foreach ($engines as $engine) {
                    if (($entry['engines'][$engine]['rank'] ?? null) !== 1) {
                        return true;
                    }
                }

                return false;
            },
        ));

        if ($misses !== []) {
            $headers = ['query'];

            foreach ($engines as $engine) {
                $headers[] = $engine . ' rank';
                $headers[] = $engine . ' top1';
            }

            $headers[] = 'tests';

            $io->text(sprintf('Golden entries not ranked first by every engine (%d of %d):', count($misses), count($quality['per_entry'])));
            $io->table(
                $headers,
                array_map(
                    function (array $entry) use ($engines): array {
                        $row = [$entry['query']];

                        foreach ($engines as $engine) {
                            $rank = $entry['engines'][$engine]['rank'] ?? null;
                            $row[] = $rank === null ? 'miss' : (string) $rank;
                            $row[] = $entry['engines'][$engine]['top1'] ?? '-';
                        }

                        // The full rationale lives in golden.json; a terminal
                        // table that wraps to 400 columns helps nobody.
                        $row[] = $this->truncate((string) $entry['note'], 60);

                        return $row;
                    },
                    $misses,
                ),
            );
        }

        $io->section('Verdict');

        /** @var array<string, mixed> $verdict */
        $verdict = $payload['verdict'];
        $lines = [];

        if (isset($verdict['latency'])) {
            /** @var array<string, mixed> $l */
            $l = $verdict['latency'];
            $lines[] = $l['slowest'] === null
                ? sprintf('Latency: only %s ran, so there is nothing to compare it to.', (string) $l['fastest'])
                : sprintf(
                    'Latency: %s is fastest and %s slowest, %sx at p50 and %sx at p95 between them.',
                    (string) $l['fastest'],
                    (string) $l['slowest'],
                    $l['p50_factor'] === null ? '?' : (string) $l['p50_factor'],
                    $l['p95_factor'] === null ? '?' : (string) $l['p95_factor'],
                );

            foreach ((array) $l['p50_ms'] as $engine => $value) {
                $lines[] = sprintf('  %s p50 %s ms', (string) $engine, $this->ms((float) $value));
            }
        }

        if (isset($verdict['golden'])) {
            /** @var array<string, mixed> $g */
            $g = $verdict['golden'];
            $lines[] = $g['more_hits'] === null
                ? 'Golden set: no single engine leads on hit@10.'
                : sprintf('Golden set: %s produced the most hit@10.', (string) $g['more_hits']);

            foreach ((array) $g['hit@10'] as $engine => $value) {
                $lines[] = sprintf(
                    '  %s hit@1 %d, hit@10 %d, MRR %.3f',
                    (string) $engine,
                    (int) ((array) $g['hit@1'])[$engine],
                    (int) $value,
                    (float) ((array) $g['mrr'])[$engine],
                );
            }
        }

        /** @var array<string, int> $errors */
        $errors = $payload['errors'];

        foreach ($errors as $engine => $count) {
            if ($count === 0) {
                continue;
            }

            $lines[] = sprintf('%s returned %d error%s during the run.', $engine, $count, $count === 1 ? '' : 's');

            // Naming the failure and one query that triggers it is the
            // difference between a number to ignore and a bug to fix.
            foreach ($this->errorMessages[$engine] ?? [] as $message => $detail) {
                $lines[] = sprintf('   %dx on e.g. "%s": %s', $detail['count'], $detail['query'], $message);
            }
        }

        $io->text($lines === [] ? 'Not enough engines were available to compare.' : $lines);
    }

    /**
     * Long-format CSV so the whole run fits one flat file that a spreadsheet or
     * pandas can pivot: section,engine,query,metric,value.
     *
     * @param array<string, mixed> $payload
     */
    private function writeCsv(OutputInterface $output, array $payload): void
    {
        $handle = fopen('php://temp', 'r+b');

        if ($handle === false) {
            return;
        }

        fputcsv($handle, ['section', 'engine', 'query', 'metric', 'value'], ',', '"', '');

        /** @var array{overall: array<string, array<string, float|int>>, per_query: array<string, array<string, array<string, float|int>>>} $latency */
        $latency = $payload['latency'];

        foreach ($latency['overall'] as $engine => $stats) {
            foreach ($stats as $metric => $value) {
                fputcsv($handle, ['latency_overall', $engine, '', $metric, (string) $value], ',', '"', '');
            }
        }

        foreach ($latency['per_query'] as $engine => $byQuery) {
            foreach ($byQuery as $query => $stats) {
                foreach ($stats as $metric => $value) {
                    fputcsv($handle, ['latency_query', $engine, $query, $metric, (string) $value], ',', '"', '');
                }
            }
        }

        /** @var array{baseline: string|null, per_engine: array<string, array<string, mixed>>, per_query: list<array<string, mixed>>} $agreement */
        $agreement = $payload['agreement'];

        // The engine column now carries the *compared* engine; which engine it
        // was compared against is a property of the whole run, so it is emitted
        // once as its own row rather than repeated on every line. Without it a
        // saved CSV is unreadable six months later, because nothing else in the
        // file says what the agreement numbers are agreement with.
        if ($agreement['baseline'] !== null) {
            fputcsv($handle, ['agreement_overall', $agreement['baseline'], '', 'is_baseline', '1'], ',', '"', '');
        }

        foreach ($agreement['per_engine'] as $engine => $stats) {
            foreach ($stats as $metric => $value) {
                if ($metric === 'engine') {
                    continue;
                }

                fputcsv($handle, ['agreement_overall', (string) $engine, '', $metric, $value === null ? '' : (string) $value], ',', '"', '');
            }
        }

        foreach ($agreement['per_query'] as $row) {
            $engine = (string) $row['engine'];

            fputcsv($handle, ['agreement', $engine, $row['query'], 'overlap', (string) $row['overlap']], ',', '"', '');
            fputcsv($handle, ['agreement', $engine, $row['query'], 'jaccard', sprintf('%.4f', $row['jaccard'])], ',', '"', '');
            fputcsv($handle, ['agreement', $engine, $row['query'], 'spearman', $row['spearman'] === null ? '' : sprintf('%.4f', $row['spearman'])], ',', '"', '');
            fputcsv($handle, ['agreement', $engine, $row['query'], 'same_top1', $row['same_top1'] ? '1' : '0'], ',', '"', '');
        }

        /** @var array{per_engine: array<string, array<string, float|int>>, per_entry: list<array<string, mixed>>} $quality */
        $quality = $payload['golden'];

        foreach ($quality['per_engine'] as $engine => $stats) {
            foreach ($stats as $metric => $value) {
                fputcsv($handle, ['golden_overall', $engine, '', $metric, (string) $value], ',', '"', '');
            }
        }

        foreach ($quality['per_entry'] as $entry) {
            foreach ($entry['engines'] as $engine => $detail) {
                fputcsv($handle, ['golden_entry', (string) $engine, $entry['query'], 'rank', $detail['rank'] === null ? '' : (string) $detail['rank']], ',', '"', '');
            }
        }

        rewind($handle);
        $output->write((string) stream_get_contents($handle));
        fclose($handle);
    }

    /**
     * @param list<Suggestion> $suggestions
     *
     * @return list<string>
     */
    private function ids(array $suggestions): array
    {
        return array_values(array_map(static fn (Suggestion $s): string => $s->id, $suggestions));
    }

    /**
     * @param list<Suggestion> $suggestions
     */
    private function label(array $suggestions): string
    {
        return $suggestions === [] ? '(no results)' : $suggestions[0]->label;
    }

    private function ms(float|int $value): string
    {
        return number_format((float) $value, 2);
    }

    private function truncate(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 3) . '...' : $text;
    }

    /**
     * Lazy per engine so a dead Elasticsearch still lets the MySQL numbers out.
     *
     * The result keeps the selection order, which is what makes the first
     * surviving engine the agreement baseline. An engine that drops out here
     * therefore hands the baseline to the next one along -- deliberate: a run
     * against a baseline that produced no results at all would report total
     * disagreement everywhere and mean nothing.
     *
     * @param list<string>          $engines
     * @param array<string, string> $unavailable
     *
     * @return array<string, SuggesterInterface>
     */
    private function buildSuggesters(array $engines, array &$unavailable): array
    {
        $factories = $this->container->suggesters();
        $suggesters = [];

        foreach ($engines as $engine) {
            try {
                $suggester = $factories[$engine]();
                $health = $suggester->health();

                if (!$health['ok']) {
                    $unavailable[$engine] = $health['detail'];

                    continue;
                }

                $suggesters[$engine] = $suggester;
            } catch (Throwable $e) {
                $unavailable[$engine] = $e->getMessage();
            }
        }

        return $suggesters;
    }

    /**
     * Resolves --engine into an ordered, duplicate-free list of method keys.
     *
     * Four shapes, all reducible to the same thing: "all" is the registry,
     * "es" is every method on the Elasticsearch backend (a group and not a
     * method, because "run the five ES variants against each other" is the
     * single most common thing to want and spelling out five hyphenated keys
     * invites typos), any registry key is itself, and commas combine them.
     *
     * Unlike the HTTP endpoint, an unknown token throws instead of falling
     * back to everything: a typo in a script that silently benchmarks six
     * engines for twenty minutes instead of the one you meant is worse than an
     * error, and unlike a hand-edited URL there is no one watching to notice.
     *
     * The order the caller typed is preserved -- that is the only thing that
     * chooses the agreement baseline (see agreementReport()), so canonicalising
     * it into registry order would silently take that choice away.
     *
     * @return list<string>
     */
    private function selectedEngines(string $engine): array
    {
        /** @var array<string, true> $selected */
        $selected = [];

        foreach (explode(',', $engine) as $token) {
            $token = strtolower(trim($token));

            // A trailing or doubled comma is a slip of the finger, not a
            // request for an engine called "".
            if ($token === '') {
                continue;
            }

            $expanded = match (true) {
                $token === 'all' => $this->container->methodKeys(),
                $token === 'es' => $this->container->methodKeysForBackend('elasticsearch'),
                $this->container->hasMethod($token) => [$token],
                default => throw new \InvalidArgumentException(sprintf(
                    'Unknown --engine "%s", expected all|es|%s, optionally comma separated.',
                    $token,
                    implode('|', $this->container->methodKeys()),
                )),
            };

            foreach ($expanded as $key) {
                // Keyed, so "mysql,all" runs mysql once and keeps it first
                // rather than benchmarking it twice.
                $selected[$key] = true;
            }
        }

        if ($selected === []) {
            throw new \InvalidArgumentException('--engine selected no engine; expected all|es|<method>, optionally comma separated.');
        }

        return array_map(strval(...), array_keys($selected));
    }

    /**
     * @return list<string>
     */
    private function loadQueries(string $path): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new \RuntimeException(sprintf('Query file not readable: %s', $path));
        }

        $queries = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Duplicates would silently weight one query more heavily in the
            // pooled latency aggregate, so they are dropped rather than run.
            $queries[$line] = true;
        }

        // Array keys that look like integers are stored as int, so a bare
        // postcode ("9000") would come back out of array_keys() as an int and
        // blow up SuggestQuery's string parameter under strict_types. Bare
        // postcodes are a legitimate query shape, so cast rather than drop.
        return array_map(strval(...), array_keys($queries));
    }

    /**
     * @return list<array{query: string, expect: list<string>, note: string}>
     */
    private function loadGolden(string $path): array
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new \RuntimeException(sprintf('Golden file not readable: %s', $path));
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Golden file is not valid JSON: %s', $path));
        }

        // The file is an object whose "_readme" documents the matching rule for
        // whoever edits it next; the expectations live under "entries". A bare
        // JSON array is accepted too, for a hand-written throwaway set.
        $list = isset($decoded['entries']) && is_array($decoded['entries']) ? $decoded['entries'] : $decoded;
        $entries = [];

        foreach ($list as $key => $entry) {
            if ($key === '_readme' || !is_array($entry)) {
                continue;
            }

            if (!isset($entry['query'], $entry['expect']) || !is_array($entry['expect'])) {
                throw new \RuntimeException(sprintf('Golden entry %s needs a "query" and an "expect" array.', (string) $key));
            }

            $entries[] = [
                'query' => (string) $entry['query'],
                'expect' => array_values(array_map(strval(...), $entry['expect'])),
                'note' => isset($entry['note']) ? (string) $entry['note'] : '',
            ];
        }

        return $entries;
    }
}
