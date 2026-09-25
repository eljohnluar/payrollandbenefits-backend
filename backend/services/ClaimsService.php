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

    public function submit(string $employeeId, string $category, float $amount, string $claimDate, string $description): array
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

        AuditService::log('Claim Submitted', 'Claims', null, ['claim_number' => $number, 'employee_id' => $employeeId]);
        return $claim;
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
