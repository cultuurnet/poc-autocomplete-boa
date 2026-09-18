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
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Loads the address register into both engines from a single CSV pass.
 *
 * Import time is itself one of the numbers the POC has to produce, so this
 * command reports per-engine write time next to the document counts rather
 * than just saying "done".
 */
#[AsCommand(
    name: 'import',
    description: 'Import the Belgian address register into MySQL and/or Elasticsearch',
)]
final class ImportCommand extends Command
{
    /**
     * How often the progress bar is nudged from the document loop. Redrawing
     * per document would cost more than the indexing itself at 4.2M rows.
     */
    private const TICK_EVERY = 2_000;

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
                'street (aggregated streets + municipalities + postcodes, ~83k docs), address (house numbers only, 4.2M docs) or all',
                'street',
            )
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N CSV rows')
            ->addOption(
                'postcode',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Restrict the import to these postcodes (repeatable)',
            )
            ->addOption('recreate', null, InputOption::VALUE_NONE, 'Drop and rebuild the table/index first')
            ->addOption(
                'csv',
                'f',
                InputOption::VALUE_REQUIRED,
                'Import from this CSV instead of the configured one; relative paths resolve against the working directory (/app in the container)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $engines = $this->selectedEngines((string) $input->getOption('engine'));
            $options = $this->importOptions($input);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $csvPath = $this->resolveCsvPath($input);

        // Validate before anything destructive runs. DocumentSource only reads
        // the header when the generator is first pulled, which is after
        // --recreate has already dropped the table and after the progress bar
        // has started drawing, so a wrong file used to surface as an uncaught
        // exception over a half-wiped index.
        try {
            $this->assertUsableCsv($csvPath);
            $source = new DocumentSource($csvPath);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->title('Import');
        $io->definitionList(
            ['csv' => $csvPath],
            ['engines' => implode(', ', $engines)],
            ['level' => (string) $input->getOption('level')],
            ['limit' => $options->limit === null ? 'none' : (string) $options->limit],
            ['postcodes' => $options->postcodes === [] ? 'all' : implode(', ', $options->postcodes)],
            ['recreate' => $input->getOption('recreate') ? 'yes' : 'no'],
        );

        /** @var array<string, string> $failures engine => reason */
        $failures = [];
        $indexers = $this->buildIndexers($engines, $failures);

        $recreate = (bool) $input->getOption('recreate');

        foreach ($indexers as $name => $indexer) {
            try {
                $indexer->prepare($recreate);
            } catch (Throwable $e) {
                $failures[$name] = 'prepare failed: ' . $e->getMessage();
                unset($indexers[$name]);
            }
        }

        if ($indexers === []) {
            $this->reportFailures($io, $failures);
            $io->error('No engine could be prepared; nothing was imported.');

            return Command::FAILURE;
        }

        foreach ($failures as $name => $reason) {
            $io->warning(sprintf('%s is unavailable, continuing without it: %s', $name, $reason));
        }

        // stream() takes $indexers by value and drops engines that die mid-run,
        // so the copy here still holds every engine we can ask for a count.
        $result = $this->stream($io, $source, $options, $indexers, $failures);

        $this->summarize($io, $result, $indexers, $failures);

        return $failures === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Read the CSV once and fan every document out to every live indexer.
     *
     * This is the reason the import loop looks the way it does: the source file
     * is 620 MB and the street aggregation pass alone takes minutes. Importing
     * into both engines by running the whole pipeline twice would double the
     * slowest part of the job for no reason, and worse, it would let the two
     * engines see subtly different input if the file changed in between.
     *
     * @param array<string, IndexerInterface> $indexers
     * @param array<string, string>           $failures
     *
     * @return array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int}
     */
    private function stream(
        SymfonyStyle $io,
        DocumentSource $source,
        ImportOptions $options,
        array $indexers,
        array &$failures,
    ): array {
        $bar = $this->progressBar($io);
        $phase = 'reading csv';
        $documents = 0;

        // The total is unknown until the aggregation pass has finished, so the
        // bar is indeterminate and the phase label carries the information.
        $progress = static function (string $csvPhase, int $rows) use ($bar, &$phase): void {
            $phase = match ($csvPhase) {
                'reading' => sprintf('reading csv (%s rows)', number_format($rows)),
                'read' => sprintf('read %s rows, indexing', number_format($rows)),
                'addresses' => sprintf('addresses (%s rows)', number_format($rows)),
                default => $csvPhase,
            };
            $bar->setMessage($phase, 'phase');
            $bar->display();
        };

        $typeCounts = array_fill_keys(SuggestionType::values(), 0);
        $nanos = array_fill_keys(array_keys($indexers), 0);
        $counts = array_fill_keys(array_keys($indexers), 0);

        $wallStart = hrtime(true);

        foreach ($source->documents($options, $progress) as $document) {
            ++$documents;
            ++$typeCounts[$document->type->value];

            foreach ($indexers as $name => $indexer) {
                // hrtime() costs ~50ns; the mapping and buffering inside add()
                // costs far more, so per-document timing is accurate enough to
                // compare the two engines' write paths.
                $started = hrtime(true);

                try {
                    $indexer->add($document);
                } catch (Throwable $e) {
                    $failures[$name] = 'write failed: ' . $e->getMessage();
                    unset($indexers[$name]);

                    continue;
                }

                $nanos[$name] += hrtime(true) - $started;
                ++$counts[$name];
            }

            if ($indexers === []) {
                break;
            }

            if ($documents % self::TICK_EVERY === 0) {
                $bar->setMessage($phase, 'phase');
                $bar->advance(self::TICK_EVERY);
            }
        }

        foreach ($indexers as $name => $indexer) {
            $bar->setMessage(sprintf('flushing %s', $name), 'phase');
            $bar->display();

            $started = hrtime(true);

            try {
                $indexer->flush();
                $indexer->finish();
            } catch (Throwable $e) {
                $failures[$name] = 'finish failed: ' . $e->getMessage();
            }

            $nanos[$name] += hrtime(true) - $started;
        }

        $wall = (hrtime(true) - $wallStart) / 1e9;

        $bar->setMessage('done', 'phase');
        $bar->finish();
        $io->newLine(2);

        return [
            'counts' => $counts,
            'nanos' => $nanos,
            'types' => $typeCounts,
            'documents' => $documents,
            'wall' => $wall,
            'peak' => memory_get_peak_usage(true),
        ];
    }

    private function progressBar(SymfonyStyle $io): ProgressBar
    {
        ProgressBar::setPlaceholderFormatterDefinition(
            'docrate',
            static function (ProgressBar $bar): string {
                $elapsed = max(0.001, microtime(true) - (float) $bar->getStartTime());

                return number_format($bar->getProgress() / $elapsed, 0);
            },
        );

        $bar = $io->createProgressBar();
        $bar->setFormat(' %phase% | %current% docs | %elapsed:6s% | %docrate% docs/s | mem %memory:6s%');
        $bar->setMessage('starting', 'phase');
        $bar->setRedrawFrequency(self::TICK_EVERY);
        $bar->minSecondsBetweenRedraws(0.2);
        $bar->start();

        return $bar;
    }

    /**
     * @param array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int} $result
     * @param array<string, IndexerInterface>                                                                                                $indexers
     * @param array<string, string>                                                                                                          $failures
     */
    private function summarize(SymfonyStyle $io, array $result, array $indexers, array $failures): void
    {
        $rows = [];

        foreach ($result['counts'] as $engine => $count) {
            $seconds = $result['nanos'][$engine] / 1e9;
            $verified = null;

            try {
                $verified = $indexers[$engine]->count();
            } catch (Throwable) {
                // Counting is a nicety; a dead engine already shows up below.
            }

            $rows[] = [
                $engine,
                number_format($count),
                $verified === null ? '-' : number_format($verified),
                sprintf('%.2f s', $seconds),
                // Below ~10ms the measurement is noise, not a throughput.
                $seconds >= 0.01 ? number_format($count / $seconds, 0) : '-',
                isset($failures[$engine]) ? 'FAILED' : 'ok',
            ];
        }

        $io->section('Per engine');
        $io->table(
            ['engine', 'documents sent', 'documents stored', 'write time', 'docs/s', 'status'],
            $rows,
        );

        $io->section('Documents by type');
        $io->table(
            ['type', 'documents'],
            array_map(
                static fn (string $type, int $count): array => [$type, number_format($count)],
                array_keys($result['types']),
                array_values($result['types']),
            ),
        );

        $io->definitionList(
            ['documents produced' => number_format($result['documents'])],
            ['wall clock (shared csv pass + all writes)' => sprintf('%.1f s', $result['wall'])],
            ['throughput' => sprintf('%s docs/s', number_format($result['documents'] / max(0.001, $result['wall']), 0))],
            ['peak memory' => sprintf('%.1f MiB', $result['peak'] / 1048576)],
        );

        // Write time is per engine; wall clock is shared because the CSV is read
        // once for both. Comparing the two write times is the fair comparison.
        $io->comment('Write time excludes the shared CSV pass, so the two engines are comparable to each other.');

        $this->reportFailures($io, $failures);

        if ($failures === []) {
            $io->success('Import complete.');
        }
    }

    /**
     * @param array<string, string> $failures
     */
    private function reportFailures(SymfonyStyle $io, array $failures): void
    {
        foreach ($failures as $engine => $reason) {
            $io->error(sprintf('%s: %s', $engine, $reason));
        }
    }

    /**
     * Build the selected indexers one at a time.
     *
     * Container::indexers() constructs both, which means one dead engine takes
     * the other down with it (the PDO connection is opened eagerly). A partial
     * import into the engine that is up is more useful than no import at all,
     * so each engine is constructed on its own and its failure is recorded
     * against that engine only.
     *
     * @param list<string>          $engines
     * @param array<string, string> $failures
     *
     * @return array<string, IndexerInterface>
     */
    private function buildIndexers(array $engines, array &$failures): array
    {
        $factories = [
            'mysql' => $this->container->mysqlIndexer(...),
            'elasticsearch' => $this->container->elasticsearchIndexer(...),
        ];

        $indexers = [];

        foreach ($engines as $engine) {
            try {
                $indexers[$engine] = $factories[$engine]();
            } catch (Throwable $e) {
                $failures[$engine] = 'connection failed: ' . $e->getMessage();
            }
        }

        return $indexers;
    }

    /**
     * --csv/-f wins over CSV_PATH; relative paths are taken from the working
     * directory, which is /app in the container, so `-f data/addresses.csv` works.
     * The absolute form is what gets printed and put into every error message,
     * because "not found: data/addresses.csv" is not enough to debug a path.
     */
    private function resolveCsvPath(InputInterface $input): string
    {
        $csv = $input->getOption('csv');

        if (!is_string($csv) || $csv === '') {
            return $this->container->config->csvPath;
        }

        $resolved = realpath($csv);

        return $resolved === false ? $csv : $resolved;
    }

    /**
     * Cheap up-front check: one stat plus one line of I/O.
     *
     * Everything it rejects would otherwise fail later and worse — either deep
     * inside a generator or, for a wrong-but-parseable file, not at all.
     */
    private function assertUsableCsv(string $path): void
    {
        if (!file_exists($path)) {
            throw new RuntimeException(sprintf(
                "CSV file not found: %s\n"
                . "Relative paths resolve against %s (the project root inside the container).\n"
                . 'Either pass an existing file with --csv/-f, or put openaddress-bevlg.csv in the project root.',
                $path,
                (string) getcwd(),
            ));
        }

        if (is_dir($path)) {
            throw new RuntimeException(sprintf('CSV path is a directory, not a file: %s', $path));
        }

        $handle = is_readable($path) ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            throw new RuntimeException(sprintf(
                'CSV file exists but cannot be read: %s (check the file permissions).',
                $path,
            ));
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        fclose($handle);

        if ($header === false || $header === [null]) {
            throw new RuntimeException(sprintf('CSV file is empty: %s', $path));
        }

        if ($header !== CsvColumns::EXPECTED_HEADER) {
            throw new RuntimeException(sprintf(
                "%s does not have the expected header, so its columns cannot be trusted.\n"
                . "expected: %s\n"
                . 'found:    %s',
                $path,
                implode(',', CsvColumns::EXPECTED_HEADER),
                implode(',', array_map(strval(...), $header)),
            ));
        }
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

    private function importOptions(InputInterface $input): ImportOptions
    {
        $level = (string) $input->getOption('level');

        // "street" is the level the autocomplete actually needs: nobody types a
        // house number to find a street. "address" exists to measure what the
        // two engines do at 4.2M documents instead of 83k.
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
