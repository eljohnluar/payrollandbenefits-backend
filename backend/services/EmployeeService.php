<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class EmployeeService
{
    public function __construct(private readonly PDO $pdo) {}

    public function all(): array
    {
        return $this->pdo->query(
            "SELECT id, code, first_name, last_name, department, position, employment_type,
                    basic_salary, status, hire_date, email
               FROM employees WHERE is_archived = FALSE ORDER BY department, last_name, first_name"
        )->fetchAll();
    }

    public function find(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM employees WHERE id = ?');
        $stmt->execute([$id]);
        $employee = $stmt->fetch();
        if ($employee === false) {
            throw new RuntimeException("Employee {$id} not found.", 404);
        }
        return $employee;
    }

    /** The four profile sections plus claims history, in one round trip. */
    public function profile(string $id): array
    {
        $employee = $this->find($id);
        $section = function (string $sql) use ($id): array {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$id]);
            return $stmt->fetchAll();
        };
        return $employee + [
            'competencies'   => $section('SELECT * FROM competency_assessments WHERE employee_id = ? ORDER BY assessment_date DESC'),
            'trainings'      => $section('SELECT * FROM training_records WHERE employee_id = ? ORDER BY completed_date DESC'),
            'awards'         => $section('SELECT * FROM recognition_awards WHERE employee_id = ? ORDER BY award_date DESC'),
            'benefits'       => $section('SELECT * FROM benefit_enrollments WHERE employee_id = ? ORDER BY effective_date DESC'),
            'claims'         => $section('SELECT * FROM claims WHERE employee_id = ? ORDER BY claim_date DESC LIMIT 10'),
            'leave_balance'  => $section('SELECT * FROM leave_balances WHERE employee_id = ? ORDER BY year DESC, leave_type'),
            'salary_history' => $section('SELECT * FROM salary_history WHERE employee_id = ? ORDER BY effective_date DESC'),
            'allowances'     => $section('SELECT * FROM allowances WHERE employee_id = ? AND is_active = TRUE ORDER BY type'),
            'all_allowances' => $section('SELECT * FROM allowances WHERE employee_id = ? ORDER BY is_active DESC, type'),
            'performance'    => $section('SELECT * FROM performance_ratings WHERE employee_id = ? ORDER BY review_date DESC'),
            'loans'          => $section('SELECT * FROM loans WHERE employee_id = ? AND status = \'Active\' ORDER BY approved_at DESC'),
        ];
    }

    private const CREATABLE = [
        'code','first_name','middle_name','last_name','suffix','email','mobile','birth_date','gender',
        'department','position','employment_type','hire_date','basic_salary','status','sss','philhealth',
        'pagibig','tin','ewallet_provider','ewallet_account','ewallet_name',
    ];

    /** Builds the column list / bind values, skipping anything blank. */
    private static function fields(array $body, array $allowed): array
    {
        $cols = [];
        $vals = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $body) && $body[$col] !== '' && $body[$col] !== null) {
                $cols[] = $col;
                $vals[] = is_string($body[$col]) ? trim($body[$col]) : $body[$col];
            }
        }
        return [$cols, $vals];
    }

    private function nextEmployeeId(): string
    {
        $last = (string) ($this->pdo->query("SELECT id FROM employees WHERE id LIKE 'emp-%' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 'emp-000');
        $n = (int) substr($last, 4) + 1;
        return 'emp-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    /** SSS and PhilHealth numbers are mandatory under Philippine law. */
    private static function assertMandatoryIds(array $body, bool $require): void
    {
        foreach (['sss', 'philhealth'] as $field) {
            $value = trim((string) ($body[$field] ?? ''));
            if (($require && $value === '') || (!$require && array_key_exists($field, $body) && $value === '')) {
                throw new RuntimeException(strtoupper($field) . ' number is mandatory for every employee.', 422);
            }
        }
    }

    public function create(array $body): array
    {
        self::assertMandatoryIds($body, true);
        $id = trim((string) ($body['id'] ?? '')) ?: $this->nextEmployeeId();
        $dup = $this->pdo->prepare('SELECT 1 FROM employees WHERE id = ? OR code = ?');
        $dup->execute([$id, $body['code'] ?? '']);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('An employee with that id or code already exists.', 409);
        }
        [$cols, $vals] = self::fields($body, self::CREATABLE);
        array_unshift($cols, 'id');
        array_unshift($vals, $id);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO employees (' . implode(', ', $cols) . ") VALUES ($placeholders) RETURNING *";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($vals);
        $employee = $stmt->fetch();
        AuditService::log('Employee Created', 'Employees', null, ['employee_id' => $id]);
        return $employee;
    }

    public function update(string $id, array $body): array
    {
        self::assertMandatoryIds($body, false);
        $this->find($id);
        [$cols, $vals] = self::fields($body, array_merge(self::CREATABLE, ['ewallet_primary']));
        if ($cols === []) {
            return $this->find($id);
        }
        $set = implode(', ', array_map(static fn ($c) => "$c = ?", $cols));
        $vals[] = $id;
        $this->pdo->prepare("UPDATE employees SET $set WHERE id = ?")->execute($vals);
        AuditService::log('Employee Updated', 'Employees', null, ['employee_id' => $id]);
        return $this->find($id);
    }

    // Deleting an employee is an archive operation: see ArchiveService + DELETE /api/employees/{id}.

    // ── Compensation sub-resources (allowances / loans / salary history) ──
    public function addAllowance(string $employeeId, array $body): array
    {
        $this->find($employeeId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO allowances (employee_id, type, amount, frequency, description, start_date, end_date, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, TRUE) RETURNING *'
        );
        $stmt->execute([
            $employeeId,
            Http::requireString($body, 'type'),
            (float) ($body['amount'] ?? 0),
            (string) ($body['frequency'] ?? 'Monthly'),
            (string) ($body['description'] ?? ''),
            $body['start_date'] ?? null,
            $body['end_date'] ?? null,
        ]);
        return $stmt->fetch();
    }

    public function deleteAllowance(int $id): array
    {
        $this->pdo->prepare('DELETE FROM allowances WHERE id = ?')->execute([$id]);
        return ['deleted' => $id];
    }

    private const ALLOWANCE_UPDATABLE = ['type', 'amount', 'frequency', 'description', 'start_date', 'end_date', 'is_active'];

    public function updateAllowance(int $id, array $body): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM allowances WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetch() === false) {
            throw new RuntimeException("Allowance {$id} not found.", 404);
        }
        [$cols, $vals] = self::fields($body, self::ALLOWANCE_UPDATABLE);
        if ($cols !== []) {
            $vals[] = $id;
            $set = implode(', ', array_map(static fn ($c) => "$c = ?", $cols));
            $this->pdo->prepare("UPDATE allowances SET $set WHERE id = ?")->execute($vals);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM allowances WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function addLoan(string $employeeId, array $body): array
    {
        $this->find($employeeId);
        $stmt = $this->pdo->prepare(
            "INSERT INTO loans (employee_id, loan_type, principal, monthly_deduction, balance, status, approved_at)
             VALUES (?, ?, ?, ?, ?, 'Active', ?) RETURNING *"
        );
        $principal = (float) ($body['principal'] ?? 0);
        $stmt->execute([
            $employeeId,
            Http::requireString($body, 'loan_type'),
            $principal,
            (float) ($body['monthly_deduction'] ?? 0),
            (float) ($body['balance'] ?? $principal),
            $body['approved_at'] ?? null,
        ]);
        return $stmt->fetch();
    }

    public function deleteLoan(int $id): array
    {
        $this->pdo->prepare('DELETE FROM loans WHERE id = ?')->execute([$id]);
        return ['deleted' => $id];
    }

    private const LOAN_UPDATABLE = ['loan_type', 'principal', 'monthly_deduction', 'balance', 'status', 'approved_at'];

    public function updateLoan(int $id, array $body): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM loans WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetch() === false) {
            throw new RuntimeException("Loan {$id} not found.", 404);
        }
        [$cols, $vals] = self::fields($body, self::LOAN_UPDATABLE);
        if ($cols !== []) {
            $vals[] = $id;
            $set = implode(', ', array_map(static fn ($c) => "$c = ?", $cols));
            $this->pdo->prepare("UPDATE loans SET $set WHERE id = ?")->execute($vals);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM loans WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /** Records a new basic salary and writes a salary_history entry for the effective date. */
    public function updateSalary(string $employeeId, array $body): array
    {
        $this->find($employeeId);
        $amount = (float) Http::requireString($body, 'basic_salary');
        $date = (string) ($body['effective_date'] ?? date('Y-m-d'));
        $reason = (string) ($body['reason'] ?? 'Adjustment');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE employees SET basic_salary = ? WHERE id = ?')->execute([$amount, $employeeId]);
            $this->pdo->prepare(
                'INSERT INTO salary_history (employee_id, basic_salary, effective_date, reason) VALUES (?, ?, ?, ?)'
            )->execute([$employeeId, $amount, $date, $reason]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        AuditService::log('Salary Updated', 'Compensation', null, ['employee_id' => $employeeId, 'amount' => $amount]);
        return $this->find($employeeId);
    }
}
