<?php

declare(strict_types=1);

use App\Container;
use App\Http\JsonResponse;
use App\Http\SuggestController;

/**
 * Front controller for the comparison UI's API.
 *
 * nginx serves index.html / app.js / style.css straight from disk and only
 * falls through to here for everything else, so this file is essentially the
 * /api/ router. It still handles the fallthrough cases, because the same
 * document root is usable with `php -S` and because an unmatched path should
 * answer in JSON, not in an HTML error page.
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"Dependencies are not installed. Run composer install."}';

    exit;
}

require $autoload;

// An HTML warning printed before the JSON body would break every client; the
// handlers below turn anything unexpected into a JSON 500 instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $e): void {
    JsonResponse::send([
        'error' => $e::class . ': ' . $e->getMessage(),
        'file' => basename($e->getFile()) . ':' . $e->getLine(),
    ], 500);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Read the request once, here, and pass values down explicitly.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$params = $_GET;

if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    JsonResponse::send(['error' => 'Method not allowed'], 405);

    exit;
}

if (!str_starts_with($path, '/api/')) {
    // Only reachable when something other than the nginx config in docker/
    // is in front of us (the builtin server, a misconfigured try_files).
    // Serve the page for the document root, refuse the rest in JSON.
    $index = __DIR__ . '/index.html';

    if (($path === '/' || $path === '/index.html' || $path === '/index.php') && is_file($index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index);

        exit;
    }

    JsonResponse::send(['error' => 'Not found'], 404);

    exit;
}

if ($path !== '/api/suggest' && $path !== '/api/health') {
    JsonResponse::send(['error' => 'Unknown endpoint: ' . $path], 404);

    exit;
}

$controller = new SuggestController(new Container());

JsonResponse::send($path === '/api/suggest'
    ? $controller->suggest($params)
    : $controller->health());
