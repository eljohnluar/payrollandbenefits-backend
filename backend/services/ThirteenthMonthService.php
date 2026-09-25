<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/** 13th month pay: computed once per employee per year (basic salary x months worked / 12). */
final class ThirteenthMonthService
{
    public function __construct(private readonly PDO $pdo) {}

    public function list(?int $year): array
    {
        if ($year) {
            $stmt = $this->pdo->prepare('SELECT * FROM thirteenth_month WHERE year = ? ORDER BY department, employee_name');
            $stmt->execute([$year]);
            return $stmt->fetchAll();
        }
        return $this->pdo->query('SELECT * FROM thirteenth_month ORDER BY year DESC, department, employee_name')->fetchAll();
    }

    /** Upserts the computed 13th-month figure for every active / on-leave employee. */
    public function generate(int $year): array
    {
        $employees = $this->pdo->query(
            "SELECT id, first_name, last_name, department, position, basic_salary, hire_date
               FROM employees WHERE status IN ('Active','On Leave') AND is_archived = FALSE ORDER BY department, last_name"
        )->fetchAll();

        $stmt = $this->pdo->prepare(
            'INSERT INTO thirteenth_month
                (employee_id, employee_name, department, monthly_basic, months_worked, computed_amount, year, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'Pending\')
             ON CONFLICT (employee_id, year) DO UPDATE
               SET department = EXCLUDED.department, monthly_basic = EXCLUDED.monthly_basic,
                   months_worked = EXCLUDED.months_worked, computed_amount = EXCLUDED.computed_amount
             RETURNING *'
        );
        $count = 0;
        foreach ($employees as $e) {
            $months = $this->monthsWorkedInYear((string) $e['hire_date'], $year);
            $basic = (float) $e['basic_salary'];
            $computed = round($basic * $months / 12, 2);
            $stmt->execute([
                $e['id'],
                trim($e['first_name'] . ' ' . $e['last_name']),
                $e['department'],
                $basic,
                $months,
                $computed,
                $year,
            ]);
            $count++;
        }
        AuditService::log('13th Month Generated', 'Benefits', null, ['year' => $year, 'employees' => $count]);
        return ['year' => $year, 'generated' => $count, 'data' => $this->list($year)];
    }

    private function monthsWorkedInYear(string $hireDate, int $year): float
    {
        $ts = strtotime($hireDate);
        if ($ts === false) {
            return 12.0;
        }
        $hireYear = (int) date('Y', $ts);
        if ($hireYear < $year)  return 12.0;
        if ($hireYear > $year)  return 0.0;
        return (float) (12 - ((int) date('n', $ts)) + 1);
    }

    public function setStatus(int $id, string $status): array
    {
        if (!in_array($status, ['Pending', 'Approved', 'Paid'], true)) {
            throw new RuntimeException('status must be Pending, Approved or Paid.', 422);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM thirteenth_month WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetch() === false) {
            throw new RuntimeException("13th month record {$id} not found.", 404);
        }
        $this->pdo->prepare('UPDATE thirteenth_month SET status = ? WHERE id = ?')->execute([$status, $id]);
        $get = $this->pdo->prepare('SELECT * FROM thirteenth_month WHERE id = ?');
        $get->execute([$id]);
        return $get->fetch();
    }
}
