<?php

declare(strict_types=1);

namespace App\Command;

use App\Container;
use App\Import\ImportOptions;
use App\Import\PlaceCsvColumns;
use App\Import\PlaceDocumentSource;
use App\Model\SuggestionType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Loads the UiTdatabank place export into the same index as the addresses.
 *
 * Same options as `import`, minus --level: the address register is imported at
 * a chosen granularity because it holds 4.2M house numbers nobody types, while
 * this export is already one row per thing a user would pick. There is only
 * one level to import.
 *
 * --recreate removes only `place` documents: both exports write into one
 * table/index, so dropping it here would delete the address register as a side
 * effect of refreshing 62k places. `import --recreate` is the mirror image and
 * clears only the address register's types.
 */
#[AsCommand(
    name: 'import-places',
    description: 'Import the UiTdatabank place export into MySQL and/or Elasticsearch',
)]
final class ImportPlacesCommand extends Command
{
    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('engine', null, InputOption::VALUE_REQUIRED, 'all|mysql|elasticsearch', 'all')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N CSV rows')
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
                'Remove the existing place documents first, leaving addresses and regions in place',
            );

        CsvPathOption::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $engines = ImportPipeline::selectedEngines((string) $input->getOption('engine'));
            $options = $this->importOptions($input);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        // Before anything destructive runs, and before the progress bar starts
        // drawing -- see the same note in ImportCommand. The header check is
        // what catches the easy mistake of pointing this command at the address
        // register (or the other way round).
        try {
            $csvPath = CsvPathOption::resolve($input, $this->container->config);
            CsvPathOption::assertUsable($csvPath, PlaceCsvColumns::EXPECTED_HEADER);
            $source = new PlaceDocumentSource($csvPath);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $recreate = (bool) $input->getOption('recreate');

        $io->title('Import places');
        $io->definitionList(
            ['csv' => $csvPath],
            ['engines' => implode(', ', $engines)],
            ['limit' => $options->limit === null ? 'none' : (string) $options->limit],
            ['postcodes' => $options->postcodes === [] ? 'all' : implode(', ', $options->postcodes)],
            ['recreate' => $recreate ? 'yes (place documents only)' : 'no'],
        );

        $pipeline = new ImportPipeline($this->container, $io);
        $indexers = $pipeline->prepare($engines, $recreate, [SuggestionType::Place]);

        if ($indexers === []) {
            $pipeline->reportFailures();
            $io->error('No engine could be prepared; nothing was imported.');

            return Command::FAILURE;
        }

        $pipeline->warnAboutFailures();

        $result = $pipeline->stream($source, $options, $indexers);

        $this->reportSkipped($io, $source);
        $pipeline->summarize($result, $indexers);

        return $pipeline->failed() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Why rows did not become documents.
     *
     * Worth a table of its own rather than a single "skipped N": this export
     * is hand-maintained, so "my place is not in the autocomplete" is a
     * question that will be asked, and the answer is nearly always one of
     * these five lines.
     */
    private function reportSkipped(SymfonyStyle $io, PlaceDocumentSource $source): void
    {
        $skipped = array_filter($source->skipped(), static fn (int $count): bool => $count > 0);

        if ($skipped === []) {
            return;
        }

        $io->section('Rows skipped');
        $io->table(
            ['reason', 'rows'],
            array_map(
                static fn (string $reason, int $count): array => [$reason, number_format($count)],
                array_keys($skipped),
                array_values($skipped),
            ),
        );
        $io->comment(
            'Only Belgian addresses with a four-digit postcode are indexed: the postcode column, the '
            . '--postcode filter and the postcode suggestions are all built on that shape.',
        );
    }

    private function importOptions(InputInterface $input): ImportOptions
    {
        $limit = $input->getOption('limit');

        /** @var list<string> $postcodes */
        $postcodes = array_values(array_map(strval(...), (array) $input->getOption('postcode')));

        // The three level flags are the address register's, and PlaceDocumentSource
        // ignores them; only limit and postcodes mean anything here.
        return new ImportOptions(
            limit: $limit === null ? null : (int) $limit,
            postcodes: $postcodes,
        );
    }
}
