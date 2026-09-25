<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Payslip e-mail delivery. Every send is recorded in email_outbox. When
 * MAIL_WEBHOOK_URL is configured (e.g. a Supabase Edge Function relaying
 * through Resend/Postmark) the payload is POSTed there for real delivery;
 * otherwise the mail is marked as outbox-only so the flow stays demoable
 * without SMTP credentials on this machine.
 */
final class MailService
{
    public function __construct(private readonly PDO $pdo) {}

    public function send(string $toEmail, string $subject, string $message, ?string $attachmentName = null): array
    {
        $webhook = (string) env('MAIL_WEBHOOK_URL', '');
        $channel = 'outbox';
        $status  = 'Sent';
        $detail  = 'Recorded in email_outbox; set MAIL_WEBHOOK_URL to relay through a live mail service.';

        if ($webhook !== '' && $toEmail !== '') {
            $payload = json_encode([
                'to'        => $toEmail,
                'subject'   => $subject,
                'text'      => $message,
                'attachment'=> $attachmentName,
            ], JSON_UNESCAPED_UNICODE);
            $ch = curl_init($webhook);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                $channel = 'webhook';
                $status  = 'Sent';
                $detail  = "Delivered via mail relay (HTTP {$code}).";
            } else {
                $status = 'Failed';
                $detail = 'Relay failed: HTTP ' . $code . ' ' . substr((string) $response, 0, 200);
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO email_outbox (to_email, subject, message, attachment_name, status, channel, detail)
             VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$toEmail, $subject, $message, $attachmentName, $status, $channel, $detail]);
        $outboxId = (int) $stmt->fetchColumn();

        $this->pdo->prepare('INSERT INTO notifications (user_name, title, message) VALUES (?, ?, ?)')
            ->execute(['All Staff', 'Payslip emailed', "Payslip sent to {$toEmail} — \"{$subject}\"."]);

        AuditService::log('Payslip Email Sent', 'Payslips', null, [
            'to' => $toEmail, 'subject' => $subject, 'channel' => $channel, 'status' => $status,
        ]);

        return ['outbox_id' => $outboxId, 'status' => $status, 'channel' => $channel, 'detail' => $detail, 'to' => $toEmail];
    }
}
