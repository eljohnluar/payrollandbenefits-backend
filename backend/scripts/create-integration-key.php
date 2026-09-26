<?php
declare(strict_types=1);

/**
 * Issue an integration API key for an external HRMS module.
 *
 *   cd backend/backend && C:\\xampp\\php\\php.exe scripts/create-integration-key.php <name> [scopes]
 *   e.g. ... scripts/create-integration-key.php workforce-module "workforce:write"
 *
 * The plaintext key is printed ONCE; only its SHA-256 hash is stored.
 */

require_once __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
$name = $argv[1] ?? null;
if ($name === null || trim($name) === '') {
    exit("Usage: create-integration-key.php <name> [scopes]\n");
}
$scopes = $argv[2] ?? '*';
$plain = (new App\IntegrationAuth(App\Database::pdo()))->issueKey($name, $scopes);
echo "Integration key created (store it now — it is shown only once):\n\n  name:   {$name}\n  scopes: {$scopes}\n  key:    {$plain}\n\n";
