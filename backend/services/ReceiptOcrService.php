<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Google Cloud Vision receipt OCR without the Composer SDK: a service-account
 * JWT is signed with openssl and exchanged for an OAuth token, then the
 * DOCUMENT_TEXT_DETECTION REST endpoint is called with curl.
 *
 * Credentials: GOOGLE_SA_JSON (inline JSON) or GOOGLE_APPLICATION_CREDENTIALS
 * pointing at a Render secret file. Missing credentials => claims are simply
 * marked 'Skipped', never blocked.
 */
final class ReceiptOcrService
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const VISION_URL = 'https://vision.googleapis.com/v1/images:annotate';
    private const AMOUNT_TOLERANCE = 10.0;
    private const MAX_AGE_DAYS = 30;

    public static function configured(): bool
    {
        return Config::googleServiceAccount() !== null;
    }

    /** @return array<string,mixed> all ocr_* column values for the claim */
    public function verify(array $claim, string $imageBase64, string $mime): array
    {
        $out = [
            'ocr_status' => 'Error', 'ocr_merchant' => null, 'ocr_receipt_date' => null,
            'ocr_amount' => null, 'ocr_or_number' => null, 'ocr_tin' => null,
            'ocr_confidence' => null, 'ocr_flags' => null,
            'ocr_checked_at' => gmdate('Y-m-d H:i:s'),
        ];
        try {
            [$text, $confidence] = $this->annotate($imageBase64, $mime);
            $fields = $this->parse($text);
            $fields['ocr_confidence'] = $confidence;
            $out = array_merge($out, $fields);
            $flags = $this->compare($claim, $fields);
            $out['ocr_flags'] = json_encode($flags, JSON_UNESCAPED_UNICODE);
            $out['ocr_status'] = $flags === [] ? 'Passed' : 'Flagged';
        } catch (\Throwable $e) {
            error_log('[ocr] ' . $e->getMessage());
            $out['ocr_flags'] = json_encode(['OCR failed: ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 180) . ' The claim needs manual review.'], JSON_UNESCAPED_UNICODE);
            $out['ocr_status'] = 'Error';
        }
        return $out;
    }

    /** Free-tier usage counter for the current calendar month. */
    public static function monthlyUsage(): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM claims
              WHERE ocr_status IN ('Passed','Flagged','Error')
                AND ocr_checked_at >= date_trunc('month', now())"
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /* ── Vision call ──────────────────────────────────── */

    /** @return array{0:string,1:?float} extracted text and page confidence */
    private function annotate(string $base64, string $mime): array
    {
        if (!preg_match('#^(image/(jpeg|png|webp|tiff|heic|bmp)|application/pdf)$#', $mime)) {
            throw new RuntimeException("Unsupported receipt format ({$mime}).");
        }
        $payload = json_encode(['requests' => [[
            'image' => ['content' => $base64],
            'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
        ]]]);
        $ch = curl_init(self::VISION_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->accessToken()],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($resp === false) {
            throw new RuntimeException('Could not reach the Vision API.');
        }
        if ($status >= 400) {
            $decoded = json_decode((string) $resp, true);
            $message = (string) ($decoded['error']['message'] ?? substr((string) $resp, 0, 180));
            if (str_contains($message, 'billing')) {
                $message = 'Google Cloud billing is not enabled on this project — the Vision API requires it even for the free tier.';
            }
            throw new RuntimeException("Vision API error {$status}: {$message}");
        }
        $data = json_decode((string) $resp, true);
        $text = $data['responses'][0]['fullTextAnnotation']['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('No readable text found on the receipt.');
        }
        $confidence = $data['responses'][0]['textAnnotations'][0]['property']['confidence'] ?? null;
        return [$text, $confidence !== null ? round((float) $confidence * 100, 1) : null];
    }

    private function accessToken(): string
    {
        $sa = Config::googleServiceAccount();
        if ($sa === null) {
            throw new RuntimeException('Google Vision is not configured.');
        }
        $cache = sys_get_temp_dir() . '/trim-google-vision-token.json';
        if (is_readable($cache)) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c) && ($c['expires'] ?? 0) > time() + 60 && ($c['email'] ?? '') === $sa['client_email']) {
                return (string) $c['token'];
            }
        }
        $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $now = time();
        $signingInput = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            . '.' . $b64(json_encode([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/cloud-platform',
                'aud' => (string) ($sa['token_uri'] ?? self::TOKEN_URI),
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
        $pkey = openssl_pkey_get_private((string) $sa['private_key']);
        if ($pkey === false) {
            throw new RuntimeException('Service account private key is invalid.');
        }
        openssl_sign($signingInput, $signature, $pkey, 'sha256WithRSAEncryption');
        $jwt = $signingInput . '.' . $b64($signature);

        $ch = curl_init((string) ($sa['token_uri'] ?? self::TOKEN_URI));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = json_decode((string) $resp, true);
        if ($status >= 400 || !is_array($data) || empty($data['access_token'])) {
            throw new RuntimeException('Google token exchange failed (' . $status . ').');
        }
        @file_put_contents($cache, json_encode([
            'email' => $sa['client_email'],
            'token' => $data['access_token'],
            'expires' => time() + (int) ($data['expires_in'] ?? 3600) - 60,
        ]));
        return (string) $data['access_token'];
    }

    /* ── Text parsing ─────────────────────────────────── */

    /** @return array<string,mixed> extracted ocr_* fields (may be null per field) */
    public function parse(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []), static fn ($l) => $l !== ''));

        $merchant = null;
        foreach (array_slice($lines, 0, 5) as $line) {
            if (preg_match('/[A-Za-z]/', $line) && !preg_match('/^(date|receipt|invoice|vat|tin|or\s*#)/i', $line) && strlen($line) > 3) {
                $merchant = mb_substr($line, 0, 120);
                break;
            }
        }

        $date = null;
        foreach ($lines as $line) {
            if (preg_match('/\b(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])\/(20\d{2})\b/', $line, $m)) {
                $date = sprintf('%s-%02d-%02d', $m[3], (int) $m[1], (int) $m[2]);
                break;
            }
            if (preg_match('/\b(20\d{2})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\b/', $line, $m)) {
                $date = "{$m[1]}-{$m[2]}-{$m[3]}";
                break;
            }
            if (preg_match('/\b(0?[1-9]|[12]\d|3[01])[- ](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*[- ](20\d{2})\b/i', $line, $m)) {
                $ts = strtotime(str_replace(',', '', "{$m[2]} {$m[1]}, {$m[3]}"));
                if ($ts !== false) {
                    $date = date('Y-m-d', $ts);
                    break;
                }
            }
        }

        $amount = null;
        $totalLines = array_values(array_filter($lines, static fn ($l) => (bool) preg_match('/\b(total|amount\s*due|grand\s*total|net\s*pay|vat\s*inclusive)\b/i', $l)));
        $amountSource = $totalLines !== [] ? end($totalLines) : implode("\n", $lines);
        if (preg_match_all('/(?:₱|PHP|P)\s*([\d,]+(?:\.\d{1,2})?)/iu', $amountSource, $m) && $m[1] !== []) {
            $values = array_map(static fn ($v) => (float) str_replace(',', '', $v), $m[1]);
            $amount = max($values);
        } elseif ($totalLines !== [] && preg_match('/([\d,]+\.\d{2})\s*$/u', (string) end($totalLines), $m)) {
            $amount = (float) str_replace(',', '', $m[1]);
        }

        $orNumber = null;
        if (preg_match('/\b(?:or\s*#?|official\s*receipt|receipt\s*(?:no|number|#)|invoice\s*(?:no|number|#)?|acknowledgement\s*receipt)\s*[:#\s]?\s*([A-Z0-9][A-Z0-9-]{3,20})\b/i', $text, $m)) {
            $orNumber = mb_substr($m[1], 0, 30);
        }

        $tin = null;
        if (preg_match('/\b(\d{3}-\d{3}-\d{3}(?:-\d{3})?)\b/', $text, $m)) {
            $tin = $m[1];
        } elseif (preg_match('/\btin[:\s]*([\d-]{9,14})\b/i', $text, $m)) {
            $tin = $m[1];
        }

        return [
            'ocr_merchant' => $merchant,
            'ocr_receipt_date' => $date,
            'ocr_amount' => $amount !== null ? round($amount, 2) : null,
            'ocr_or_number' => $orNumber,
            'ocr_tin' => $tin,
        ];
    }

    /** @return string[] human-readable mismatch flags; empty means the receipt checks out */
    public function compare(array $claim, array $fields): array
    {
        $flags = [];
        $claimed = (float) ($claim['amount'] ?? 0);

        if ($fields['ocr_amount'] === null) {
            $flags[] = 'Could not read a total amount from the receipt.';
        } elseif (abs((float) $fields['ocr_amount'] - $claimed) > self::AMOUNT_TOLERANCE) {
            $flags[] = sprintf(
                'Receipt total %s differs from the claimed %s by more than %s.',
                number_format((float) $fields['ocr_amount'], 2),
                number_format($claimed, 2),
                number_format(self::AMOUNT_TOLERANCE, 2)
            );
        }

        if (empty($fields['ocr_receipt_date'])) {
            $flags[] = 'Could not read a date from the receipt.';
        } else {
            $receiptTs = strtotime((string) $fields['ocr_receipt_date']);
            $claimTs = strtotime((string) ($claim['claim_date'] ?? 'now'));
            if ($receiptTs !== false && $claimTs !== false) {
                $age = ($claimTs - $receiptTs) / 86400;
                if ($age > self::MAX_AGE_DAYS) {
                    $flags[] = sprintf('Receipt is %.0f days old — beyond the %d-day claim window.', $age, self::MAX_AGE_DAYS);
                } elseif ($age < -1) {
                    $flags[] = 'Receipt date is after the claim date.';
                }
            }
        }

        if (empty($fields['ocr_merchant'])) {
            $flags[] = 'Could not identify the merchant on the receipt.';
        }

        return $flags;
    }
}
