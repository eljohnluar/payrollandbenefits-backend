<?php
declare(strict_types=1);

namespace App;

use PDO;

final class DashboardService
{
    public function __construct(private readonly PDO $pdo) {}

    public function summary(): array
    {
        $runners = $this->pdo->query(
            "SELECT * FROM payroll_runs
              WHERE status IN ('Draft','Processing','Pending Finance Approval','On Hold','Approved')
              ORDER BY period_start DESC LIMIT 1"
        )->fetch();

        $trend = $this->pdo->query(
            'SELECT period, period_start, total_gross, total_deductions, total_net, status
               FROM payroll_runs ORDER BY period_start DESC LIMIT 6'
        )->fetchAll();

        $pendingClaims = $this->pdo->query("SELECT COUNT(*) FROM claims WHERE status = 'Pending'")->fetchColumn();
        $claimTotal    = $this->pdo->query("SELECT COALESCE(SUM(amount),0) FROM claims WHERE status = 'Pending'")->fetchColumn();

        $latestRun = $this->pdo->query(
            'SELECT period, total_gross, total_net, status FROM payroll_runs ORDER BY id DESC LIMIT 1'
        )->fetch() ?: ['period' => 'N/A', 'total_gross' => 0, 'total_net' => 0, 'status' => 'N/A'];

        $latestContributions = $this->pdo->query(
            'SELECT COALESCE(SUM(sss_ee + philhealth_ee + pagibig_ee + withholding_tax), 0)
               FROM payroll_items
              WHERE payroll_run_id = (SELECT id FROM payroll_runs ORDER BY id DESC LIMIT 1)'
        )->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT fa.payroll_run_id, fa.submitted_at, pr.period, pr.period_start, pr.period_end,
                    pr.total_gross, pr.total_deductions, pr.total_net, u.name AS submitter_name
               FROM finance_approvals fa
               JOIN payroll_runs pr ON pr.id = fa.payroll_run_id
               LEFT JOIN app_users u ON u.id = fa.submitted_by
              WHERE fa.status = \'Pending\'
              ORDER BY fa.submitted_at ASC'
        );
        $stmt->execute();
        $pendingApprovals = $stmt->fetchAll();

        $activeBenefits = (int) $this->pdo->query("SELECT COUNT(*) FROM benefit_enrollments WHERE status = 'Active'")->fetchColumn();
        $approvedClaims = (int) $this->pdo->query("SELECT COUNT(*) FROM claims WHERE status IN ('Approved','Paid')")->fetchColumn();
        $totalClaims    = (int) $this->pdo->query('SELECT COUNT(*) FROM claims')->fetchColumn();

        $deptStmt = $this->pdo->query(
            "SELECT department, COUNT(*) AS emp_count, COALESCE(SUM(gross_pay), 0) AS total_sal
               FROM payroll_items
              WHERE payroll_run_id = (SELECT id FROM payroll_runs ORDER BY id DESC LIMIT 1)
              GROUP BY department ORDER BY total_sal DESC"
        );
        $deptBreakdown = $deptStmt->fetchAll();

        $recentPayroll = $this->pdo->query(
            'SELECT employee_name, net_pay, pay_date, status FROM payroll_items ORDER BY id DESC LIMIT 5'
        )->fetchAll();

        $activity = $this->pdo->query(
            'SELECT a.action, a.module, a.details, a.created_at, u.name AS user_name
               FROM audit_log a LEFT JOIN app_users u ON u.id = a.user_id
              ORDER BY a.created_at DESC LIMIT 6'
        )->fetchAll();

        return [
            'employees' => [
                'total'  => (int) $this->pdo->query('SELECT COUNT(*) FROM employees WHERE is_archived = FALSE')->fetchColumn(),
                'active' => (int) $this->pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'Active' AND is_archived = FALSE")->fetchColumn(),
            ],
            'current_run'   => $runners ?: null,
            'payroll_trend' => array_reverse($trend),
            'claims'        => ['pending_count' => (int) $pendingClaims, 'pending_amount' => (float) $claimTotal],
            'latest_run'            => ['period' => $latestRun['period'], 'total_gross' => (float) $latestRun['total_gross'], 'total_net' => (float) $latestRun['total_net'], 'status' => $latestRun['status']],
            'latest_contributions'  => (float) $latestContributions,
            'pending_approvals'     => $pendingApprovals,
            'pending_finance_amount' => array_sum(array_column($pendingApprovals, 'total_net')),
            'active_benefits'       => $activeBenefits,
            'approved_claims'       => $approvedClaims,
            'total_claims'          => $totalClaims,
            'department_breakdown'  => $deptBreakdown,
            'recent_payroll_items'  => $recentPayroll,
            'activity'              => $activity,
            'generated_at'  => date('c'),
        ];
    }
}
