<?php

declare(strict_types=1);

namespace App\Command;

use App\Container;
use App\Import\DocumentSourceInterface;
use App\Import\ImportOptions;
use App\Import\IndexerInterface;
use App\Model\SuggestionType;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Everything the two import commands do that is not about their own CSV.
 *
 * `import` and `import-places` read different files into different document
 * types, but the machinery around that is identical: build the engines one at a
 * time, survive one of them dying, read the source once and fan every document
 * out to whatever is still alive, and report per-engine write time next to the
 * document counts. Import time is itself one of the numbers the POC has to
 * produce, so that reporting is not incidental - it is the point.
 *
 * Kept as a collaborator rather than a base class so the commands stay plain
 * Symfony commands, and so the failure bookkeeping (which engine died, when,
 * and why) lives in one object instead of in by-reference parameters threaded
 * through five methods.
 */
final class ImportPipeline
{
    /**
     * How often the progress bar is nudged from the document loop. Redrawing
     * per document would cost more than the indexing itself at 4.2M rows.
     */
    private const TICK_EVERY = 2_000;

    /** @var array<string, string> engine => reason */
    private array $failures = [];

    public function __construct(
        private readonly Container $container,
        private readonly SymfonyStyle $io,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function selectedEngines(string $engine): array
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
     * Build and ready every requested engine; returns the ones that survived.
     *
     * $resetTypes is what --recreate means for the calling command: the
     * document types that command owns. Both exports write into one shared
     * table/index, so dropping it would make either import destroy the other's
     * documents; each command instead clears exactly what it is about to write
     * and leaves the rest alone.
     *
     * @param list<string>         $engines
     * @param list<SuggestionType> $resetTypes
     *
     * @return array<string, IndexerInterface>
     */
    public function prepare(array $engines, bool $recreate, array $resetTypes = []): array
    {
        $indexers = $this->buildIndexers($engines);

        foreach ($indexers as $name => $indexer) {
            try {
                // A type-scoped reset still has to create the schema if this is
                // the first import into an empty stack, hence prepare(false)
                // rather than skipping prepare altogether.
                $indexer->prepare(false);

                if ($recreate && $resetTypes !== []) {
                    $deleted = 0;

                    foreach ($resetTypes as $type) {
                        $deleted += $indexer->deleteType($type);
                    }

                    $this->io->text(sprintf(
                        '%s: removed %s existing %s document(s); other document types untouched.',
                        $name,
                        number_format($deleted),
                        implode('/', array_map(static fn (SuggestionType $t): string => $t->value, $resetTypes)),
                    ));
                }
            } catch (Throwable $e) {
                $this->failures[$name] = 'prepare failed: ' . $e->getMessage();
                unset($indexers[$name]);
            }
        }

        return $indexers;
    }

    /**
     * Read the source once and fan every document out to every live indexer.
     *
     * This is the reason the import loop looks the way it does: the address
     * file is 620 MB and the street aggregation pass alone takes minutes.
     * Importing into both engines by running the whole pipeline twice would
     * double the slowest part of the job for no reason, and worse, it would let
     * the two engines see subtly different input if the file changed in between.
     *
     * $finish is what a worker turns off: building the FULLTEXT index and
     * force-merging the Elasticsearch index are whole-corpus operations, so
     * with several workers writing into one table they must happen once, in
     * the parent, after the last worker has exited - not once per worker while
     * the others are still inserting.
     *
     * @param array<string, IndexerInterface> $indexers
     *
     * @return array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int}
     */
    public function stream(
        DocumentSourceInterface $source,
        ImportOptions $options,
        array $indexers,
        bool $finish = true,
    ): array {
        $bar = $this->progressBar();
        $phase = 'reading csv';
        $documents = 0;

        // The total is unknown until the source has finished reading, so the
        // bar is indeterminate and the phase label carries the information.
        $progress = static function (string $csvPhase, int $rows) use ($bar, &$phase): void {
            $phase = match ($csvPhase) {
                'reading' => sprintf('reading csv (%s rows)', number_format($rows)),
                'read' => sprintf('read %s rows, indexing', number_format($rows)),
                'addresses' => sprintf('addresses (%s rows)', number_format($rows)),
                'places' => sprintf('places (%s rows)', number_format($rows)),
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
                    $this->failures[$name] = 'write failed: ' . $e->getMessage();
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

                if ($finish) {
                    $indexer->finish();
                }
            } catch (Throwable $e) {
                $this->failures[$name] = 'finish failed: ' . $e->getMessage();
            }

            $nanos[$name] += hrtime(true) - $started;
        }

        $wall = (hrtime(true) - $wallStart) / 1e9;

        $bar->setMessage('done', 'phase');
        $bar->finish();
        $this->io->newLine(2);

        return [
            'counts' => $counts,
            'nanos' => $nanos,
            'types' => $typeCounts,
            'documents' => $documents,
            'wall' => $wall,
            'peak' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Say which file the next stream() call is about.
     *
     * Only printed when a run covers more than one, where the progress bar
     * restarting from zero is otherwise the only clue that the import moved on
     * to the next region.
     */
    public function announce(string $csvPath): void
    {
        $this->io->section(sprintf('Reading %s', basename($csvPath)));
    }

    /**
     * Add one stream() result into a running total.
     *
     * Counts, types and documents add up across files because each file
     * contributes its own documents. Write time adds up too, and honestly so:
     * it is time an engine spent writing, whether that came from one file or
     * three. Wall clock is the exception - it is only additive for files read
     * one after another, so the parallel path overwrites it with its own
     * measurement - and peak memory is a high-water mark, not a sum.
     *
     * @param array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int} $total
     * @param array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int} $next
     *
     * @return array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int}
     */
    public static function mergeResults(array $total, array $next): array
    {
        foreach (['counts', 'nanos', 'types'] as $key) {
            foreach ($next[$key] as $name => $value) {
                $total[$key][$name] = ($total[$key][$name] ?? 0) + $value;
            }
        }

        $total['documents'] += $next['documents'];
        $total['wall'] += $next['wall'];
        $total['peak'] = max($total['peak'], $next['peak']);

        return $total;
    }

    /**
     * The whole-corpus half of the import, run once after every worker is done.
     *
     * Only reached on the multi-worker path: a single-process import finishes
     * inside stream(), where the cost is already part of that engine's write
     * time. Here there is no single write path to attribute it to, so it is
     * timed and reported on its own line instead of being folded into a number
     * it would distort.
     *
     * @param array<string, IndexerInterface> $indexers
     */
    public function finishAll(array $indexers): void
    {
        foreach ($indexers as $name => $indexer) {
            $started = hrtime(true);

            try {
                $indexer->finish();
                $this->io->text(sprintf(
                    '%s: built indexes and settled the index in %.1f s.',
                    $name,
                    (hrtime(true) - $started) / 1e9,
                ));
            } catch (Throwable $e) {
                $this->failures[$name] = 'finish failed: ' . $e->getMessage();
            }
        }
    }

    /**
     * @param array{counts: array<string, int>, nanos: array<string, int>, types: array<string, int>, documents: int, wall: float, peak: int} $result
     * @param array<string, IndexerInterface>                                                                                                $indexers
     */
    public function summarize(array $result, array $indexers): void
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
                isset($this->failures[$engine]) ? 'FAILED' : 'ok',
            ];
        }

        $this->io->section('Per engine');
        $this->io->table(
            // "documents stored" is the whole table/index, not just this run:
            // both exports write into the same one, so after a place import it
            // legitimately exceeds the number of documents sent.
            ['engine', 'documents sent', 'documents stored (total)', 'write time', 'docs/s', 'status'],
            $rows,
        );

        $this->io->section('Documents by type');
        $this->io->table(
            ['type', 'documents'],
            array_map(
                static fn (string $type, int $count): array => [$type, number_format($count)],
                array_keys($result['types']),
                array_values($result['types']),
            ),
        );

        $this->io->definitionList(
            ['documents produced' => number_format($result['documents'])],
            ['wall clock (shared csv pass + all writes)' => sprintf('%.1f s', $result['wall'])],
            ['throughput' => sprintf('%s docs/s', number_format($result['documents'] / max(0.001, $result['wall']), 0))],
            ['peak memory' => sprintf('%.1f MiB', $result['peak'] / 1048576)],
        );

        // Write time is per engine; wall clock is shared because the CSV is read
        // once for both. Comparing the two write times is the fair comparison.
        $this->io->comment('Write time excludes the shared CSV pass, so the two engines are comparable to each other.');

        $this->reportFailures();

        if ($this->failures === []) {
            $this->io->success('Import complete.');
        }
    }

    /**
     * Engines that came up but are not usable. Reported as warnings so a
     * partial import into the engine that is up still happens.
     */
    public function warnAboutFailures(): void
    {
        foreach ($this->failures as $name => $reason) {
            $this->io->warning(sprintf('%s is unavailable, continuing without it: %s', $name, $reason));
        }
    }

    public function reportFailures(): void
    {
        foreach ($this->failures as $engine => $reason) {
            $this->io->error(sprintf('%s: %s', $engine, $reason));
        }
    }

    public function failed(): bool
    {
        return $this->failures !== [];
    }

    private function progressBar(): ProgressBar
    {
        ProgressBar::setPlaceholderFormatterDefinition(
            'docrate',
            static function (ProgressBar $bar): string {
                $elapsed = max(0.001, microtime(true) - (float) $bar->getStartTime());

                return number_format($bar->getProgress() / $elapsed, 0);
            },
        );

        $bar = $this->io->createProgressBar();
        $bar->setFormat(' %phase% | %current% docs | %elapsed:6s% | %docrate% docs/s | mem %memory:6s%');
        $bar->setMessage('starting', 'phase');
        $bar->setRedrawFrequency(self::TICK_EVERY);
        // A bar redrawn in place costs nothing on a terminal, but a worker's
        // stdout is a pipe: there is no cursor to move back, so every redraw
        // becomes a line the parent has to print. Five a second from each
        // worker would bury the output that matters, so piped runs report
        // progress as an occasional status line instead.
        $bar->minSecondsBetweenRedraws($this->io->isDecorated() ? 0.2 : 10.0);
        $bar->start();

        return $bar;
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
     * @param list<string> $engines
     *
     * @return array<string, IndexerInterface>
     */
    private function buildIndexers(array $engines): array
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
                $this->failures[$engine] = 'connection failed: ' . $e->getMessage();
            }
        }

        return $indexers;
    }
}
