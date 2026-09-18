<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The single place that writes a response body.
 *
 * Centralising it is not ceremony here: every exit from the front controller
 * (suggest, health, 404, uncaught throwable) has to agree on the same three
 * decisions, and two of them are load bearing. JSON_UNESCAPED_UNICODE keeps
 * Dutch place names readable -- half the point of this POC is eyeballing raw
 * engine output, and "Sint-Job-in-'t-Goor" turning into a wall of \u00XX makes
 * that useless. no-store is correctness, not politeness: a suggest response is
 * scoped to one keystroke, so a cached one is always the wrong one.
 */
final class JsonResponse
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param array<string, mixed> $payload
     */
    public static function send(array $payload, int $status = 200): void
    {
        $json = json_encode($payload, self::FLAGS);

        if ($json === false) {
            // Almost always invalid UTF-8 leaking out of the CSV via a debug
            // payload. Report it rather than emitting an empty 200.
            $status = 500;
            $json = json_encode(['error' => 'Response encoding failed: ' . json_last_error_msg()], self::FLAGS)
                ?: '{"error":"Response encoding failed"}';
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo $json;
    }
}
