<?php

declare(strict_types=1);

namespace App\Command;

use App\Container;
use App\Import\CsvColumns;
use App\Import\DocumentSource;
use App\Import\ImportOptions;
use App\Import\IndexerInterface;
use App\Model\SuggestionType;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Loads the Belgian address register into both engines.
 *
 * The register ships as one CSV per region - openaddress-bevlg.csv (Flanders),
 * -bewal.csv (Wallonia), -bebru.csv (Brussels) - so --csv is repeatable and a
 * full corpus is the three named together. They are additive by construction:
 * every document is written under its own id, so a region imported later joins
 * the ones already there instead of replacing them, and a region re-imported
 * refreshes its own documents and touches nothing else.
 *
 * The streaming, timing and reporting all live in ImportPipeline, which this
 * command shares with `import-places`; what is left here is the address
 * register's own shape - the --level aggregation choice and the header these
 * files are expected to have.
 *
 * --recreate removes the four document types this export owns and nothing else.
 * The place export writes into the same table/index, so a full drop here would
 * destroy 62k places as a side effect of refreshing the addresses; the two
 * import commands are symmetric, each resetting only what it writes. Without
 * it, nothing is deleted at all - that is the plain way to add a region to an
 * index that already holds the others.
 */
#[AsCommand(
    name: 'import',
    description: 'Import the Belgian address register into MySQL and/or Elasticsearch',
)]
final class ImportCommand extends Command
{
    /**
     * What --recreate clears: everything this export can produce.
     *
     * All four regardless of --level, because the level only narrows what this
     * run writes; leaving the other types behind would keep documents from an
     * earlier, wider run around as stale leftovers.
     *
     * @var list<SuggestionType>
     */
    private const RESET_TYPES = [
        SuggestionType::Address,
        SuggestionType::Street,
        SuggestionType::Municipality,
        SuggestionType::Postcode,
    ];

    /**
     * Upper bound on --workers.
     *
     * Not a measured optimum - it is a guard on a POC stack. Every worker is a
     * second PHP process holding its own aggregate table (a few hundred MB at
     * address level) and pushing bulk requests at a single-node Elasticsearch
     * with a 1 GB heap. Past a handful the two engines become the bottleneck
     * and the workers just queue behind each other with more memory held.
     */
    private const MAX_WORKERS = 8;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('engine', null, InputOption::VALUE_REQUIRED, 'all|mysql|elasticsearch', 'all')
            ->addOption(
                'level',
                null,
                InputOption::VALUE_REQUIRED,
                'street (aggregated streets + municipalities + postcodes, ~103k docs for all three regions), '
                    . 'address (house numbers only, 6.6M docs) or all',
                'street',
            )
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N CSV rows, per file')
            ->addOption(
                'postcode',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Restrict the import to these postcodes (repeatable)',
            )
            ->addOption(
                'recreate',
                null,
                InputOption::VALUE_NONE,
                'Remove the existing address, street, municipality and postcode documents first, leaving places in place. '
                    . 'Omit it to add the given files to what is already indexed',
            )
            ->addOption(
                'workers',
                null,
                InputOption::VALUE_REQUIRED,
                'Import this many CSV files in parallel, one child process per file (1 = in-process, sequential)',
                '1',
            )
            // The two options below exist for the children ImportWorkers spawns
            // and are not meant to be typed by hand: the whole-corpus index
            // build has to happen once, in the parent, and the parent needs the
            // children's counts back to report a single total.
            ->addOption('skip-finish', null, InputOption::VALUE_NONE, 'Internal: skip the index build (workers only)')
            ->addOption('result-file', null, InputOption::VALUE_REQUIRED, 'Internal: write the run totals here as JSON');

        CsvPathOption::configure($this, multiple: true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $engines = ImportPipeline::selectedEngines((string) $input->getOption('engine'));
            $options = $this->importOptions($input);
            $workers = $this->workerCount($input);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        // Validate every file before anything destructive runs. DocumentSource
        // only reads the header when the generator is first pulled, which is
        // after --recreate has already deleted the old documents and after the
        // progress bar has started drawing, so a wrong file used to surface as
        // an uncaught exception over a half-wiped index. With several files
        // that matters more, not less: the third path being a typo must not be
        // discovered two regions into the import.
        try {
            $csvPaths = CsvPathOption::resolveMany($input, $this->container->config);

            if ($csvPaths === []) {
                throw new RuntimeException(sprintf(
                    "No CSV given, and there is no default.\n"
                    . "Pass --csv/-f once per file, or `make import CSV=\"openaddress-bevlg.csv openaddress-bewal.csv\"`,\n"
                    . 'or set CSV_PATH. Relative paths resolve against %s.',
                    (string) getcwd(),
                ));
            }

            foreach ($csvPaths as $csvPath) {
                CsvPathOption::assertUsable($csvPath, CsvColumns::EXPECTED_HEADER, CsvColumns::normalizeHeader(...));
            }
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $recreate = (bool) $input->getOption('recreate');
        $workers = min($workers, count($csvPaths));

        $io->title('Import');
        $io->definitionList(
            ['csv' => implode("\n", $csvPaths)],
            ['engines' => implode(', ', $engines)],
            ['level' => (string) $input->getOption('level')],
            ['limit' => $options->limit === null ? 'none' : (string) $options->limit . ' rows per file'],
            ['postcodes' => $options->postcodes === [] ? 'all' : implode(', ', $options->postcodes)],
            ['recreate' => $recreate ? 'yes (address register documents only)' : 'no (adding to what is indexed)'],
            ['workers' => $workers > 1 ? sprintf('%d parallel', $workers) : '1 (sequential)'],
        );

        $pipeline = new ImportPipeline($this->container, $io);
        $indexers = $pipeline->prepare($engines, $recreate, self::RESET_TYPES);

        if ($indexers === []) {
            $pipeline->reportFailures();
            $io->error('No engine could be prepared; nothing was imported.');

            return Command::FAILURE;
        }

        $pipeline->warnAboutFailures();

        $result = $workers > 1
            ? $this->runInWorkers($io, $input, $csvPaths, $workers, array_keys($indexers))
            : $this->runHere($pipeline, $csvPaths, $options, $indexers);

        if ($result === null) {
            $pipeline->reportFailures();
            $io->error('At least one worker failed; see its output above.');

            return Command::FAILURE;
        }

        // Workers deliberately do not build the FULLTEXT index or force-merge
        // the Elasticsearch index: those cover the whole corpus and would fight
        // each other. Skipped here too when this run is itself a worker.
        if (!(bool) $input->getOption('skip-finish')) {
            $io->section('Finishing');
            $pipeline->finishAll($indexers);
        }

        $pipeline->summarize($result, $indexers);
        $this->writeResultFile($input, $result);

        return $pipeline->failed() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Read the files one after another into one set of indexers.
     *
     * One CSV pass at a time, but the indexers are shared, so a street that
     * exists in two files - which cannot happen between regions, but can
     * between an export and its own re-run - is written twice and upserts,
     * exactly as it would in separate invocations.
     *
     * @param list<string>                    $csvPaths
     * @param array<string, IndexerInterface> $indexers
     *
     * @return array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int}
     */
    private function runHere(
        ImportPipeline $pipeline,
        array $csvPaths,
        ImportOptions $options,
        array $indexers,
    ): array {
        $total = null;

        foreach ($csvPaths as $csvPath) {
            if (count($csvPaths) > 1) {
                $pipeline->announce($csvPath);
            }

            $result = $pipeline->stream(new DocumentSource($csvPath), $options, $indexers, finish: false);
            $total = $total === null ? $result : ImportPipeline::mergeResults($total, $result);
        }

        /** @var array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int} $total */
        return $total;
    }

    /**
     * Fan the files out over child processes and add up what they report.
     *
     * Returns null when any worker exited non-zero: a partial corpus that looks
     * complete is the one outcome worth failing loudly over, and the worker's
     * own error is already on screen, prefixed with its filename.
     *
     * @param list<string> $csvPaths
     * @param list<string> $engines
     *
     * @return array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int}|null
     */
    private function runInWorkers(
        SymfonyStyle $io,
        InputInterface $input,
        array $csvPaths,
        int $workers,
        array $engines,
    ): ?array {
        $resultDir = sys_get_temp_dir() . '/poc-autocomplete-import-' . getmypid();

        if (!is_dir($resultDir) && !mkdir($resultDir, 0o777, true) && !is_dir($resultDir)) {
            throw new RuntimeException(sprintf('Cannot create the worker result directory %s', $resultDir));
        }

        $resultFiles = [];
        $wallStart = hrtime(true);

        try {
            $io->section(sprintf('Importing %d file(s) over %d worker(s)', count($csvPaths), $workers));

            $exitCodes = (new ImportWorkers($io))->run(
                $this->jobs($input, $resultDir, $csvPaths, $resultFiles),
                $workers,
            );

            if (array_filter($exitCodes, static fn (int $code): bool => $code !== 0) !== []) {
                return null;
            }

            $total = null;

            foreach ($csvPaths as $csvPath) {
                $result = $this->readResultFile($resultFiles[$csvPath], $engines);
                $total = $total === null ? $result : ImportPipeline::mergeResults($total, $result);
            }

            if ($total === null) {
                return null;
            }

            // The workers ran side by side, so their wall clocks overlap and
            // adding them up would invent time that never passed. What the
            // corpus actually cost is measured here, in the parent.
            $total['wall'] = (hrtime(true) - $wallStart) / 1e9;

            return $total;
        } finally {
            foreach ($resultFiles as $file) {
                @unlink($file);
            }

            @rmdir($resultDir);
        }
    }

    /**
     * One child invocation per file: the shared options, plus that file's own
     * result file.
     *
     * --recreate is absent on purpose: the reset already ran in the parent, and
     * a worker repeating it would delete the region a sibling just finished
     * writing. --workers is absent so a child never spawns children of its own,
     * and --skip-finish is present because the index build belongs to whoever
     * outlives all the workers.
     *
     * @param list<string>          $csvPaths
     * @param array<string, string> $resultFiles filled in with csv path => result file
     *
     * @return array<string, list<string>> csv path => that child's options
     */
    private function jobs(
        InputInterface $input,
        string $resultDir,
        array $csvPaths,
        array &$resultFiles,
    ): array {
        $shared = [
            '--engine=' . (string) $input->getOption('engine'),
            '--level=' . (string) $input->getOption('level'),
            '--skip-finish',
            '--no-interaction',
        ];

        $limit = $input->getOption('limit');

        if ($limit !== null) {
            $shared[] = '--limit=' . (string) $limit;
        }

        foreach ((array) $input->getOption('postcode') as $postcode) {
            $shared[] = '--postcode=' . (string) $postcode;
        }

        $jobs = [];

        foreach ($csvPaths as $index => $csvPath) {
            $resultFiles[$csvPath] = sprintf('%s/%d.json', $resultDir, $index);
            $jobs[$csvPath] = [...$shared, '--result-file=' . $resultFiles[$csvPath]];
        }

        return $jobs;
    }

    /**
     * @param list<string> $engines
     *
     * @return array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int}
     */
    private function readResultFile(string $path, array $engines): array
    {
        $json = @file_get_contents($path);
        $decoded = $json === false ? null : json_decode($json, true);

        if (!is_array($decoded)) {
            // A worker that exited 0 without leaving a result file is a bug,
            // not a failed import, so the totals fall back to zero rather than
            // taking the whole run down after the data is already written.
            return [
                'counts' => array_fill_keys($engines, 0),
                'nanos' => array_fill_keys($engines, 0),
                'types' => array_fill_keys(SuggestionType::values(), 0),
                'documents' => 0,
                'wall' => 0.0,
                'peak' => 0,
            ];
        }

        /** @var array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int} $decoded */
        return $decoded;
    }

    /**
     * @param array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int} $result
     */
    private function writeResultFile(InputInterface $input, array $result): void
    {
        $path = $input->getOption('result-file');

        if (!is_string($path) || $path === '') {
            return;
        }

        @file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function workerCount(InputInterface $input): int
    {
        $workers = (int) $input->getOption('workers');

        if ($workers < 1 || $workers > self::MAX_WORKERS) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown --workers "%s", expected a number between 1 and %d.',
                (string) $input->getOption('workers'),
                self::MAX_WORKERS,
            ));
        }

        return $workers;
    }

    private function importOptions(InputInterface $input): ImportOptions
    {
        $level = (string) $input->getOption('level');

        // "street" is the level the autocomplete actually needs: nobody types a
        // house number to find a street. "address" exists to measure what the
        // two engines do at 6.6M documents instead of 100k.
        [$streets, $regions, $addresses] = match ($level) {
            'street' => [true, true, false],
            'address' => [false, false, true],
            'all' => [true, true, true],
            default => throw new \InvalidArgumentException(
                sprintf('Unknown --level "%s", expected street|address|all.', $level),
            ),
        };

        $limit = $input->getOption('limit');

        /** @var list<string> $postcodes */
        $postcodes = array_values(array_map(strval(...), (array) $input->getOption('postcode')));

        return new ImportOptions(
            limit: $limit === null ? null : (int) $limit,
            postcodes: $postcodes,
            addresses: $addresses,
            streets: $streets,
            regions: $regions,
        );
    }
}
