<?php

declare(strict_types=1);

namespace App\Command;

use App\Container;
use App\Suggest\SuggesterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Is the stack up and does it hold data?
 *
 * Prints the resolved configuration first. Nine out of ten "it does not work"
 * reports are a container talking to 127.0.0.1 instead of the service name, or
 * a CSV path that does not exist inside the container, and both are visible at
 * a glance here.
 */
#[AsCommand(
    name: 'health',
    description: 'Check that MySQL and Elasticsearch are reachable and populated',
)]
final class HealthCommand extends Command
{
    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        CsvPathOption::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->container->config;

        // Resolved the same way import resolves it, so this report is about
        // the file an import would actually read. Unset is not an error here:
        // health is primarily about the engines, and the import commands say
        // their own piece when they need a path and have none.
        $csvPath = CsvPathOption::resolveOptional($input, $config);

        $io->title('Health');

        $csvReadable = $csvPath !== null && is_readable($csvPath);
        $csvSize = $csvReadable ? filesize((string) $csvPath) : false;

        $io->section('Configuration');
        $io->definitionList(
            ['mysql host' => sprintf('%s:%d', $config->mysqlHost, $config->mysqlPort)],
            ['mysql database' => $config->mysqlDatabase],
            ['mysql table' => $config->mysqlTable],
            ['mysql user' => $config->mysqlUser],
            ['elasticsearch host' => $config->elasticsearchHost],
            ['elasticsearch index' => $config->elasticsearchIndex],
            ['csv path' => $csvPath ?? 'not set (pass --csv/-f)'],
            ['csv' => match (true) {
                $csvReadable => sprintf('readable, %.1f MiB', ($csvSize === false ? 0 : $csvSize) / 1048576),
                $csvPath === null => 'n/a',
                default => 'NOT READABLE',
            }],
        );

        $rows = [];
        $healthy = true;

        foreach ($this->suggesters() as $name => $factory) {
            try {
                $suggester = $factory();
                $health = $suggester->health();
            } catch (Throwable $e) {
                // A connection error is a health answer too, and a far more
                // common one than an engine that is up but empty.
                $health = ['ok' => false, 'detail' => $e->getMessage(), 'documents' => 0];
            }

            $healthy = $healthy && $health['ok'];

            $rows[] = [
                $name,
                $health['ok'] ? 'yes' : 'NO',
                number_format($health['documents']),
                $this->truncate($health['detail']),
            ];
        }

        $io->section('Engines');
        $io->table(['engine', 'ok', 'documents', 'detail'], $rows);

        // Only when a path was actually named: "you did not pass --csv" is not a
        // health problem, it is just this command being run without one.
        if ($csvPath !== null && !$csvReadable) {
            $io->warning(sprintf(
                'CSV not readable at %s; an import from it will fail.',
                $csvPath,
            ));
        }

        if (!$healthy) {
            $io->error('At least one engine is unhealthy.');

            return Command::FAILURE;
        }

        $io->success('All engines healthy.');

        return Command::SUCCESS;
    }

    /**
     * Lazy per engine: constructing both up front means a dead MySQL hides the
     * state of Elasticsearch, which is exactly what this command must not do.
     *
     * @return array<string, callable(): SuggesterInterface>
     */
    private function suggesters(): array
    {
        return [
            'mysql' => $this->container->mysqlSuggester(...),
            'elasticsearch' => $this->container->elasticsearchSuggester(...),
        ];
    }

    private function truncate(string $detail): string
    {
        $detail = trim(preg_replace('/\s+/', ' ', $detail) ?? $detail);

        return mb_strlen($detail) > 120 ? mb_substr($detail, 0, 117) . '...' : $detail;
    }
}
