<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class BenefitsService
{
    public function __construct(private readonly PDO $pdo) {}

    public function plans(): array
    {
        return $this->pdo->query('SELECT * FROM benefit_plans WHERE is_active = TRUE ORDER BY plan_type, plan_name')->fetchAll();
    }

    public function enrollments(?string $employeeId): array
    {
        if ($employeeId === null || $employeeId === '') {
            return $this->pdo->query('SELECT * FROM benefit_enrollments WHERE is_archived = FALSE ORDER BY employee_name')->fetchAll();
        }
        $stmt = $this->pdo->prepare('SELECT * FROM benefit_enrollments WHERE employee_id = ? AND is_archived = FALSE ORDER BY effective_date DESC');
        $stmt->execute([$employeeId]);
        return $stmt->fetchAll();
    }

    public function enroll(string $employeeId, int $planId, int $dependents = 0): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT first_name, last_name, employment_type FROM employees
              WHERE id = ? AND status IN ('Active','On Leave') AND is_archived = FALSE"
        );
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();
        if ($employee === false) {
            throw new RuntimeException('Select an active employee.', 422);
        }

        // Company policy: benefits are reserved for regular employees, and only
        // when their attendance in the trailing month was complete (no absences).
        if ($employee['employment_type'] !== 'Regular') {
            throw new RuntimeException('Benefits are only available to Regular employees (currently ' . $employee['employment_type'] . ').', 422);
        }
        $check = $this->pdo->prepare(
            "SELECT COUNT(*) FROM attendance_logs
              WHERE employee_id = ? AND log_date >= CURRENT_DATE - INTERVAL '30 days' AND status = 'A'"
        );
        $check->execute([$employeeId]);
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('Benefit enrollment requires complete attendance; this employee has absences in the last 30 days.', 422);
        }

        $stmt = $this->pdo->prepare('SELECT * FROM benefit_plans WHERE id = ? AND is_active = TRUE');
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();
        if ($plan === false) {
            throw new RuntimeException('That benefit plan is not available.', 422);
        }

        $dup = $this->pdo->prepare("SELECT 1 FROM benefit_enrollments WHERE employee_id = ? AND plan_id = ? AND status <> 'Cancelled'");
        $dup->execute([$employeeId, $planId]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('This employee is already enrolled in that plan.', 409);
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO benefit_enrollments
                (employee_id, employee_name, plan_id, plan_name, provider, monthly_premium,
                 employer_share, employee_share, dependents, effective_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, \'Active\') RETURNING *'
        );
        $insert->execute([
            $employeeId, trim($employee['first_name'] . ' ' . $employee['last_name']),
            $plan['id'], $plan['plan_name'], $plan['provider'], $plan['monthly_premium'],
            $plan['employer_share'], $plan['employee_share'], max(0, $dependents),
        ]);
        $enrollment = $insert->fetch();

        AuditService::log('Benefit Enrolled', 'Benefits', null, [
            'employee_id' => $employeeId, 'plan_id' => $planId, 'enrollment_id' => $enrollment['id'],
        ]);
        return $enrollment;
    }

    public function cancel(int $enrollmentId): array
    {
        $this->pdo->prepare("UPDATE benefit_enrollments SET status = 'Cancelled' WHERE id = ?")->execute([$enrollmentId]);
        return ['cancelled' => $enrollmentId];
    }

    // ── Benefit plan maintenance (Benefit Plans page) ──
    public function allPlans(): array
    {
        return $this->pdo->query('SELECT * FROM benefit_plans ORDER BY plan_type, plan_name')->fetchAll();
    }

    public function createPlan(array $body): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO benefit_plans (plan_code, plan_name, plan_type, provider, monthly_premium, employer_share, employee_share, description, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING *'
        );
        $stmt->execute([
            Http::requireString($body, 'plan_code'),
            Http::requireString($body, 'plan_name'),
            (string) ($body['plan_type'] ?? 'HMO'),
            (string) ($body['provider'] ?? ''),
            (float) ($body['monthly_premium'] ?? 0),
            (int) ($body['employer_share'] ?? 0),
            (int) ($body['employee_share'] ?? 0),
            (string) ($body['description'] ?? ''),
            isset($body['is_active']) ? filter_var($body['is_active'], FILTER_VALIDATE_BOOL) : true,
        ]);
        $plan = $stmt->fetch();
        AuditService::log('Benefit Plan Created', 'Benefits', null, ['plan_code' => $plan['plan_code']]);
        return $plan;
    }

    public function updatePlan(int $id, array $body): array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE benefit_plans
                SET plan_name = ?, plan_type = ?, provider = ?, monthly_premium = ?,
                    employer_share = ?, employee_share = ?, description = ?, is_active = ?
              WHERE id = ? RETURNING *'
        );
        $stmt->execute([
            (string) ($body['plan_name'] ?? ''),
            (string) ($body['plan_type'] ?? 'HMO'),
            (string) ($body['provider'] ?? ''),
            (float) ($body['monthly_premium'] ?? 0),
            (int) ($body['employer_share'] ?? 0),
            (int) ($body['employee_share'] ?? 0),
            (string) ($body['description'] ?? ''),
            filter_var($body['is_active'] ?? true, FILTER_VALIDATE_BOOL),
            $id,
        ]);
        $plan = $stmt->fetch();
        if ($plan === false) {
            throw new RuntimeException("Benefit plan {$id} not found.", 404);
        }
        AuditService::log('Benefit Plan Updated', 'Benefits', null, ['plan_id' => $id]);
        return $plan;
    }

    public function deletePlan(int $id): array
    {
        // Soft-delete: deactivate so existing enrollments keep their history.
        $stmt = $this->pdo->prepare('UPDATE benefit_plans SET is_active = FALSE WHERE id = ? RETURNING id');
        $stmt->execute([$id]);
        if ($stmt->fetch() === false) {
            throw new RuntimeException("Benefit plan {$id} not found.", 404);
        }
        return ['deactivated' => $id];
    }
}
