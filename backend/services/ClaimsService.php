<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class ClaimsService
{
    public function __construct(private readonly PDO $pdo) {}

    public function list(?string $employeeId, ?string $status, int $limit = 100): array
    {
        $sql = 'SELECT * FROM claims WHERE is_archived = FALSE';
        $params = [];
        if ($employeeId !== null && $employeeId !== '') { $sql .= ' AND employee_id = ?'; $params[] = $employeeId; }
        if ($status !== null && $status !== '')         { $sql .= ' AND status = ?';       $params[] = $status; }
        $sql .= ' ORDER BY claim_date DESC, id DESC LIMIT ?';
        $params[] = $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function categories(): array
    {
        return $this->pdo->query('SELECT * FROM claim_categories ORDER BY name')->fetchAll();
    }

    public function get(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM claims WHERE id = ? AND is_archived = FALSE');
        $stmt->execute([$id]);
        $claim = $stmt->fetch();
        if ($claim === false) {
            throw new RuntimeException("Claim {$id} not found.", 404);
        }
        return $claim;
    }

    /**
     * @param array{base64:string,mime:string}|null $receipt uploaded receipt, OCR-verified inline
     */
    public function submit(string $employeeId, string $category, float $amount, string $claimDate, string $description, ?array $receipt = null): array
    {
        $stmt = $this->pdo->prepare("SELECT first_name, last_name FROM employees WHERE id = ? AND status IN ('Active','On Leave') AND is_archived = FALSE");
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();
        if ($employee === false) {
            throw new RuntimeException('Select an active employee.', 422);
        }

        $cap = $this->pdo->prepare('SELECT max_amount FROM claim_categories WHERE name = ? OR id = ?');
        $cap->execute([$category, $category]);
        $maxAmount = $cap->fetchColumn();
        if ($maxAmount === false) {
            throw new RuntimeException("Unknown claim category '{$category}'.", 422);
        }
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.', 422);
        }
        if ($amount > (float) $maxAmount) {
            throw new RuntimeException("The amount exceeds this category's limit of " . number_format((float) $maxAmount, 2) . '.', 422);
        }
        if (\DateTime::createFromFormat('Y-m-d', $claimDate) === false) {
            throw new RuntimeException('claim_date must be an ISO date (YYYY-MM-DD).', 422);
        }

        $number = $this->nextClaimNumber($claimDate);
        $insert = $this->pdo->prepare(
            'INSERT INTO claims (claim_number, employee_name, employee_id, category, description, amount, claim_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'Pending\') RETURNING *'
        );
        $insert->execute([
            $number, trim($employee['first_name'] . ' ' . $employee['last_name']),
            $employeeId, $category, $description, $amount, $claimDate,
        ]);
        $claim = $insert->fetch();

        $ocr = $this->verifyReceipt($claim, $receipt);
        if ($ocr !== []) {
            $columns = array_keys($ocr);
            $set = implode(', ', array_map(static fn ($c) => "{$c} = ?", $columns));
            $upd = $this->pdo->prepare("UPDATE claims SET {$set} WHERE id = ?");
            $upd->execute([...array_values($ocr), $claim['id']]);
            $claim = array_merge($claim, $ocr);
        }

        AuditService::log('Claim Submitted', 'Claims', null, ['claim_number' => $number, 'employee_id' => $employeeId, 'ocr' => $ocr['ocr_status'] ?? null]);
        return $claim;
    }

    /**
     * Receipt verification. Never rejects a claim: OCR failures and missing
     * configuration only flag it for manual review.
     * Provider priority: Tabscanner (receipt-specialised, JPG/PNG) > Google
     * Vision (also handles PDF) > Skipped.
     */
    private function verifyReceipt(array $claim, ?array $receipt): array
    {
        $base64 = isset($receipt['base64']) ? (string) $receipt['base64'] : '';
        if ($base64 === '' || base64_decode($base64, true) === false) {
            return [
                'receipt_file' => null,
                'ocr_status' => 'No receipt',
                'ocr_flags' => json_encode(['No receipt uploaded — manual review required.']),
                'ocr_checked_at' => gmdate('Y-m-d H:i:s'),
            ];
        }
        $mime = (string) ($receipt['mime'] ?? 'image/jpeg');

        $objectKey = sprintf('claim-receipts/%s.%s', $claim['claim_number'], $mime === 'application/pdf' ? 'pdf' : 'jpg');
        $stored = $this->storeReceipt($objectKey, (string) base64_decode($base64), $mime);

        $ocr = ['receipt_file' => $stored ? $objectKey : null];
        if (TabscannerService::configured() && preg_match('#^image/(jpeg|png|jpg)$#i', $mime)) {
            $ocr = array_merge($ocr, (new TabscannerService())->verify($claim, $base64, $mime));
        } elseif (ReceiptOcrService::configured()) {
            $ocr = array_merge($ocr, (new ReceiptOcrService($this->pdo))->verify($claim, $base64, $mime));
            $ocr['ocr_provider'] = 'google-vision';
        } else {
            $ocr += [
                'ocr_status' => 'Skipped',
                'ocr_flags' => json_encode(['No receipt OCR provider is configured on this server — manual review required.']),
                'ocr_checked_at' => gmdate('Y-m-d H:i:s'),
            ];
        }
        return $ocr;
    }

    private function storeReceipt(string $objectKey, string $bytes, string $mime): bool
    {
        $url = Config::supabaseUrl();
        $key = Config::serviceRoleKey();
        if ($url === '' || $key === '' || $bytes === '') {
            return false; // Storage not configured — OCR still ran on the inline bytes.
        }
        $ch = curl_init("{$url}/storage/v1/object/{$objectKey}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $bytes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key,
                'Content-Type: ' . $mime,
                'x-upsert: true',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status >= 400) {
            error_log('[claims] receipt storage upload failed ' . $status . ' ' . substr((string) $resp, 0, 200));
        }
        return $status < 400;
    }

    /** HR review step. Payment itself goes through disburse() — Finance must release the cash. */
    public function decide(int $id, string $status): array
    {
        if (!in_array($status, ['Approved', 'Rejected'], true)) {
            throw new RuntimeException('status must be Approved or Rejected.', 422);
        }
        $claim = $this->get($id);
        if ($claim['status'] === 'Paid') {
            throw new RuntimeException('A disbursed claim can no longer be changed.', 422);
        }
        $this->pdo->prepare('UPDATE claims SET status = ? WHERE id = ?')->execute([$status, $id]);
        AuditService::log("Claim {$status}", 'Claims', null, ['claim_id' => $id]);
        if ($status === 'Approved') {
            $this->pdo->prepare("INSERT INTO notifications (user_name, title, message) VALUES ('Finance', 'Claim approved for disbursement', ?)")
                ->execute(["Claim {$claim['claim_number']} (" . number_format((float) $claim['amount'], 2) . ') awaits finance disbursement.']);
        }
        return $this->get($id);
    }

    /** Finance disbursement approval: releases the reimbursement to the employee. */
    public function disburse(int $id, int $userId, string $notes = ''): array
    {
        $claim = $this->get($id);
        if ($claim['status'] !== 'Approved') {
            throw new RuntimeException('Only an approved claim can be disbursed — Finance disbursement follows HR approval.', 422);
        }
        $this->pdo->prepare("UPDATE claims SET status = 'Paid', disbursed_by = ?, disbursed_at = now() WHERE id = ?")
            ->execute([$userId, $id]);
        AuditService::log('Claim Disbursed', 'Finance', $userId, ['claim_id' => $id, 'claim_number' => $claim['claim_number'], 'notes' => $notes]);
        $this->pdo->prepare('INSERT INTO notifications (user_name, title, message) VALUES (?, ?, ?)')
            ->execute([$claim['employee_name'], 'Claim disbursed', "Claim {$claim['claim_number']} was disbursed by Finance."]);
        return $this->get($id);
    }

    private function nextClaimNumber(string $claimDate): string
    {
        $prefix = 'CLM' . date('Ymd', strtotime($claimDate));
        $stmt = $this->pdo->prepare('SELECT claim_number FROM claims WHERE claim_number LIKE ? ORDER BY claim_number DESC LIMIT 1');
        $stmt->execute([$prefix . '%']);
        $last = (string) ($stmt->fetchColumn() ?: $prefix . '0000');
        $next = ((int) substr($last, strlen($prefix))) + 1;
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
