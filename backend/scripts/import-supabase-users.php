<?php
declare(strict_types=1);

/**
 * One-shot: mirror every app_users row into Supabase Auth so those emails can
 * use email-confirmation links and OTP codes.
 *
 *   cd backend/backend && C:\\xampp\\php\\php.exe scripts/import-supabase-users.php
 *
 * Needs SUPABASE_URL + SUPABASE_SERVICE_ROLE_KEY in .env. Idempotent: rows that
 * already have auth_uid (or already exist in Supabase) are skipped/linked.
 */

require_once __DIR__ . '/../src/env.php';
load_env(dirname(__DIR__) . '/.env');
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';

$url = rtrim(App\Config::supabaseUrl(), '/');
$key = App\Config::serviceRoleKey();
if ($url === '' || $key === '') {
    fwrite(STDERR, "Set SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY in backend/.env first.\n");
    exit(1);
}

$pdo = App\Database::pdo();
$users = $pdo->query('SELECT id, email, name, password, auth_uid FROM app_users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $u) {
    if ($u['auth_uid'] !== null && $u['auth_uid'] !== '') {
        echo "skip  {$u['email']} (already linked {$u['auth_uid']})\n";
        continue;
    }

    $payload = [
        'email'          => $u['email'],
        'email_confirm'  => true,
        'user_metadata'  => ['name' => $u['name']],
    ];
    // Carry the existing bcrypt hash over so Supabase password sign-in works too.
    if (str_starts_with((string) $u['password'], '$2y$')) {
        $payload['hashed_password'] = str_replace('$2y$', '$2a$', (string) $u['password']);
        $payload['password_hash']   = 'bcrypt';
    }

    $ch = curl_init($url . '/auth/v1/admin/users');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['apikey: ' . $key, 'Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $res = json_decode($body, true) ?: [];

    if ($status === 422 && str_contains(strtolower($body), 'already')) {
        $list = curl_init($url . '/auth/v1/admin/users?per_page=1000');
        curl_setopt_array($list, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['apikey: ' . $key, 'Authorization: Bearer ' . $key],
        ]);
        $found = json_decode((string) curl_exec($list), true);
        curl_close($list);
        $uid = null;
        foreach ((array) ($found['users'] ?? []) as $su) {
            if (strcasecmp((string) ($su['email'] ?? ''), (string) $u['email']) === 0) {
                $uid = $su['id'] ?? null;
            }
        }
        if ($uid === null) {
            echo "error {$u['email']} exists in Supabase but was not found via admin list\n";
            continue;
        }
    } elseif ($status !== 200 && $status !== 201) {
        echo "error {$u['email']} -> HTTP {$status}: " . substr($body, 0, 200) . "\n";
        continue;
    } else {
        $uid = $res['id'] ?? null;
    }

    if (!is_string($uid)) {
        echo "error {$u['email']} -> no user id in response\n";
        continue;
    }
    $pdo->prepare('UPDATE app_users SET auth_uid = ? WHERE id = ?')->execute([$uid, (int) $u['id']]);
    echo "ok    {$u['email']} -> {$uid}\n";
}

echo "done\n";
