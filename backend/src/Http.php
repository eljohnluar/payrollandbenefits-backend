<?php
declare(strict_types=1);

namespace App;

final class Http
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::json(['error' => $message] + $extra, $status);
    }

    /** Best-effort client address; honours the proxy header Supabase/Cloudflare set. */
    public static function clientIp(): ?string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($forwarded) && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
        $direct = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($direct) && $direct !== '' ? $direct : null;
    }

    /** Decoded JSON body, falling back to form-encoded POST fields. */
    public static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST;
    }

    public static function requireString(array $data, string $key): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value === '') {
            self::error("Field '{$key}' is required.", 422);
        }
        return $value;
    }

    public static function number(array $data, string $key, float $default = 0.0): float
    {
        return is_numeric($data[$key] ?? null) ? (float) $data[$key] : $default;
    }
}
