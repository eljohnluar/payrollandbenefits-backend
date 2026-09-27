<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Tabscanner receipt-OCR provider (https://www.tabscanner.com).
 *
 * Two-step REST flow, called with plain curl so the project stays
 * Composer/Guzzle-free like the rest of the backend:
 *   POST https://api.tabscanner.com/{key}/process   -> { token, duplicate? }
 *   GET  https://api.tabscanner.com/{key}/result/{token} -> { status, data }
 *
 * Chosen over general-purpose OCR because it returns *structured* receipt
 * data (merchant, date, total, tax, line items) and includes duplicate /
 * tamper detection. Free Starter tier is 200 scans/month, no card required.
 *
 * Enable with TABSCANNER_API_KEY in .env (or Render env). When unset,
 * ClaimsService falls back to Google Vision, then to 'Skipped'.
 */
final class TabscannerService
{
    private const API = 'https://api.tabscanner.com/';
    private const AMOUNT_TOLERANCE = 10.0;
    private const MAX_AGE_DAYS = 30;

    public static function configured(): bool
    {
        return Config::tabscannerKey() !== '';
    }

    /** @return array<string,mixed> all ocr_* column values for the claim */
    public function verify(array $claim, string $imageBase64, string $mime): array
    {
        $out = [
            'ocr_provider' => 'tabscanner', 'ocr_status' => 'Error', 'ocr_token' => null,
            'ocr_merchant' => null, 'ocr_receipt_date' => null, 'ocr_amount' => null,
            'ocr_or_number' => null, 'ocr_tin' => null, 'ocr_confidence' => null,
            'ocr_flags' => null, 'ocr_checked_at' => gmdate('Y-m-d H:i:s'),
        ];
        try {
            $upload = $this->process($imageBase64, $mime);
            $token = (string) ($upload['token'] ?? $upload['duplicateToken'] ?? '');
            $out['ocr_token'] = $token !== '' ? $token : null;
            if ($token === '') {
                throw new RuntimeException('Tabscanner did not return a token: ' . $this->summarize($upload));
            }
            $data = $this->awaitResult($token);
            $fields = $this->mapFields($data);
            $out = array_merge($out, $fields);

            $flags = $this->compare($claim, $fields);
            if (!empty($upload['duplicate'])) {
                $flags[] = 'This receipt matches a previously submitted claim (possible duplicate).';
            }
            $out['ocr_flags'] = json_encode($flags, JSON_UNESCAPED_UNICODE);
            $out['ocr_status'] = $flags === [] ? 'Passed' : 'Flagged';
        } catch (\Throwable $e) {
            error_log('[tabscanner] ' . $e->getMessage());
            $out['ocr_flags'] = json_encode(
                ['Receipt OCR failed: ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 180) . ' The claim needs manual review.'],
                JSON_UNESCAPED_UNICODE
            );
            $out['ocr_status'] = 'Error';
        }
        return $out;
    }

    /** Free-tier usage counter for the current calendar month. */
    public static function monthlyUsage(): int
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM claims
              WHERE ocr_provider = 'tabscanner' AND ocr_status IN ('Passed','Flagged','Error')
                AND ocr_checked_at >= date_trunc('month', now())"
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /* ── REST calls ───────────────────────────────────── */

    /** Step 1: submit the receipt image for processing (multipart, field `file`). */
    private function process(string $imageBase64, string $mime): array
    {
        if (!preg_match('#^image/(jpeg|png|jpg)$#i', $mime)) {
            throw new RuntimeException('Tabscanner accepts JPG and PNG receipts only.');
        }
        $bytes = base64_decode($imageBase64, true);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Receipt image could not be decoded.');
        }
        $ext = stripos($mime, 'png') !== false ? 'png' : 'jpg';
        // Temp file keeps this working on PHP < 8.3 (no CURLFile::createWithContents).
        $tmp = tempnam(sys_get_temp_dir(), 'tsr');
        file_put_contents($tmp, $bytes);
        try {
            $file = new \CURLFile($tmp, $mime === 'image/jpg' ? 'image/jpeg' : $mime, 'receipt.' . $ext);
            $resp = $this->request('POST', self::API . rawurlencode(Config::tabscannerKey()) . '/process', [
                'file' => $file,
                'documentType' => 'receipt',
                'defaultDateParsing' => 'm/d',
            ], []);
        } finally {
            @unlink($tmp);
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            throw new RuntimeException('Tabscanner returned an unreadable response.');
        }
        if (isset($data['success']) && $data['success'] === false) {
            throw new RuntimeException('Tabscanner: ' . $this->summarize($data));
        }
        return $data;
    }

    /** Step 2: poll for the structured result (usually ready in 1-2s). */
    private function awaitResult(string $token, int $maxWaitSeconds = 15): array
    {
        $url = self::API . rawurlencode(Config::tabscannerKey()) . '/result/' . rawurlencode($token);
        $deadline = microtime(true) + $maxWaitSeconds;
        $last = [];
        do {
            $resp = $this->request('GET', $url, null, []);
            $last = json_decode($resp, true) ?: [];
            $status = strtolower((string) ($last['status'] ?? ''));
            if ($status === 'done' && isset($last['result'])) {
                $data = $last['result'];
                return is_array($data) ? $data : [];
            }
            if ($status === 'failed' || $status === 'error') {
                throw new RuntimeException('Tabscanner processing failed: ' . $this->summarize($last));
            }
            usleep(700_000); // 0.7s between polls
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Tabscanner result still pending after ' . $maxWaitSeconds . 's (' . $this->summarize($last) . ').');
    }

    private function request(string $method, string $url, array|string|null $body, array $headers): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
        ]);
        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if ($body !== null) {
            // Array bodies (with CURLFile) are sent as multipart/form-data automatically.
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            throw new RuntimeException('Network error talking to Tabscanner: ' . $err);
        }
        if ($status >= 400) {
            throw new RuntimeException('Tabscanner HTTP ' . $status . ': ' . substr((string) $resp, 0, 160));
        }
        return (string) $resp;
    }

    /* ── Field mapping + comparison ───────────────────── */

    /**
     * Map Tabscanner's structured data onto our ocr_* columns.
     * Key names vary by receipt type, so each field checks a few aliases.
     */
    private function mapFields(array $d): array
    {
        $est = $d['establishment'] ?? null;
        $merchant = is_array($est) ? ($est['name'] ?? null) : $est;

        $rawDate = $this->first($d, ['dateISO', 'date', 'transactionDate', 'receiptDate', 'purchaseDate']);
        $date = $this->normalizeDate(is_string($rawDate) || is_numeric($rawDate) ? (string) $rawDate : null);

        $amount = $this->first($d, ['total', 'grandTotal', 'amount', 'totalAmount']);
        $amount = is_numeric($amount) ? round((float) $amount, 2) : null;

        $or = $this->first($d, ['transactionId', 'transaction_id', 'receiptNumber', 'invoiceNumber', 'orNumber', 'referenceNumber']);
        $tin = $this->first($d, ['tin', 'taxId', 'vatNumber']);

        // Tabscanner reports per-field confidences; keep the weakest.
        $confidences = array_filter(
            array_map(
                static fn ($k) => isset($d[$k]) && is_numeric($d[$k]) ? (float) $d[$k] : null,
                ['totalConfidence', 'dateConfidence', 'establishmentConfidence']
            ),
            static fn ($v) => $v !== null
        );
        $confidence = $confidences !== [] ? round(min($confidences) * 100, 1) : null;

        return [
            'ocr_merchant' => $merchant !== null ? mb_substr((string) $merchant, 0, 120) : null,
            'ocr_receipt_date' => $date,
            'ocr_amount' => $amount,
            'ocr_or_number' => $or !== null ? mb_substr((string) $or, 0, 30) : null,
            'ocr_tin' => $tin !== null ? mb_substr((string) $tin, 0, 30) : null,
            'ocr_confidence' => $confidence,
        ];
    }

    /** @param string[] $keys @return mixed */
    private function first(array $data, array $keys)
    {
        foreach ($keys as $k) {
            if (isset($data[$k]) && $data[$k] !== '' && !is_array($data[$k])) {
                return $data[$k];
            }
        }
        return null;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime(str_replace(',', '', (string) $value));
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    /** Same verification rules as the Google Vision path. @return string[] */
    private function compare(array $claim, array $fields): array
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

    private function summarize(array $data): string
    {
        return mb_substr((string) json_encode($data, JSON_UNESCAPED_SLASHES), 0, 160);
    }
}
