<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Outbound event bus: every payroll lifecycle event is logged to
 * integration_events_log and pushed to matching webhook_endpoints with an
 * HMAC-SHA256 signature. Delivery is best-effort — a dead webhook must
 * never fail the payroll request that triggered it.
 */
final class EventService
{
    public function __construct(private readonly PDO $pdo) {}

    public function emit(string $event, array $payload): void
    {
        $body = json_encode(['event' => $event, 'occurred_at' => date('c'), 'data' => $payload], JSON_UNESCAPED_UNICODE);
        try {
            $this->pdo->prepare('INSERT INTO integration_events_log (event, payload) VALUES (?, ?)')
                ->execute([$event, $body]);
            $stmt = $this->pdo->prepare("SELECT url, secret FROM webhook_endpoints WHERE is_active = TRUE AND (event = ? OR event = '*')");
            $stmt->execute([$event]);
            foreach ($stmt->fetchAll() as $hook) {
                $this->deliver($hook['url'], $event, $body, (string) $hook['secret']);
            }
        } catch (\Throwable $e) {
            error_log('EventService: ' . $e->getMessage());
        }
    }

    private function deliver(string $url, string $event, string $body, string $secret): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Event: ' . $event,
                'X-Signature: sha256=' . hash_hmac('sha256', $body, $secret),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
