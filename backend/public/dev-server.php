<?php
/**
 * Dev-only router for PHP's built-in server. Serves the built React app from
 * frontend/dist and the JSON API from this directory:
 *
 *   php -S localhost:8080 -t backend/public backend/public/dev-server.php
 *
 * Then open http://localhost:8080 (after `npm run build` in frontend/).
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/index.php';
    return true;
}

$distRoot = realpath(dirname(__DIR__, 2) . '/frontend/dist');
$candidate = $distRoot !== false ? realpath($distRoot . $path) : false;

// Reject anything that escapes the dist root before touching the filesystem.
if ($candidate !== false && $distRoot !== false && is_file($candidate) && str_starts_with($candidate, $distRoot)) {
    $types = [
        'js' => 'text/javascript', 'css' => 'text/css', 'html' => 'text/html',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
        'woff2' => 'font/woff2', 'json' => 'application/json', 'map' => 'application/json',
    ];
    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($candidate);
    return true;
}

if ($distRoot !== false && is_file($distRoot . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($distRoot . '/index.html');
    return true;
}

http_response_code(404);
echo 'frontend/dist/index.html not found. Run `npm run build` in frontend/ first.';
return true;
