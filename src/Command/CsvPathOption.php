<?php

declare(strict_types=1);

namespace App\Command;

use App\Config;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The --csv/-f option, shared by import and health.
 *
 * Both commands have to agree on which file they are talking about: a `health`
 * that reports on a different path than the one `import` would read is worse
 * than no report at all. Defining the option and its resolution once is what
 * keeps them from drifting.
 */
final class CsvPathOption
{
    public static function configure(Command $command): void
    {
        $command->addOption(
            'csv',
            'f',
            InputOption::VALUE_REQUIRED,
            'Path to the address CSV. Required unless CSV_PATH is set; relative paths resolve '
                . 'against the working directory, which is /app in the container',
        );
    }

    /**
     * For commands that cannot run without a file. There is no default to fall
     * back on, so "nothing given" is a usage error and has to read like one.
     */
    public static function resolve(InputInterface $input, Config $config): string
    {
        $path = self::resolveOptional($input, $config);

        if ($path === null) {
            throw new RuntimeException(sprintf(
                "No CSV given, and there is no default.\n"
                . "Pass --csv/-f, or `make import CSV=data/addresses.csv`, or set CSV_PATH.\n"
                . 'Relative paths resolve against %s.',
                (string) getcwd(),
            ));
        }

        return $path;
    }

    /**
     * Cheap up-front check: one stat plus one line of I/O.
     *
     * Everything it rejects would otherwise fail later and worse - either deep
     * inside a generator or, for a wrong-but-parseable file, not at all. The
     * header check is also what stops the two exports from being mixed up:
     * they are both CSVs of Belgian places and neither would obviously
     * misbehave on the other's columns.
     *
     * @param list<string> $expectedHeader
     */
    public static function assertUsable(string $path, array $expectedHeader): void
    {
        if (!file_exists($path)) {
            throw new RuntimeException(sprintf(
                "CSV file not found: %s\n"
                . 'Relative paths resolve against %s (the project root inside the container).',
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

        if ($header !== $expectedHeader) {
            throw new RuntimeException(sprintf(
                "%s does not have the expected header, so its columns cannot be trusted.\n"
                . "expected: %s\n"
                . 'found:    %s',
                $path,
                implode(',', $expectedHeader),
                implode(',', array_map(strval(...), $header)),
            ));
        }
    }

    /**
     * --csv/-f wins over CSV_PATH, and null means neither was given — which
     * `health` reports rather than treats as fatal.
     *
     * Relative paths are taken from the working directory, which is /app in the
     * container, so `-f data/addresses.csv` works. The absolute form is what gets
     * printed and put into every error message, because "not found:
     * data/addresses.csv" is not enough to debug a path.
     */
    public static function resolveOptional(InputInterface $input, Config $config): ?string
    {
        $csv = $input->getOption('csv');

        if (!is_string($csv) || $csv === '') {
            return $config->csvPath;
        }

        $resolved = realpath($csv);

        return $resolved === false ? $csv : $resolved;
    }
}
