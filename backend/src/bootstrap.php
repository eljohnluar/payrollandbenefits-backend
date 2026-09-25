<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';
load_env(dirname(__DIR__) . '/.env');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = substr($class, 4);
    foreach (['src', 'services', 'controllers'] as $folder) {
        $file = dirname(__DIR__) . '/' . $folder . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) {
            require_once $file;
            return;
        }
    }
});

use App\Config;
use App\Http;

$origin = Config::allowedOrigin();
header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Max-Age: 600');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('[payroll-api] ' . $e->getMessage());
    // Services throw RuntimeException with an HTTP code for business-rule
    // violations (422 etc.); surface the message with that status.
    if ($e instanceof RuntimeException && $e->getCode() >= 400 && $e->getCode() <= 599) {
        Http::error($e->getMessage(), (int) $e->getCode());
    }
    $debug = env('APP_DEBUG', '0') === '1';
    Http::error(
        $debug ? $e->getMessage() : 'Unexpected server error.',
        $e instanceof RuntimeException && str_contains($e->getMessage(), 'PostgreSQL') ? 503 : 500,
        $debug ? ['trace' => explode("\n", $e->getTraceAsString())] : []
    );
});
