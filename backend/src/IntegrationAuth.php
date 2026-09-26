<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Machine-to-machine auth for external HRMS modules. Callers send an
 * X-Api-Key header; the key is stored only as a SHA-256 hash.
 */
final class IntegrationAuth
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{id:int,name:string,scopes:string} */
    public function requireScope(string $scope): array
    {
        $key = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
        if ($key === '') {
            throw new RuntimeException('Missing X-Api-Key header.', 401);
        }
        $stmt = $this->pdo->prepare('SELECT id, name, scopes FROM integration_keys WHERE key_hash = ? AND is_active = TRUE');
        $stmt->execute([hash('sha256', $key)]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Unknown or revoked integration key.', 401);
        }
        $scopes = array_map('trim', explode(',', (string) $row['scopes']));
        if (!in_array('*', $scopes, true) && !in_array($scope, $scopes, true)) {
            throw new RuntimeException("This key is not authorized for scope '{$scope}'.", 403);
        }
        $this->pdo->prepare('UPDATE integration_keys SET last_used_at = now() WHERE id = ?')->execute([$row['id']]);
        return $row;
    }

    public function issueKey(string $name, string $scopes): string
    {
        $plain = bin2hex(random_bytes(24));
        $stmt = $this->pdo->prepare(
            'INSERT INTO integration_keys (name, key_hash, scopes) VALUES (?, ?, ?) RETURNING id'
        );
        $stmt->execute([$name, hash('sha256', $plain), $scopes]);
        return $plain;
    }
}
