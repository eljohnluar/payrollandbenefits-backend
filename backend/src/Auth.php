<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Resolves the caller from a bearer token.
 *
 * Two token sources are supported so the same backend runs before and after
 * Supabase Auth is wired up:
 *   1. A JWT issued by Supabase Auth (HS256, signed with the project JWT secret).
 *   2. A JWT issued by POST /api/auth/login below (signed with the same secret
 *      when present, otherwise APP_SECRET) — handy for local development.
 */
final class Auth
{
    private static ?array $current = null;

    public static function user(): array
    {
        $user = self::tryUser();
        if ($user === null) {
            $token = self::bearerToken();
            Http::error($token === '' ? 'Authentication required.' : 'Token is invalid or has expired.', 401);
        }
        return $user;
    }

    /** Resolves the caller without aborting the request — used by auditing and optional context. */
    public static function tryUser(): ?array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $token = self::bearerToken();
        if ($token === '') {
            return null;
        }
        $claims = self::verify($token);
        if ($claims === null) {
            return null;
        }
        $user = self::findAppUser((string) ($claims['email'] ?? ''));
        if ($user === null) {
            return null;
        }
        return self::$current = [
            'id'    => (int) $user['id'],
            'email' => $user['email'],
            'name'  => $user['name'],
            'role'  => $user['role'],
        ];
    }

    /** Re-checks the signed-in user's password (payslip print/download gate). */
    public static function verifyPassword(string $password): bool
    {
        $user = self::user();
        $stmt = Database::pdo()->prepare('SELECT password FROM app_users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $hash = (string) $stmt->fetchColumn();
        $stmt->closeCursor();
        return $hash !== '' && password_verify($password, $hash);
    }

    public static function requireRole(string ...$roles): array
    {
        $user = self::user();
        if (!in_array($user['role'], $roles, true)) {
            Http::error('This action requires one of: ' . implode(', ', $roles), 403);
        }
        return $user;
    }

    private static function bearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $header = $v; break; }
            }
        }
        return preg_match('/Bearer\s+(.+)$/i', $header, $m) ? trim($m[1]) : '';
    }

    /** @return array<string,mixed>|null decoded claims, or null when the token fails verification */
    public static function verify(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h64, $p64, $s64] = $parts;
        $secret = Config::appSecret();
        $expected = self::b64(hash_hmac('sha256', "$h64.$p64", $secret, true));
        if (!hash_equals($expected, self::b64(self::base64UrlDecode($s64)))) {
            return null;
        }
        $claims = json_decode((string) self::base64UrlDecode($p64), true);
        if (!is_array($claims)) {
            return null;
        }
        $now = time();
        if (isset($claims['exp']) && $now > (int) $claims['exp'] + 5) {
            return null;
        }
        if (isset($claims['nbf']) && $now < (int) $claims['nbf'] - 5) {
            return null;
        }
        return $claims;
    }

    public static function issue(array $claims, int $ttlSeconds = 28800): string
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $now = time();
        $payload = self::b64(json_encode($claims + ['iat' => $now, 'exp' => $now + $ttlSeconds], JSON_UNESCAPED_SLASHES));
        $sig = self::b64(hash_hmac('sha256', "$header.$payload", Config::appSecret(), true));
        return "{$header}.{$payload}.{$sig}";
    }

    public static function login(string $email, string $password): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, email, password, name, role FROM app_users WHERE email = ? AND is_active = TRUE LIMIT 1'
        );
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();
        $stmt->closeCursor();
        if ($user === false || !password_verify($password, $user['password'])) {
            return null;
        }
        $token = self::issue([
            'sub'   => (string) $user['id'],
            'email' => $user['email'],
            'role'  => $user['role'],
            'iss'   => 'payroll-backend',
            'aud'   => 'payroll-frontend',
        ]);
        return [
            'token' => $token,
            'user'  => ['id' => (int) $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'role' => $user['role']],
        ];
    }

    /**
     * Self-registration, mirroring payrollandbenefits_php/api/register.php:
     * required fields, email format, registration-code gate, duplicate check,
     * bcrypt hash, HR role, initials from the display name.
     */
    public static function register(string $email, string $username, string $password, string $registrationCode): array
    {
        $email = strtolower(trim($email));
        $username = trim($username);
        if ($email === '' || $username === '' || $password === '') {
            Http::error('Email, display name and password are required.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Http::error('Enter a valid email address.', 422);
        }
        $expected = Config::registrationCode();
        if ($expected === '') {
            Http::error('Registration is unavailable: REGISTRATION_CODE is not configured on the server.', 503);
        }
        if (!hash_equals($expected, strtoupper(trim($registrationCode)))) {
            Http::error('Invalid registration code.', 403);
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM app_users WHERE lower(email) = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch() !== false) {
            Http::error('That email is already registered.', 409);
        }

        $initials = strtoupper(substr($username, 0, 2));
        $insert = $pdo->prepare(
            "INSERT INTO app_users (email, password, name, role, initials, is_active)
             VALUES (?, ?, ?, 'HR', ?, TRUE)
             RETURNING id, email, name, role"
        );
        $insert->execute([$email, password_hash($password, PASSWORD_DEFAULT), $username, $initials]);
        $user = $insert->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            Http::error('An error occurred during registration.', 500);
        }
        return [
            'message' => 'Registration successful. You can now login with your email.',
            'user'    => ['id' => (int) $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'role' => $user['role']],
        ];
    }

    private static function findAppUser(string $email): ?array
    {
        if ($email === '') {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT id, email, name, role FROM app_users WHERE email = ? AND is_active = TRUE LIMIT 1');
        $stmt->execute([strtolower($email)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
