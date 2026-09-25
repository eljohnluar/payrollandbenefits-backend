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

    public static function allowedOrigin(): string
    {
        return (string) env('FRONTEND_ORIGIN', 'http://localhost:5173');
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
