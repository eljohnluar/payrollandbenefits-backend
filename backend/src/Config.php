<?php
declare(strict_types=1);

namespace App;

final class Config
{
    public static function supabaseUrl(): string
    {
        return rtrim((string) env('SUPABASE_URL', ''), '/');
    }

    public static function anonKey(): string
    {
        return (string) env('SUPABASE_ANON_KEY', '');
    }

    /** Used only by the backend for Storage uploads; never sent to the browser. */
    public static function serviceRoleKey(): string
    {
        return (string) env('SUPABASE_SERVICE_ROLE_KEY', '');
    }

    /** Secret used to verify HS256 JWTs issued by Supabase Auth. */
    public static function jwtSecret(): string
    {
        return (string) env('SUPABASE_JWT_SECRET', '');
    }

    /**
     * Google service-account JSON for Vision OCR, from GOOGLE_SA_JSON (inline)
     * or the file at GOOGLE_APPLICATION_CREDENTIALS (Render secret file).
     */
    public static function googleServiceAccount(): ?array
    {
        $json = (string) env('GOOGLE_SA_JSON', '');
        if ($json === '' && ($path = (string) env('GOOGLE_APPLICATION_CREDENTIALS', '')) !== '' && is_readable($path)) {
            $json = (string) file_get_contents($path);
        }
        $sa = json_decode($json, true);
        return is_array($sa) && isset($sa['client_email'], $sa['private_key']) ? $sa : null;
    }

    /** Tabscanner receipt-OCR API key (free Starter plan: 200 scans/month). */
    public static function tabscannerKey(): string
    {
        return trim((string) env('TABSCANNER_API_KEY', ''));
    }

    public static function isSupabaseConfigured(): bool
    {
        return self::jwtSecret() !== '' && self::supabaseUrl() !== '';
    }

    public static function dsn(): string
    {
        if (($url = env('DATABASE_URL')) !== null) {
            // Accept both postgres:// and postgresql:// schemes.
            return preg_replace('#^postgres(ql)?://#', 'pgsql://', $url);
        }
        $ref = (string) env('SUPABASE_PROJECT_REF', '');
        return sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;user=%s;password=%s;sslmode=%s',
            (string) env('SUPABASE_DB_HOST', $ref !== '' ? "aws-0-ap-southeast-1.pooler.supabase.com" : '127.0.0.1'),
            (string) env('SUPABASE_DB_PORT', '5432'),          // Session pooler: PDO needs prepared statements.
            (string) env('SUPABASE_DB_NAME', 'postgres'),
            (string) env('SUPABASE_DB_USER', $ref !== '' ? "postgres.{$ref}" : 'postgres'),
            (string) env('SUPABASE_DB_PASSWORD', ''),
            (string) env('SUPABASE_DB_SSLMODE', 'require')
        );
    }

    /** Comma-separated list of allowed browser origins. */
    public static function allowedOrigins(): array
    {
        $default = 'https://payrollandbenefits-frontend.vercel.app,http://localhost:5173';
        return array_values(array_filter(array_map(
            static fn ($o) => rtrim(trim($o), '/'),
            explode(',', (string) env('FRONTEND_ORIGIN', $default))
        ), static fn ($o) => $o !== ''));
    }

    /**
     * CORS origin for this request: echoes the caller's origin when it is
     * allow-listed (so Vercel preview URLs work), else the first entry.
     */
    public static function corsOrigin(?string $requestOrigin = null): string
    {
        $allowed = self::allowedOrigins();
        if ($requestOrigin !== null && $requestOrigin !== '') {
            $normalized = rtrim($requestOrigin, '/');
            if (in_array($normalized, $allowed, true)) {
                return $normalized;
            }
        }
        return $allowed[0] ?? '';
    }

    /** Gate code required for self-registration (mirrors payrollandbenefits_php REGISTRATION_CODE). */
    public static function registrationCode(): string
    {
        return strtoupper(trim((string) env('REGISTRATION_CODE', '')));
    }

    /** Fallback signing secret for local dev logins when Supabase is not wired up yet. */
    public static function appSecret(): string
    {
        return (string) (self::jwtSecret() !== '' ? self::jwtSecret() : env('APP_SECRET', 'dev-only-secret-change-me'));
    }
}
