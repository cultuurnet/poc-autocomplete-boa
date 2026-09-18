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
 * Compares MySQL and Elasticsearch on the same queries against the same data.
 *
 * Two questions, because either one alone is misleading: how fast is it, and is
 * the answer any good. A fast engine that ranks "Kerkstraat, 2060 Antwerpen"
 * above "Kerkstraat, 9050 Gentbrugge" for the query "kerkstraat gent" is not a
 * usable autocomplete, and a perfectly ranked engine that takes 400 ms is not
 * an autocomplete at all.
 *
 * Quality is measured twice. Engine agreement needs no ground truth and covers
 * the whole query set: wherever the two engines disagree is where a human has
 * to look. The golden set is hand-curated ground truth over a couple of dozen
 * known-item queries and is the only measure that can say which engine is
 * right rather than merely different.
 */
#[AsCommand(
    name: 'benchmark',
    description: 'Benchmark MySQL against Elasticsearch on latency and result quality',
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
            ->addOption('engine', null, InputOption::VALUE_REQUIRED, 'all|mysql|elasticsearch', 'all')
            ->addOption('no-fuzzy', null, InputOption::VALUE_NONE, 'Disable fuzzy matching on both engines')
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
     * running the whole MySQL set and then the whole Elasticsearch set. A GC
     * pause, a noisy neighbour on the host or a background merge then lands on
     * both engines roughly equally instead of being charged entirely to
     * whichever one happened to be running at the time.
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
     * How much the two engines agree, per query.
     *
     * No ground truth needed, which is what makes this measure worth having:
     * it covers every query in the file, not just the couple of dozen someone
     * had the patience to curate. It cannot tell you who is right, only where
     * to look.
     *
     * @param list<string>                                   $queries
     * @param array<string, array<string, list<Suggestion>>> $captured
     *
     * @return array{engines: list<string>, per_query: list<array<string, mixed>>, mean_overlap: float, mean_jaccard: float, mean_spearman: float|null, identical_top1: int, comparable: int}
     */
    private function agreementReport(array $queries, array $captured, int $k): array
    {
        $engines = array_keys($captured);

        if (count($engines) !== 2) {
            return [
                'engines' => $engines,
                'per_query' => [],
                'mean_overlap' => 0.0,
                'mean_jaccard' => 0.0,
                'mean_spearman' => null,
                'identical_top1' => 0,
                'comparable' => 0,
            ];
        }

        [$a, $b] = $engines;
        $rows = [];
        $overlaps = [];
        $jaccards = [];
        $spearmans = [];
        $sameTop1 = 0;

        foreach ($queries as $query) {
            $left = $this->ids($captured[$a][$query] ?? []);
            $right = $this->ids($captured[$b][$query] ?? []);

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

            $rows[] = [
                'query' => $query,
                'overlap' => $overlap,
                'k' => min($k, max(count($topK), count($topKOther))),
                'jaccard' => $jaccard,
                'spearman' => $spearman,
                'same_top1' => $identical,
                'top1' => [$a => $this->label($captured[$a][$query] ?? []), $b => $this->label($captured[$b][$query] ?? [])],
            ];
        }

        return [
            'engines' => [$a, $b],
            'per_query' => $rows,
            'mean_overlap' => $overlaps === [] ? 0.0 : array_sum($overlaps) / count($overlaps),
            'mean_jaccard' => $jaccards === [] ? 0.0 : array_sum($jaccards) / count($jaccards),
            'mean_spearman' => $spearmans === [] ? null : array_sum($spearmans) / count($spearmans),
            'identical_top1' => $sameTop1,
            'comparable' => count($rows),
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

        if (count($overall) === 2) {
            $engines = array_keys($overall);
            $byP50 = $overall;
            uasort($byP50, static fn (array $x, array $y): int => $x['p50'] <=> $y['p50']);
            $fast = array_key_first($byP50);
            $slow = array_key_last($byP50);
            $slowP50 = (float) $overall[$slow]['p50'];
            $fastP50 = (float) $overall[$fast]['p50'];

            $verdict['latency'] = [
                'faster' => $fast,
                'p50_factor' => $fastP50 > 0 ? round($slowP50 / $fastP50, 2) : null,
                'p95_factor' => (float) $overall[$fast]['p95'] > 0
                    ? round((float) $overall[$slow]['p95'] / (float) $overall[$fast]['p95'], 2)
                    : null,
                'p50_ms' => [$engines[0] => $overall[$engines[0]]['p50'], $engines[1] => $overall[$engines[1]]['p50']],
            ];
        }

        $hits = [];

        foreach ($quality['per_engine'] as $engine => $scores) {
            $hits[$engine] = $scores;
        }

        if (count($hits) === 2) {
            $engines = array_keys($hits);
            $best = $hits[$engines[0]]['hit@10'] <=> $hits[$engines[1]]['hit@10'];

            $verdict['golden'] = [
                'more_hits' => $best === 0 ? null : ($best > 0 ? $engines[0] : $engines[1]),
                'hit@10' => [$engines[0] => $hits[$engines[0]]['hit@10'], $engines[1] => $hits[$engines[1]]['hit@10']],
                'hit@1' => [$engines[0] => $hits[$engines[0]]['hit@1'], $engines[1] => $hits[$engines[1]]['hit@1']],
                'mrr' => [$engines[0] => round((float) $hits[$engines[0]]['mrr'], 4), $engines[1] => round((float) $hits[$engines[1]]['mrr'], 4)],
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

        /** @var array{engines: list<string>, per_query: list<array<string, mixed>>, mean_overlap: float, mean_jaccard: float, mean_spearman: float|null, identical_top1: int, comparable: int} $agreement */
        $agreement = $payload['agreement'];

        if ($agreement['comparable'] > 0 && count($agreement['engines']) === 2) {
            [$a, $b] = $agreement['engines'];

            $io->section(sprintf('Engine agreement (%s vs %s, k=%d)', $a, $b, $k));
            $io->definitionList(
                ['queries compared' => (string) $agreement['comparable']],
                ['mean overlap@k' => sprintf('%.2f of %d', $agreement['mean_overlap'], $k)],
                ['mean Jaccard' => sprintf('%.3f', $agreement['mean_jaccard'])],
                ['mean Spearman (shared ids)' => $agreement['mean_spearman'] === null ? 'n/a' : sprintf('%.3f', $agreement['mean_spearman'])],
                ['identical top-1' => sprintf('%d of %d', $agreement['identical_top1'], $agreement['comparable'])],
            );

            $disagreements = array_values(array_filter(
                $agreement['per_query'],
                static fn (array $row): bool => !$row['same_top1'] || $row['overlap'] < $row['k'],
            ));

            usort($disagreements, static fn (array $x, array $y): int => $x['overlap'] <=> $y['overlap']);

            $shown = $output->isVerbose() ? $disagreements : array_slice($disagreements, 0, 20);

            if ($shown !== []) {
                $io->text(sprintf(
                    'Queries where the engines disagree (%d of %d, %s):',
                    count($disagreements),
                    $agreement['comparable'],
                    $output->isVerbose() ? 'all shown' : 'worst 20 shown, -v for all',
                ));
                $io->table(
                    ['query', 'overlap', 'jaccard', 'spearman', 'top1 ' . $a, 'top1 ' . $b],
                    array_map(
                        static fn (array $row): array => [
                            $row['query'],
                            sprintf('%d/%d', $row['overlap'], $row['k']),
                            sprintf('%.2f', $row['jaccard']),
                            $row['spearman'] === null ? '-' : sprintf('%.2f', $row['spearman']),
                            $row['top1'][$a] ?? '-',
                            $row['top1'][$b] ?? '-',
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

            $io->text(sprintf('Golden entries not ranked first by both engines (%d of %d):', count($misses), count($quality['per_entry'])));
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
            $lines[] = sprintf(
                'Latency: %s is faster, %sx at p50 and %sx at p95.',
                (string) $l['faster'],
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
                ? 'Golden set: both engines produced the same number of hit@10.'
                : sprintf('Golden set: %s produced more hit@10.', (string) $g['more_hits']);

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

        /** @var array{per_query: list<array<string, mixed>>} $agreement */
        $agreement = $payload['agreement'];

        foreach ($agreement['per_query'] as $row) {
            fputcsv($handle, ['agreement', '', $row['query'], 'overlap', (string) $row['overlap']], ',', '"', '');
            fputcsv($handle, ['agreement', '', $row['query'], 'jaccard', sprintf('%.4f', $row['jaccard'])], ',', '"', '');
            fputcsv($handle, ['agreement', '', $row['query'], 'spearman', $row['spearman'] === null ? '' : sprintf('%.4f', $row['spearman'])], ',', '"', '');
            fputcsv($handle, ['agreement', '', $row['query'], 'same_top1', $row['same_top1'] ? '1' : '0'], ',', '"', '');
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
     * @param list<string>          $engines
     * @param array<string, string> $unavailable
     *
     * @return array<string, SuggesterInterface>
     */
    private function buildSuggesters(array $engines, array &$unavailable): array
    {
        $factories = [
            'mysql' => $this->container->mysqlSuggester(...),
            'elasticsearch' => $this->container->elasticsearchSuggester(...),
        ];

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
     * @return list<string>
     */
    private function selectedEngines(string $engine): array
    {
        return match ($engine) {
            'all' => ['mysql', 'elasticsearch'],
            'mysql', 'elasticsearch' => [$engine],
            default => throw new \InvalidArgumentException(
                sprintf('Unknown --engine "%s", expected all|mysql|elasticsearch.', $engine),
            ),
        };
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
