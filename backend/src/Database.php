<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                // Credentials come from the DSN; pass null (not '') so an empty
                // string does not make libpq fall back to the OS user.
                self::$pdo = new PDO(Config::dsn(), null, null, $options);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    'Unable to reach the PostgreSQL database. Check backend/.env '
                    . '(SUPABASE_DB_HOST / SUPABASE_DB_PORT / SUPABASE_DB_USER / SUPABASE_DB_PASSWORD) '
                    . 'and confirm the database server is running. '
                    . 'Driver said: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
        return self::$pdo;
    }
}
