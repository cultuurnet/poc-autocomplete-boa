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
