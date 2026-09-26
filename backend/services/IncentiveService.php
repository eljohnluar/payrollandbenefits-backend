<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Incentives module: structures (what an employee can earn), earnings
 * (auto-computed or metric-recorded per period) and the analytics summary.
 * Earned amounts are claimed by payroll via PayrollService::computeLine.
 */
final class IncentiveService
{
    public const TYPES = ['Performance', 'Sales', 'Attendance', 'Productivity', 'Referral', 'Retention', 'Spot', 'Team'];
    public const FREQUENCIES = ['Monthly', 'Quarterly', 'Annual', 'One-Time'];

    /** Types HR must record a metric for; the rest evaluate from HRMS data. */
    private const MANUAL_TYPES = ['Sales', 'Productivity', 'Referral', 'Spot', 'Team'];

    public function __construct(private readonly PDO $pdo) {}

    /* ── Structures ───────────────────────────────────── */

    public function structures(?string $employeeId = null): array
    {
        $sql = "SELECT s.*, e.first_name || ' ' || e.last_name AS employee_name, e.department, e.basic_salary
                  FROM incentive_structures s JOIN employees e ON e.id = s.employee_id
                 WHERE e.is_archived = FALSE";
        $params = [];
        if ($employeeId !== null && $employeeId !== '') {
            $sql .= ' AND s.employee_id = ?';
            $params[] = $employeeId;
        }
        $sql .= ' ORDER BY s.is_active DESC, s.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function create(array $body): array
    {
        $employeeId = trim((string) ($body['employee_id'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $type = (string) ($body['type'] ?? '');
        $rateType = (string) ($body['rate_type'] ?? 'Fixed');
        $frequency = (string) ($body['frequency'] ?? 'Monthly');
        $rate = (float) ($body['rate'] ?? -1);
        $effective = trim((string) ($body['effective_date'] ?? '')) ?: date('Y-m-d');
        $end = trim((string) ($body['end_date'] ?? '')) ?: null;

        if ($name === '' || !in_array($type, self::TYPES, true)) {
            throw new RuntimeException('A name and a valid incentive type are required.', 422);
        }
        if (!in_array($rateType, ['Fixed', 'Percentage'], true) || !in_array($frequency, self::FREQUENCIES, true)) {
            throw new RuntimeException('Invalid rate type or frequency.', 422);
        }
        if ($rate < 0) {
            throw new RuntimeException('The incentive rate is required.', 422);
        }
        $stmt = $this->pdo->prepare("SELECT id FROM employees WHERE id = ? AND is_archived = FALSE");
        $stmt->execute([$employeeId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('Select an existing employee.', 422);
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO incentive_structures
                (employee_id, name, type, rate_type, rate, frequency, target, eligibility, effective_date, end_date)
             VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING *'
        );
        $ins->execute([
            $employeeId, $name, $type, $rateType, $rate, $frequency,
            ($body['target'] ?? '') !== '' && $body['target'] !== null ? (float) $body['target'] : null,
            trim((string) ($body['eligibility'] ?? '')) ?: null, $effective, $end,
        ]);
        $row = $ins->fetch();
        AuditService::log('Incentive Structure Created', 'Compensation', null, ['id' => $row['id'], 'type' => $type]);
        return $row;
    }

    public function update(int $id, array $body): array
    {
        $row = $this->findStructure($id);
        $fields = [
            'name' => trim((string) ($body['name'] ?? $row['name'])),
            'type' => in_array($body['type'] ?? '', self::TYPES, true) ? $body['type'] : $row['type'],
            'rate_type' => in_array($body['rate_type'] ?? '', ['Fixed', 'Percentage'], true) ? $body['rate_type'] : $row['rate_type'],
            'rate' => isset($body['rate']) && $body['rate'] !== '' ? max(0.0, (float) $body['rate']) : $row['rate'],
            'frequency' => in_array($body['frequency'] ?? '', self::FREQUENCIES, true) ? $body['frequency'] : $row['frequency'],
            'target' => array_key_exists('target', $body) ? (($body['target'] === '' || $body['target'] === null) ? null : (float) $body['target']) : $row['target'],
            'eligibility' => array_key_exists('eligibility', $body) ? (trim((string) $body['eligibility']) ?: null) : $row['eligibility'],
            'effective_date' => trim((string) ($body['effective_date'] ?? $row['effective_date'])),
            'end_date' => array_key_exists('end_date', $body) ? (trim((string) $body['end_date']) ?: null) : $row['end_date'],
            'is_active' => array_key_exists('is_active', $body) ? (bool) $body['is_active'] : (bool) $row['is_active'],
        ];
        $stmt = $this->pdo->prepare(
            'UPDATE incentive_structures SET name = ?, type = ?, rate_type = ?, rate = ?, frequency = ?,
                    target = ?, eligibility = ?, effective_date = ?, end_date = ?, is_active = ?
              WHERE id = ? RETURNING *'
        );
        $stmt->execute([
            $fields['name'], $fields['type'], $fields['rate_type'], $fields['rate'], $fields['frequency'],
            $fields['target'], $fields['eligibility'], $fields['effective_date'], $fields['end_date'],
            $fields['is_active'], $id,
        ]);
        AuditService::log('Incentive Structure Updated', 'Compensation', null, ['id' => $id]);
        return $stmt->fetch();
    }

    public function delete(int $id): array
    {
        $this->findStructure($id);
        $this->pdo->prepare('UPDATE incentive_structures SET is_active = FALSE WHERE id = ?')->execute([$id]);
        AuditService::log('Incentive Structure Deactivated', 'Compensation', null, ['id' => $id]);
        return ['ok' => true];
    }

    private function findStructure(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM incentive_structures WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException("Incentive structure {$id} not found.", 404);
        }
        return $row;
    }

    /* ── Earnings ─────────────────────────────────────── */

    public function earnings(?string $employeeId, ?string $period, ?string $status): array
    {
        $sql = "SELECT en.*, s.name AS structure_name, e.department
                  FROM incentive_earnings en
                  JOIN employees e ON e.id = en.employee_id
                  LEFT JOIN incentive_structures s ON s.id = en.incentive_structure_id
                 WHERE e.is_archived = FALSE";
        $params = [];
        if ($employeeId !== null && $employeeId !== '') { $sql .= ' AND en.employee_id = ?'; $params[] = $employeeId; }
        if ($period !== null && $period !== '')         { $sql .= ' AND en.period = ?';       $params[] = $period; }
        if ($status !== null && $status !== '')         { $sql .= ' AND en.status = ?';       $params[] = $status; }
        $sql .= ' ORDER BY en.period DESC, en.created_at DESC LIMIT 500';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Auto-evaluate Performance / Attendance / Retention structures for the
     * payroll period. Sales, Productivity, Referral, Spot and Team earnings
     * come from recordMetric() because their actuals live outside this system.
     */
    public function computePeriod(string $start, string $end): array
    {
        $this->assertDate($start, 'period_start');
        $this->assertDate($end, 'period_end');
        $period = date('Y-m', strtotime($start));
        $month = (int) date('n', strtotime($start));

        $stmt = $this->pdo->prepare(
            "SELECT s.*, e.basic_salary, e.hire_date
               FROM incentive_structures s JOIN employees e ON e.id = s.employee_id
              WHERE s.is_active = TRUE AND e.is_archived = FALSE
                AND s.effective_date <= ? AND (s.end_date IS NULL OR s.end_date >= ?)"
        );
        $stmt->execute([$end, $start]);
        $structures = $stmt->fetchAll();

        $insert = $this->pdo->prepare(
            'INSERT INTO incentive_earnings (employee_id, incentive_structure_id, period, type, basis, amount)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (incentive_structure_id, period) DO NOTHING'
        );
        $created = [];
        foreach ($structures as $s) {
            if (in_array($s['type'], self::MANUAL_TYPES, true)) {
                continue;
            }
            if (!$this->frequencyApplies($s['frequency'], $month)) {
                continue;
            }
            $result = match ($s['type']) {
                'Performance' => $this->evaluatePerformance($s, $start, $end),
                'Attendance'  => $this->evaluateAttendance($s, $start, $end),
                'Retention'   => $this->evaluateRetention($s, $end),
                default => null,
            };
            if ($result === null) {
                continue;
            }
            [$basis, $amount] = $result;
            if ($amount <= 0) {
                continue;
            }
            $insert->execute([$s['employee_id'], $s['id'], $period, $s['type'], $basis, round($amount, 2)]);
            if ($insert->rowCount() > 0) {
                $created[] = ['structure' => $s['name'], 'employee_id' => $s['employee_id'], 'type' => $s['type'], 'basis' => $basis, 'amount' => round($amount, 2)];
            }
        }
        AuditService::log('Incentives Computed', 'Compensation', null, ['period' => $period, 'created' => count($created)]);
        return ['period' => $period, 'created' => $created];
    }

    private function frequencyApplies(string $frequency, int $month): bool
    {
        return match ($frequency) {
            'Monthly' => true,
            'Quarterly' => in_array($month, [3, 6, 9, 12], true),
            'Annual' => $month === 12,
            'One-Time' => true,
            default => false,
        };
    }

    private function evaluatePerformance(array $s, string $start, string $end): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rating FROM performance_ratings
              WHERE employee_id = ? AND review_date BETWEEN ? AND ? ORDER BY review_date DESC LIMIT 1'
        );
        $stmt->execute([$s['employee_id'], $start, $end]);
        $rating = $stmt->fetchColumn();
        if ($rating === false || $rating === null || trim((string) $rating) === '') {
            return null;
        }
        $rating = (float) $rating;
        if ($s['rate_type'] === 'Percentage') {
            $scale = (float) ($s['target'] ?: 5);
            return [sprintf('Rating %.1f/%.0f at %s%% of basic', $rating, $scale, rtrim(rtrim(number_format((float) $s['rate'], 2), '0'), '.')),
                (float) $s['basic_salary'] * min(1.0, $rating / $scale) * (float) $s['rate'] / 100];
        }
        return [sprintf('Rating %.1f x %s per point', $rating, $this->money((float) $s['rate'])),
            $rating * (float) $s['rate']];
    }

    private function evaluateAttendance(array $s, string $start, string $end): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FILTER (WHERE status = 'A') AS absences, COUNT(*) AS logged
               FROM attendance_logs WHERE employee_id = ? AND log_date BETWEEN ? AND ?"
        );
        $stmt->execute([$s['employee_id'], $start, $end]);
        $row = $stmt->fetch();
        if ((int) $row['logged'] === 0 || (int) $row['absences'] > 0) {
            return null;
        }
        $amount = $s['rate_type'] === 'Percentage'
            ? (float) $s['basic_salary'] * (float) $s['rate'] / 100
            : (float) $s['rate'];
        return ['Perfect attendance for the period', $amount];
    }

    private function evaluateRetention(array $s, string $end): ?array
    {
        $stmt = $this->pdo->prepare('SELECT hire_date FROM employees WHERE id = ?');
        $stmt->execute([$s['employee_id']]);
        $hire = $stmt->fetchColumn();
        if ($hire === false) {
            return null;
        }
        $years = floor((strtotime($end) - strtotime((string) $hire)) / (365.25 * 86400));
        $needed = (float) ($s['target'] ?: 1);
        if ($years < $needed) {
            return null;
        }
        $amount = $s['rate_type'] === 'Percentage'
            ? (float) $s['basic_salary'] * (float) $s['rate'] / 100
            : (float) $s['rate'];
        return [sprintf('%.0f years of service (min %.0f)', $years, $needed), $amount];
    }

    /** HR-entered actuals for Sales / Productivity / Referral / Spot / Team. */
    public function recordMetric(int $structureId, string $period, float $value, string $note): array
    {
        $s = $this->findStructure($structureId);
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new RuntimeException('Period must be YYYY-MM.', 422);
        }
        $basic = 0.0;
        $stmt = $this->pdo->prepare('SELECT basic_salary, department FROM employees WHERE id = ?');
        $stmt->execute([$s['employee_id']]);
        $emp = $stmt->fetch();
        if ($emp !== false) {
            $basic = (float) $emp['basic_salary'];
        }

        $amount = match ($s['type']) {
            'Sales' => $s['rate_type'] === 'Percentage' ? $value * (float) $s['rate'] / 100 : $value + (float) $s['rate'],
            'Productivity' => max(0.0, $value - (float) ($s['target'] ?? 0)) * (float) $s['rate'],
            'Referral', 'Spot' => $value,
            'Team' => $value,
            default => throw new RuntimeException("{$s['type']} incentives are computed automatically, not recorded.", 422),
        };
        if ($amount <= 0) {
            throw new RuntimeException('The recorded metric produces no incentive amount.', 422);
        }
        $basis = trim($note) !== '' ? $note : sprintf('%s metric: %s', $s['type'], $this->money($value));

        if ($s['type'] === 'Team') {
            // value = total team pot; split equally across the owner's department.
            $members = $this->pdo->prepare(
                "SELECT id FROM employees WHERE department = ? AND status IN ('Active','On Leave') AND is_archived = FALSE"
            );
            $members->execute([$emp['department'] ?? '']);
            $ids = array_column($members->fetchAll(), 'id');
            if ($ids === []) {
                throw new RuntimeException('The team has no members to distribute to.', 422);
            }
            $share = round($amount / count($ids), 2);
            $ins = $this->pdo->prepare(
                'INSERT INTO incentive_earnings (employee_id, incentive_structure_id, period, type, basis, amount)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($ids as $mid) {
                $ins->execute([$mid, null, $period, 'Team', $basis . sprintf(' (team share of %s)', $this->money($amount)), $share]);
            }
            AuditService::log('Team Incentive Recorded', 'Compensation', null, ['structure' => $structureId, 'members' => count($ids), 'total' => $amount]);
            return ['ok' => true, 'shared_with' => count($ids), 'each' => $share, 'total' => $amount];
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO incentive_earnings (employee_id, incentive_structure_id, period, type, basis, amount)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$s['employee_id'], $structureId, $period, $s['type'], $basis, round($amount, 2)]);
        AuditService::log('Incentive Metric Recorded', 'Compensation', null, ['structure' => $structureId, 'amount' => $amount]);
        return ['ok' => true, 'amount' => round($amount, 2)];
    }

    /* ── Analytics ────────────────────────────────────── */

    public function summary(): array
    {
        $totals = $this->pdo->query(
            "SELECT COALESCE(SUM(amount),0) AS total,
                    COALESCE(SUM(amount) FILTER (WHERE status = 'Paid'), 0) AS paid,
                    COALESCE(SUM(amount) FILTER (WHERE status = 'Earned'), 0) AS pending
               FROM incentive_earnings"
        )->fetch();
        return [
            'totals' => $totals,
            'by_type' => $this->pdo->query(
                'SELECT type, COUNT(*) AS records, COALESCE(SUM(amount),0) AS total
                   FROM incentive_earnings GROUP BY type ORDER BY total DESC'
            )->fetchAll(),
            'by_department' => $this->pdo->query(
                'SELECT e.department, COALESCE(SUM(en.amount),0) AS total
                   FROM incentive_earnings en JOIN employees e ON e.id = en.employee_id
                  GROUP BY e.department ORDER BY total DESC LIMIT 8'
            )->fetchAll(),
            'top_earners' => $this->pdo->query(
                'SELECT en.employee_id, e.first_name || \' \' || e.last_name AS name, SUM(en.amount) AS total
                   FROM incentive_earnings en JOIN employees e ON e.id = en.employee_id
                  GROUP BY en.employee_id, name ORDER BY total DESC LIMIT 5'
            )->fetchAll(),
            'monthly_trend' => $this->pdo->query(
                'SELECT period, COALESCE(SUM(amount),0) AS total
                   FROM incentive_earnings GROUP BY period ORDER BY period DESC LIMIT 12'
            )->fetchAll(),
        ];
    }

    /* ── Helpers ──────────────────────────────────────── */

    private function money(float $v): string
    {
        return 'PHP ' . number_format($v, 2);
    }

    private function assertDate(string $value, string $field): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new RuntimeException("{$field} must be YYYY-MM-DD.", 422);
        }
    }
}
