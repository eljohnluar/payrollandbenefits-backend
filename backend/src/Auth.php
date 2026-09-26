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
        // A password-verified-but-not-yet-OTP'd user gets no app session.
        if (($claims['scope'] ?? '') === 'otp_pending') {
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

    /**
     * Password check only — a usable session additionally requires the 6-digit
     * email code (Supabase OTP) verified through /api/auth/supabase with the
     * returned pending token.
     */
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
        $pending = self::issue([
            'sub'    => (string) $user['id'],
            'email'  => $user['email'],
            'role'   => $user['role'],
            'scope'  => 'otp_pending',
            'iss'    => 'payroll-backend',
            'aud'    => 'payroll-frontend',
        ], 600);
        return [
            'otp_required' => true,
            'email'        => $user['email'],
            'pending'      => $pending,
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
        if ($username === '' || $password === '') {
            Http::error('Email, display name and password are required.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Http::error('Enter a valid email address.', 422);
        }
        if (strlen($username) < 2) {
            Http::error('Display name must be at least 2 characters.', 422);
        }
        $weak = self::strongPasswordProblem($password);
        if ($weak !== null) {
            Http::error($weak, 422);
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

    /**
     * Exchanges a Supabase Auth access token (email confirmation or OTP login)
     * for an app JWT. The token is validated against GoTrue's /user endpoint
     * rather than by checking its signature, so both legacy HS256 and the
     * newer ES256 signing keys work. Unknown emails are provisioned here only
     * after passing the same registration-code gate as the password form.
     */
    public static function exchangeFromSupabase(string $accessToken, array $body = []): array
    {
        $baseUrl = Config::supabaseUrl();
        $anonKey = Config::anonKey();
        if ($baseUrl === '' || $anonKey === '') {
            Http::error('Supabase Auth is not configured on this server.', 503);
        }
        $claims = self::supabaseUser($baseUrl, $anonKey, $accessToken);
        if ($claims === null) {
            Http::error('That Supabase session is not valid or has expired.', 401);
        }
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Http::error('The Supabase account has no usable email address.', 401);
        }
        if (empty($claims['email_confirmed_at'])) {
            Http::error('Confirm your email address first, then return to this tab.', 403, [
                'email_confirmation_pending' => true,
            ]);
        }

        // Second-factor path: the password was already verified and produced a
        // short-lived otp_pending token; the Supabase OTP session must belong to
        // the same address. No provisioning happens here — the account exists.
        $pending = trim((string) ($body['pending'] ?? ''));
        if ($pending !== '') {
            $pc = self::verify($pending);
            if ($pc === null || ($pc['scope'] ?? '') !== 'otp_pending'
                || strcasecmp((string) ($pc['email'] ?? ''), $email) !== 0) {
                Http::error('That verification request is no longer valid. Sign in again to get a new code.', 401);
            }
            $user = self::findAppUser($email);
            if ($user === null) {
                Http::error('No workspace account exists for that email.', 403);
            }
            return self::sessionFor($user);
        }

        $meta = is_array($claims['user_metadata'] ?? null) ? $claims['user_metadata'] : [];
        $user = self::findAppUser($email);
        if ($user === null) {
            $expected = Config::registrationCode();
            $code = strtoupper(trim((string) ($body['registration_code'] ?? $meta['registration_code'] ?? '')));
            if ($code === '' || $expected === '' || !hash_equals($expected, $code)) {
                Http::error('Enter your HR registration code to activate this account.', 428, [
                    'needs_registration_code' => true,
                ]);
            }
            $password = (string) ($body['password'] ?? '');
            $weak = self::strongPasswordProblem($password);
            if ($weak !== null) {
                Http::error($weak . ' (used for payslip access)', 422, [
                    'needs_registration_code' => true,
                    'needs_password'          => true,
                ]);
            }
            $name = trim((string) ($body['name'] ?? $meta['name'] ?? ''));
            if ($name === '') {
                $name = explode('@', $email)[0];
            }
            $pdo = Database::pdo();
            $check = $pdo->prepare('SELECT id FROM app_users WHERE lower(email) = ?');
            $check->execute([$email]);
            if ($check->fetch() === false) {
                $uid = (string) ($claims['id'] ?? '');
                $insert = $pdo->prepare(
                    "INSERT INTO app_users (email, password, name, role, initials, is_active, auth_uid)
                     VALUES (?, ?, ?, 'HR', ?, TRUE, ?)
                     RETURNING id, email, name, role"
                );
                $insert->execute([
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $name,
                    strtoupper(substr($name, 0, 2)),
                    preg_match('/^[0-9a-f-]{36}$/i', $uid) ? $uid : null,
                ]);
                if ($insert->fetch(PDO::FETCH_ASSOC) === false) {
                    Http::error('An error occurred during registration.', 500);
                }
            }
            $user = self::findAppUser($email);
            if ($user === null) {
                Http::error('An error occurred during registration.', 500);
            }
        }

        return self::sessionFor($user);
    }

    /** Mirrors frontend/src/lib/password.js — enforced here so it cannot be bypassed. */
    private static function strongPasswordProblem(string $password): ?string
    {
        $missing = [];
        if (strlen($password) < 8) $missing[] = 'at least 8 characters';
        if (!preg_match('/[A-Z]/', $password)) $missing[] = 'an uppercase letter';
        if (!preg_match('/[a-z]/', $password)) $missing[] = 'a lowercase letter';
        if (!preg_match('/[0-9]/', $password)) $missing[] = 'a number';
        if (!preg_match('/[^A-Za-z0-9]/', $password)) $missing[] = 'a special symbol';
        return $missing === [] ? null : 'Password needs: ' . implode(', ', $missing) . '.';
    }

    /** Issues the real (8-hour) app session for a resolved app_users row. */
    private static function sessionFor(array $user): array
    {
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
     * Applies a password chosen through the Supabase "reset password" recovery
     * email: the caller proves control of the inbox with a valid recovery
     * session, and we mirror the new password into app_users so login and the
     * payslip gate keep working.
     */
    public static function resetPassword(string $accessToken, string $newPassword): array
    {
        $baseUrl = Config::supabaseUrl();
        $anonKey = Config::anonKey();
        if ($baseUrl === '' || $anonKey === '') {
            Http::error('Supabase Auth is not configured on this server.', 503);
        }
        $weak = self::strongPasswordProblem($newPassword);
        if ($weak !== null) {
            Http::error($weak, 422);
        }
        $claims = self::supabaseUser($baseUrl, $anonKey, $accessToken);
        if ($claims === null) {
            Http::error('That recovery session is not valid or has expired.', 401);
        }
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '') {
            Http::error('The recovery session has no email address.', 401);
        }
        $stmt = Database::pdo()->prepare('UPDATE app_users SET password = ?, updated_at = now() WHERE lower(email) = ? AND is_active = TRUE RETURNING id');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $email]);
        if ($stmt->fetchColumn() === false) {
            Http::error('No workspace account exists for that email — sign in again or register.', 404);
        }
        return ['ok' => true, 'email' => $email];
    }

    /** @return array<string,mixed>|null the GoTrue user object, or null when the token is rejected */
    private static function supabaseUser(string $baseUrl, string $anonKey, string $accessToken): ?array
    {
        $ch = curl_init(rtrim($baseUrl, '/') . '/auth/v1/user');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'apikey: ' . $anonKey,
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($response === false || $status !== 200) {
            return null;
        }
        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : null;
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
