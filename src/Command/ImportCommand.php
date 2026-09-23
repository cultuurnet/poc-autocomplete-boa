<?php

declare(strict_types=1);

namespace App\Command;

use App\Container;
use App\Import\CsvColumns;
use App\Import\DocumentSource;
use App\Import\ImportOptions;
use App\Model\SuggestionType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Loads the address register into both engines from a single CSV pass.
 *
 * The streaming, timing and reporting all live in ImportPipeline, which this
 * command shares with `import-places`; what is left here is the address
 * register's own shape - the --level aggregation choice and the header this
 * file is expected to have.
 *
 * --recreate removes the four document types this export owns and nothing else.
 * The place export writes into the same table/index, so a full drop here would
 * destroy 62k places as a side effect of refreshing the addresses; the two
 * import commands are symmetric, each resetting only what it writes.
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
            ->addOption(
                'recreate',
                null,
                InputOption::VALUE_NONE,
                'Remove the existing address, street, municipality and postcode documents first, leaving places in place',
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

        // Validate before anything destructive runs. DocumentSource only reads
        // the header when the generator is first pulled, which is after
        // --recreate has already deleted the old documents and after the
        // progress bar has started drawing, so a wrong file used to surface as
        // an uncaught exception over a half-wiped index.
        try {
            $csvPath = CsvPathOption::resolve($input, $this->container->config);
            CsvPathOption::assertUsable($csvPath, CsvColumns::EXPECTED_HEADER);
            $source = new DocumentSource($csvPath);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $recreate = (bool) $input->getOption('recreate');

        $io->title('Import');
        $io->definitionList(
            ['csv' => $csvPath],
            ['engines' => implode(', ', $engines)],
            ['level' => (string) $input->getOption('level')],
            ['limit' => $options->limit === null ? 'none' : (string) $options->limit],
            ['postcodes' => $options->postcodes === [] ? 'all' : implode(', ', $options->postcodes)],
            ['recreate' => $recreate ? 'yes (address register documents only)' : 'no'],
        );

        $pipeline = new ImportPipeline($this->container, $io);
        $indexers = $pipeline->prepare($engines, $recreate, self::RESET_TYPES);

        if ($indexers === []) {
            $pipeline->reportFailures();
            $io->error('No engine could be prepared; nothing was imported.');

            return Command::FAILURE;
        }

        $pipeline->warnAboutFailures();

        $result = $pipeline->stream($source, $options, $indexers);
        $pipeline->summarize($result, $indexers);

        return $pipeline->failed() ? Command::FAILURE : Command::SUCCESS;
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
