<?php

declare(strict_types=1);

namespace App\Command;

use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs one `import` child process per CSV, at most N at a time.
 *
 * The unit of parallelism is deliberately the file rather than a slice of a
 * file. Every aggregate the import builds - a street's average coordinate and
 * address count, a municipality's, a postcode's - spans all the rows that share
 * a key, and the three regional exports share no postcode, NIS code or
 * street_id between them. So splitting on file boundaries is the one split that
 * produces exactly the documents a single-process import would: cutting a file
 * in half instead would hand two workers the same street key, and since both
 * indexers write by id the second worker's half-counted street document would
 * quietly replace the first's.
 *
 * Which leaves the two things that are not per-file and therefore do not belong
 * in a worker: the reset (--recreate) and the index build (finish()). Both are
 * run by the parent, before the first worker starts and after the last one
 * exits; the children are told to skip the latter.
 *
 * Workers are processes because there is no other option here: php-fpm is built
 * without pcntl, and a child process is a better fit anyway, since each one
 * needs its own PDO connection and its own copy of an 83k-entry aggregate table
 * that must not be shared.
 */
final class ImportWorkers
{
    /**
     * How long stream_select() waits for worker output before the loop checks
     * for exited children. Short enough that a finished worker is reported
     * promptly, long enough that polling costs nothing over a half-hour import.
     */
    private const POLL_SECONDS = 1;

    private const CONSOLE = __DIR__ . '/../../bin/console';

    public function __construct(private readonly SymfonyStyle $io)
    {
    }

    /**
     * @param array<string, list<string>> $jobs csv path => that child's options, already in --name=value form
     *
     * @return array<string, int> csv path => exit code
     */
    public function run(array $jobs, int $concurrency): array
    {
        $console = realpath(self::CONSOLE);

        if ($console === false) {
            throw new RuntimeException(sprintf('Cannot locate the console at %s', self::CONSOLE));
        }

        $pending = array_keys($jobs);
        $running = [];
        $exitCodes = [];

        while ($pending !== [] || $running !== []) {
            while ($pending !== [] && count($running) < $concurrency) {
                $path = (string) array_shift($pending);
                $running[$path] = $this->start($console, $path, $jobs[$path]);

                $this->io->text(sprintf(
                    '<info>%s</info> started (%d of %d running)',
                    $this->label($path),
                    count($running),
                    $concurrency,
                ));
            }

            $this->pump($running);

            foreach ($running as $path => $worker) {
                $status = proc_get_status($worker['process']);

                if ($status['running'] === true) {
                    continue;
                }

                // One last drain: a worker that has already exited may still
                // have its summary sitting in the pipe buffer, and closing the
                // pipe now would throw away exactly the output that says what
                // it did.
                $last = [$path => $worker];
                $this->pump($last, drain: true);

                fclose($worker['stdout']);
                fclose($worker['stderr']);
                proc_close($worker['process']);

                unset($running[$path]);
                $exitCodes[$path] = (int) $status['exitcode'];

                $this->io->text(sprintf(
                    '%s after %s',
                    $exitCodes[$path] === 0
                        ? sprintf('<info>%s</info> finished', $this->label($path))
                        : sprintf('<error>%s</error> FAILED (exit %d)', $this->label($path), $exitCodes[$path]),
                    $this->elapsed($worker['startedAt']),
                ));
            }
        }

        return $exitCodes;
    }

    /**
     * @param list<string> $childArguments
     *
     * @return array{process: resource, stdout: resource, stderr: resource, buffer: string, startedAt: float}
     */
    private function start(string $console, string $csvPath, array $childArguments): array
    {
        $command = [PHP_BINARY, $console, 'import', '--csv=' . $csvPath, ...$childArguments];

        $descriptors = [
            // Nothing to read from stdin, and leaving it inherited would let a
            // child block the whole pool on a prompt nobody can see.
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, dirname($console, 2));

        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Cannot start a worker for %s', $csvPath));
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'buffer' => '',
            'startedAt' => microtime(true),
        ];
    }

    /**
     * Forward whatever the workers have written, one prefixed line at a time.
     *
     * Reading has to happen on every pass, not only at the end: a pipe holds
     * 64 KB, and a worker whose output is not drained blocks on its next write
     * and stops importing. So this is not just reporting - it is what keeps the
     * workers running.
     *
     * @param array<string, array{process: resource, stdout: resource, stderr: resource, buffer: string, startedAt: float}> $workers
     */
    private function pump(array &$workers, bool $drain = false): void
    {
        $streams = [];

        foreach ($workers as $path => $worker) {
            $streams[$path . "\0out"] = $worker['stdout'];
            $streams[$path . "\0err"] = $worker['stderr'];
        }

        if ($streams === []) {
            return;
        }

        do {
            $read = array_values($streams);
            $write = [];
            $except = [];

            if (@stream_select($read, $write, $except, $drain ? 0 : self::POLL_SECONDS) === false) {
                return;
            }

            $sawData = false;

            foreach ($read as $stream) {
                $key = array_search($stream, $streams, true);

                if ($key === false) {
                    continue;
                }

                $chunk = fread($stream, 65536);

                if ($chunk === false || $chunk === '') {
                    // EOF on this pipe: stop selecting on it, but keep the
                    // worker's other pipe going.
                    if (feof($stream)) {
                        unset($streams[$key]);
                    }

                    continue;
                }

                $sawData = true;
                [$path] = explode("\0", (string) $key, 2);

                if (isset($workers[$path])) {
                    $workers[$path]['buffer'] = $this->emit(
                        $path,
                        $workers[$path]['buffer'] . $chunk,
                    );
                }
            }
        } while ($drain && $sawData && $streams !== []);
    }

    /**
     * Print every complete line in $buffer and return the unterminated tail.
     *
     * Split on \r as well as \n because the progress bar separates its redraws
     * with a carriage return; over a pipe those arrive as content, not as a
     * cursor movement, and would otherwise all end up on one unreadable line.
     */
    private function emit(string $path, string $buffer): string
    {
        $segments = preg_split('/\r\n|\r|\n/', $buffer);

        if ($segments === false) {
            return '';
        }

        $tail = (string) array_pop($segments);

        foreach ($segments as $segment) {
            $segment = rtrim($segment);

            if ($segment === '') {
                continue;
            }

            $this->io->writeln(sprintf(
                '<info>%s</info> %s',
                $this->label($path),
                OutputFormatter::escape($segment),
            ));
        }

        return $tail;
    }

    /**
     * The filename without its directory or extension: enough to tell three
     * regional exports apart, short enough to prefix every line with.
     */
    private function label(string $path): string
    {
        $label = basename($path, '.csv');

        return $label === '' ? $path : $label;
    }

    private function elapsed(float $startedAt): string
    {
        $seconds = microtime(true) - $startedAt;

        if ($seconds < 60) {
            return sprintf('%.1f s', $seconds);
        }

        return sprintf('%d m %02d s', (int) ($seconds / 60), (int) fmod($seconds, 60));
    }
}
