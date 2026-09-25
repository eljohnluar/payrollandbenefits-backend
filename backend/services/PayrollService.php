<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Payroll lifecycle: open a period, seed its line items, compute statutory pay,
 * and move the run through the Finance approval workflow.
 *
 * The computation mirrors the legacy PHP app exactly (same tables, same rates)
 * so figures reconcile across the two systems during migration.
 */
final class PayrollService
{
    public function __construct(private readonly PDO $pdo) {}

    public function listRuns(int $limit = 25): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payroll_runs ORDER BY period_start DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findRun(int $runId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payroll_runs WHERE id = ?');
        $stmt->execute([$runId]);
        $run = $stmt->fetch();
        $stmt->closeCursor();
        if ($run === false) {
            throw new RuntimeException("Payroll run {$runId} does not exist.", 404);
        }
        return $run;
    }

    /** Number of days salary must wait after Finance approval (company policy: 3). */
    public static function leadTimeDays(): int
    {
        return (int) SettingsService::num('payslip_lead_days', 3);
    }

    /** Earliest legal pay date for a run: Finance approval + the mandatory lead time. */
    public function earliestPayDate(array $run): ?string
    {
        if ($run['finance_approved_at'] === null) {
            return null;
        }
        return date('Y-m-d', strtotime((string) $run['finance_approved_at']) + self::leadTimeDays() * 86400);
    }

    public function getRun(int $runId): array
    {
        $run = $this->findRun($runId);
        $items = $this->pdo->prepare('SELECT * FROM payroll_items WHERE payroll_run_id = ? AND is_archived = FALSE ORDER BY department, employee_name');
        $items->execute([$runId]);
        $run['items'] = $items->fetchAll();
        $items->closeCursor();
        $run['earliest_pay_date'] = $this->earliestPayDate($run);

        $stmt = $this->pdo->prepare(
            'SELECT fa.*, u1.name AS submitter_name, u2.name AS reviewer_name
               FROM finance_approvals fa
               LEFT JOIN app_users u1 ON u1.id = fa.submitted_by
               LEFT JOIN app_users u2 ON u2.id = fa.reviewed_by
              WHERE fa.payroll_run_id = ?'
        );
        $stmt->execute([$runId]);
        $run['finance_approval'] = $stmt->fetch() ?: null;
        $stmt->closeCursor();

        $run['funding_snapshot'] = $this->fundingSnapshot($run);

        $stmt = $this->pdo->prepare('SELECT * FROM general_ledger_entries WHERE payroll_run_id = ? ORDER BY debit DESC, credit ASC');
        $stmt->execute([$runId]);
        $run['gl_entries'] = $stmt->fetchAll();
        $stmt->closeCursor();

        $stmt = $this->pdo->prepare(
            'SELECT reference_no, COUNT(*) AS lines, COALESCE(SUM(amount), 0) AS amount, MAX(disbursed_at) AS processed_at
               FROM disbursement_records WHERE payroll_run_id = ? GROUP BY reference_no
              ORDER BY processed_at DESC LIMIT 1'
        );
        $stmt->execute([$runId]);
        $run['disbursement'] = $stmt->fetch() ?: null;
        $stmt->closeCursor();

        return $run;
    }

    /** Mirrors getPayrollFundingSnapshot() in the legacy app's config.php. */
    public function fundingSnapshot(array $run): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT allocated_amount, committed_amount FROM budget_allocations
              WHERE department = 'ALL' AND period_start <= ? AND period_end >= ?
              ORDER BY period_end DESC LIMIT 1"
        );
        $stmt->execute([(string) $run['period_start'], (string) $run['period_end']]);
        $budget = $stmt->fetch() ?: ['allocated_amount' => 0, 'committed_amount' => 0];
        $stmt->closeCursor();

        $cash = $this->pdo->query('SELECT available_amount, as_of_date FROM cash_positions ORDER BY as_of_date DESC, id DESC LIMIT 1')->fetch()
            ?: ['available_amount' => 0, 'as_of_date' => null];

        $budgetRemaining = max(0.0, (float) $budget['allocated_amount'] - (float) $budget['committed_amount']);
        $cashAvailable = (float) $cash['available_amount'];

        return [
            'budget_allocated' => (float) $budget['allocated_amount'],
            'budget_committed' => (float) $budget['committed_amount'],
            'budget_remaining' => $budgetRemaining,
            'cash_available'   => $cashAvailable,
            'cash_as_of'       => $cash['as_of_date'],
            'budget_required'  => (float) ($run['total_gross'] ?? 0),
            'cash_required'    => (float) ($run['total_net'] ?? 0),
            'budget_sufficient' => $budgetRemaining >= (float) ($run['total_gross'] ?? 0),
            'cash_sufficient'   => $cashAvailable >= (float) ($run['total_net'] ?? 0),
        ];
    }

    /** Runs in the trailing window, for the payroll cost trend chart. */
    public function trend(string $range = '30d'): array
    {
        $modify = ['30d' => '-30 days', '3m' => '-3 months', '1y' => '-1 year'][$range] ?? '-30 days';
        $stmt = $this->pdo->prepare(
            'SELECT period, period_start, period_end, total_gross, total_deductions, total_net, status
               FROM payroll_runs WHERE period_end >= ? ORDER BY period_end ASC'
        );
        $stmt->execute([date('Y-m-d', strtotime($modify))]);
        return $stmt->fetchAll();
    }

    /** Opens (or resumes) a run for the period and seeds one line per active employee. */
    public function openRun(string $periodStart, string $periodEnd, bool $reseed = false): array
    {
        $this->assertDate($periodStart, 'period_start');
        $this->assertDate($periodEnd, 'period_end');
        if ($periodStart > $periodEnd) {
            throw new RuntimeException('period_start must be on or before period_end.');
        }

        $stmt = $this->pdo->prepare('SELECT * FROM payroll_runs WHERE period_start = ? AND period_end = ?');
        $stmt->execute([$periodStart, $periodEnd]);
        $run = $stmt->fetch();
        $stmt->closeCursor();

        $this->pdo->beginTransaction();
        try {
            if ($run === false) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO payroll_runs (period, period_start, period_end, status) VALUES (?, ?, ?, \'Draft\') RETURNING *'
                );
                $insert->execute([date('F Y', strtotime($periodStart)), $periodStart, $periodEnd]);
                $run = $insert->fetch();
                $insert->closeCursor();
            } elseif ($reseed && $run['status'] !== 'Draft') {
                throw new RuntimeException('Only draft payroll runs can be reseeded.');
            }

            if ($reseed) {
                $this->pdo->prepare('DELETE FROM payroll_items WHERE payroll_run_id = ?')->execute([$run['id']]);
            }

            $seeded = $this->seedItems((int) $run['id'], $periodStart, $periodEnd);
            $this->recalculateTotals((int) $run['id']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        AuditService::log('Payroll Run Opened', 'Payroll', null, [
            'run_id' => $run['id'], 'period' => "{$periodStart}..{$periodEnd}", 'seeded' => $seeded,
        ]);

        return $this->getRun((int) $run['id']);
    }

    private function seedItems(int $runId, string $start, string $end): int
    {
        $employees = $this->pdo->query(
            "SELECT id, first_name, last_name, department, basic_salary, ewallet_provider
               FROM employees WHERE status IN ('Active','On Leave') AND is_archived = FALSE ORDER BY department, last_name"
        )->fetchAll();

        // Resolved before inserting so no read cursor is open across the writes.
        $attendance = [];
        foreach ($employees as $emp) {
            $attendance[$emp['id']] = $this->attendanceFor((string) $emp['id'], $start, $end);
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM payroll_items WHERE payroll_run_id = ? AND employee_id = ?');
        $insert = $this->pdo->prepare(
            'INSERT INTO payroll_items
                (payroll_run_id, employee_id, employee_name, department, days_worked, ot_hours,
                 ewallet_provider, status, is_included, pay_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'Draft\', TRUE, ?)'
        );

        $count = 0;
        foreach ($employees as $emp) {
            $exists->execute([$runId, $emp['id']]);
            $alreadyThere = (bool) $exists->fetchColumn();
            $exists->closeCursor();
            if ($alreadyThere) {
                continue;
            }
            [$days, $ot] = $attendance[$emp['id']];
            $insert->execute([
                $runId, $emp['id'], trim($emp['first_name'] . ' ' . $emp['last_name']),
                $emp['department'], $days, $ot, $emp['ewallet_provider'], $end,
            ]);
            $count++;
        }
        return $count;
    }

    /** @return array{0:float,1:float,2:float} days worked, OT hours and late minutes */
    private function attendanceFor(string $employeeId, string $start, string $end): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN status IN ('P','L','OT') THEN 1 WHEN status = 'H' THEN 0.5 ELSE 0 END), 0) AS days,
                    COALESCE(SUM(ot_hours), 0) AS ot,
                    COALESCE(SUM(late_minutes), 0) AS late
               FROM attendance_logs WHERE employee_id = ? AND log_date BETWEEN ? AND ?"
        );
        $stmt->execute([$employeeId, $start, $end]);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        $days = (float) ($row['days'] ?? 0);
        $ot   = (float) ($row['ot'] ?? 0);
        $late = (float) ($row['late'] ?? 0);
        return [$days > 0 ? $days : 22.0, $ot, $days > 0 ? $late : 0.0];
    }

    /**
     * Recomputes every included line of a draft run.
     * When $persist is false the figures are returned but nothing is written,
     * which is what the React page uses for its "preview before running" step.
     */
    public function process(int $runId, bool $persist = true): array
    {
        $run = $this->findRun($runId);
        if ($persist && $run['status'] !== 'Draft') {
            throw new RuntimeException("Only draft payroll runs can be recomputed (current status: {$run['status']}).");
        }

        $stmt = $this->pdo->prepare('SELECT * FROM payroll_items WHERE payroll_run_id = ? ORDER BY department, employee_name');
        $stmt->execute([$runId]);

        $computed = [];
        $allItems = $stmt->fetchAll();
        $stmt->closeCursor();
        if ($persist) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($allItems as $item) {
                if (!$item['is_included']) {
                    $computed[] = $item;
                    continue;
                }
                $line = $this->computeLine($item, (string) $run['period_start'], (string) $run['period_end']);
                if ($persist) {
                    $this->writeLine((int) $item['id'], $line, 'Draft');
                }
                $computed[] = array_merge($item, $line);
            }
            if ($persist) {
                $this->recalculateTotals($runId);
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($persist) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        AuditService::log('Payroll Computed', 'Payroll', null, ['run_id' => $runId, 'persisted' => $persist]);
        return ['run' => $this->findRun($runId), 'items' => $computed];
    }

    /** @return array<string,float> every payable, deduction and net figure for one employee */
    private function computeLine(array $item, string $start, string $end): array
    {
        $empId = (string) $item['employee_id'];
        $scalar = function (string $sql, array $params): float {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();
            $stmt->closeCursor();
            return (float) ($value ?: 0);
        };

        $salary = $scalar('SELECT basic_salary FROM employees WHERE id = ?', [$empId]);
        $workDays  = SettingsService::num('work_days_per_month', 22);
        $workHours = SettingsService::num('work_hours_per_day', 8);
        $dailyRate  = $salary / $workDays;
        $hourlyRate = $dailyRate / $workHours;

        [$daysWorked, $otHours, $lateMinutes] = $this->attendanceFor($empId, $start, $end);

        // An external workforce system that has filed timesheets but approved
        // none of them holds the pay back for that period.
        $ts = $this->pdo->prepare(
            'SELECT COUNT(*) AS submitted, COALESCE(SUM(CASE WHEN status = \'Approved\' THEN 1 ELSE 0 END), 0) AS approved
               FROM timesheets WHERE employee_id = ? AND period_start <= ? AND period_end >= ?'
        );
        $ts->execute([$empId, $end, $start]);
        $timesheet = $ts->fetch();
        $ts->closeCursor();
        if ((int) ($timesheet['submitted'] ?? 0) > 0 && (float) ($timesheet['approved'] ?? 0) === 0.0) {
            $daysWorked = 0.0;
            $otHours = 0.0;
            $lateMinutes = 0.0;
        }

        $shift = $this->pdo->prepare(
            'SELECT COALESCE(SUM(night_diff_hours),0) AS night_hours, COALESCE(SUM(holiday_hours),0) AS holiday_hours
               FROM shift_schedules WHERE employee_id = ? AND schedule_date BETWEEN ? AND ?'
        );
        $shift->execute([$empId, $start, $end]);
        $shiftRow = $shift->fetch();
        $shift->closeCursor();

        $period = [$empId, $start, $end];
        $line = [
            'days_worked'          => $daysWorked,
            'ot_hours'             => $otHours,
            'basic_pay'            => round($dailyRate * $daysWorked, 2),
            'overtime_pay'         => round($hourlyRate * 1.25 * $otHours, 2),
            'night_differential'   => round($hourlyRate * 0.10 * (float) $shiftRow['night_hours'], 2),
            'holiday_pay'          => round($hourlyRate * 2 * (float) $shiftRow['holiday_hours'], 2),
            'allowances'           => $scalar(
                "SELECT COALESCE(SUM(amount),0) FROM allowances
                  WHERE employee_id = ? AND is_active = TRUE AND frequency = 'Monthly'
                    AND (start_date IS NULL OR start_date <= ?) AND (end_date IS NULL OR end_date >= ?)",
                [$empId, $end, $start]
            ),
            'claims_amount'        => $scalar(
                "SELECT COALESCE(SUM(amount),0) FROM claims
                  WHERE (employee_id = ? OR (employee_id IS NULL AND employee_name = ?))
                    AND is_archived = FALSE
                    AND claim_date BETWEEN ? AND ? AND status = 'Approved'",
                [$empId, $item['employee_name'], $start, $end]
            ),
            'leave_conversion'     => $scalar(
                "SELECT COALESCE(SUM(amount),0) FROM leave_conversions
                  WHERE employee_id = ? AND conv_date BETWEEN ? AND ? AND status IN ('Approved','Completed')",
                $period
            ),
            'performance_bonus'    => $scalar(
                'SELECT COALESCE(SUM(bonus_amount),0) FROM performance_ratings WHERE employee_id = ? AND review_date BETWEEN ? AND ?',
                $period
            ),
            'competency_allowance' => $scalar(
                'SELECT COALESCE(SUM(allowance_amount),0) FROM competency_assessments WHERE employee_id = ? AND assessment_date BETWEEN ? AND ?',
                $period
            ),
            'training_incentive'   => $scalar(
                'SELECT COALESCE(SUM(incentive_amount),0) FROM training_records WHERE employee_id = ? AND completed_date BETWEEN ? AND ?',
                $period
            ),
            'recognition_bonus'    => $scalar(
                'SELECT COALESCE(SUM(bonus_amount),0) FROM recognition_awards WHERE employee_id = ? AND award_date BETWEEN ? AND ?',
                $period
            ),
            'loans_deduction'      => $scalar(
                'SELECT COALESCE(SUM(monthly_deduction),0) FROM loans WHERE employee_id = ? AND status = \'Active\'',
                [$empId]
            ),
            'hmo_deduction'        => $scalar(
                "SELECT COALESCE(SUM(monthly_premium * employee_share / 100),0) FROM benefit_enrollments
                  WHERE employee_id = ? AND status = 'Active'",
                [$empId]
            ),
        ];

        // Postgres needs the bind cast so the date arithmetic below resolves to
        // a date subtraction; other engines compare the ISO strings directly.
        $cast = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? '::date' : '';
        $unpaidDays = $scalar(
            "SELECT COALESCE(SUM(LEAST(end_date, ?{$cast}) - GREATEST(start_date, ?{$cast}) + 1), 0)
               FROM leave_requests
              WHERE employee_id = ? AND status = 'Approved' AND is_paid = FALSE
                AND start_date <= ?{$cast} AND end_date >= ?{$cast}",
            [$end, $start, $empId, $end, $start]
        );
        $line['unpaid_leave_deduction'] = round($dailyRate * max(0.0, $unpaidDays), 2);

        // Attendance auto-deduct: absences are unpaid via the prorated daily basic
        // pay above; logged lateness is deducted per minute against the hourly rate.
        $line['attendance_deduction'] = round($hourlyRate * ($lateMinutes / 60), 2);

        $line['gross_pay'] = round(array_sum(array_intersect_key($line, array_flip([
            'basic_pay', 'overtime_pay', 'night_differential', 'holiday_pay', 'allowances',
            'claims_amount', 'leave_conversion', 'performance_bonus', 'competency_allowance',
            'training_incentive', 'recognition_bonus',
        ]))), 2);

        $sss     = TaxService::sss($salary);
        $ph      = TaxService::philHealth($salary);
        $pagibig = TaxService::pagIbig($salary);
        $employeeContributions = $sss['ee'] + $ph['ee'] + $pagibig['ee'];

        $line['sss_ee']        = round($sss['ee'], 2);
        $line['sss_er']        = round($sss['er'], 2);
        $line['philhealth_ee'] = round($ph['ee'], 2);
        $line['philhealth_er'] = round($ph['er'], 2);
        $line['pagibig_ee']    = round($pagibig['ee'], 2);
        $line['pagibig_er']    = round($pagibig['er'], 2);
        $line['withholding_tax'] = TaxService::withholdingTax($line['gross_pay'], $employeeContributions);

        $line['total_deductions'] = round(
            $employeeContributions + $line['withholding_tax'] + $line['loans_deduction']
            + $line['unpaid_leave_deduction'] + $line['hmo_deduction'] + $line['attendance_deduction'],
            2
        );
        $line['net_pay'] = round($line['gross_pay'] - $line['total_deductions'], 2);

        return $line;
    }

    private function writeLine(int $itemId, array $line, string $status): void
    {
        $columns = array_keys($line);
        $assignments = implode(', ', array_map(static fn ($c) => "{$c} = ?", $columns));
        $sql = "UPDATE payroll_items SET {$assignments}, status = ? WHERE id = ?";
        $this->pdo->prepare($sql)->execute([...array_values($line), $status, $itemId]);
    }

    public function toggleIncluded(int $runId, int $itemId, bool $included): void
    {
        $run = $this->findRun($runId);
        if ($run['status'] !== 'Draft') {
            throw new RuntimeException('Only draft payroll runs can be changed.');
        }
        $stmt = $this->pdo->prepare('UPDATE payroll_items SET is_included = ? WHERE id = ? AND payroll_run_id = ?');
        $stmt->execute([$included, $itemId, $runId]);
        $this->recalculateTotals($runId);
    }

    public function submitToFinance(int $runId, int $userId): array
    {
        $run = $this->findRun($runId);
        if ($run['status'] !== 'Draft') {
            throw new RuntimeException("Only draft payroll runs can be submitted (current status: {$run['status']}).");
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE payroll_items SET status = 'Pending Finance Approval' WHERE payroll_run_id = ? AND is_included = TRUE")
                ->execute([$runId]);
            $this->pdo->prepare("UPDATE payroll_runs SET status = 'Pending Finance Approval', run_date = now() WHERE id = ?")
                ->execute([$runId]);
            $this->pdo->prepare(
                'INSERT INTO finance_approvals (payroll_run_id, status, submitted_by)
                 VALUES (?, \'Pending\', ?)
                 ON CONFLICT (payroll_run_id) DO UPDATE SET status = \'Pending\', submitted_by = EXCLUDED.submitted_by, submitted_at = now()'
            )->execute([$runId, $userId]);
            $this->pdo->prepare("INSERT INTO notifications (user_name, title, message) VALUES ('Finance', 'Payroll awaiting approval', ?)")
                ->execute(["Run {$run['period']} is ready for review."]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        AuditService::log('Payroll Submitted To Finance', 'Payroll', $userId, ['run_id' => $runId]);
        return $this->getRun($runId);
    }

    public function decide(int $runId, int $userId, string $decision, string $notes = ''): array
    {
        if (!in_array($decision, ['Approved', 'Rejected', 'On Hold'], true)) {
            throw new RuntimeException('decision must be Approved, Rejected or On Hold.');
        }
        $run = $this->findRun($runId);
        if ($run['status'] !== 'Pending Finance Approval') {
            throw new RuntimeException("This run is not awaiting finance approval (current status: {$run['status']}).");
        }

        if ($decision === 'Approved') {
            $funding = $this->fundingSnapshot($run);
            if (!$funding['budget_sufficient'] || !$funding['cash_sufficient']) {
                throw new RuntimeException('Payroll cannot be approved: the available budget or cash position is insufficient. Place it on hold or update the source financial records.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE finance_approvals SET status = ?, reviewed_by = ?, reviewed_at = now(), decision_notes = ? WHERE payroll_run_id = ?')
                ->execute([$decision === 'On Hold' ? 'On Hold' : $decision, $userId, $notes, $runId]);
            $this->pdo->prepare('UPDATE payroll_runs SET status = ?, finance_approved_at = CASE WHEN ?::boolean THEN now() ELSE finance_approved_at END WHERE id = ?')
                ->execute([$decision, (int) ($decision === 'Approved'), $runId]);
            $this->pdo->prepare('UPDATE payroll_items SET status = ? WHERE payroll_run_id = ? AND is_included = TRUE')
                ->execute([$decision === 'On Hold' ? 'On Hold' : $decision, $runId]);

            if ($decision === 'Approved') {
                $this->commitBudget($run);
                $this->postAccrualEntries($runId, $run);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        AuditService::log("Payroll {$decision}", 'Finance', $userId, ['run_id' => $runId, 'notes' => $notes]);
        return $this->getRun($runId);
    }

    /** Reserves the gross payroll against the widest matching ALL-department budget. */
    private function commitBudget(array $run): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE budget_allocations SET committed_amount = committed_amount + ?
              WHERE id = (
                SELECT id FROM budget_allocations
                 WHERE department = 'ALL' AND period_start <= ? AND period_end >= ?
                   AND allocated_amount - committed_amount >= ?
                 ORDER BY period_end DESC LIMIT 1
              )"
        );
        $stmt->execute([
            (float) $run['total_gross'], (string) $run['period_start'],
            (string) $run['period_end'], (float) $run['total_gross'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Payroll cannot be approved because the available budget changed.');
        }
    }

    /** Double-entry accrual posted when Finance approves, mirroring the legacy GL block. */
    private function postAccrualEntries(int $runId, array $run): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(gross_pay),0) AS gross, COALESCE(SUM(withholding_tax),0) AS tax,
                    COALESCE(SUM(sss_ee + sss_er),0) AS sss, COALESCE(SUM(philhealth_ee + philhealth_er),0) AS ph,
                    COALESCE(SUM(pagibig_ee + pagibig_er),0) AS pagibig, COALESCE(SUM(loans_deduction),0) AS loans,
                    COALESCE(SUM(hmo_deduction),0) AS hmo, COALESCE(SUM(net_pay),0) AS net
               FROM payroll_items WHERE payroll_run_id = ? AND is_included = TRUE'
        );
        $stmt->execute([$runId]);
        $tot = $stmt->fetch();
        $stmt->closeCursor();

        $this->pdo->prepare('DELETE FROM general_ledger_entries WHERE payroll_run_id = ?')->execute([$runId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO general_ledger_entries (payroll_run_id, entry_date, account_code, account_name, debit, credit, memo)
             VALUES (?, CURRENT_DATE, ?, ?, ?, ?, ?)'
        );
        $desc = 'Finance Approved Payroll Accrual: ' . $run['period'];
        $entry = static function (string $code, string $name, float $debit, float $credit) use ($ins, $runId, $desc): void {
            $ins->execute([$runId, $code, $name, $debit, $credit, $desc]);
        };

        $entry('5010', 'Salaries & Wages Expense', (float) $tot['gross'], 0);
        if ((float) $tot['tax'] > 0)     $entry('2020', 'Withholding Tax Payable (BIR)', 0, (float) $tot['tax']);
        if ((float) $tot['sss'] > 0)     $entry('2030', 'SSS Premium Payable', 0, (float) $tot['sss']);
        if ((float) $tot['ph'] > 0)      $entry('2040', 'PhilHealth Premium Payable', 0, (float) $tot['ph']);
        if ((float) $tot['pagibig'] > 0) $entry('2050', 'Pag-IBIG Premium Payable', 0, (float) $tot['pagibig']);
        if ((float) $tot['loans'] > 0)   $entry('1080', 'Employee Loans Receivable', 0, (float) $tot['loans']);
        if ((float) $tot['hmo'] > 0)     $entry('2060', 'HMO Premiums Payable', 0, (float) $tot['hmo']);
        $entry('2010', 'Payroll Payable (Accounts Payable)', 0, (float) $tot['net']);
    }

    public function setPayDate(int $runId, string $payDate): array
    {
        $run = $this->findRun($runId);
        if (!in_array($run['status'], ['Draft', 'Approved'], true)) {
            throw new RuntimeException('Pay date can only be set on Draft or approved payroll runs.');
        }
        $this->assertDate($payDate, 'pay_date');
        $earliest = $this->earliestPayDate($run);
        if ($earliest !== null && $payDate < $earliest) {
            throw new RuntimeException("Salary may not be released before {$earliest}: Finance approval requires a " . self::leadTimeDays() . '-day lead time.', 422);
        }
        $this->pdo->prepare('UPDATE payroll_runs SET pay_date = ? WHERE id = ?')->execute([$payDate, $runId]);
        $this->pdo->prepare('UPDATE payroll_items SET pay_date = ? WHERE payroll_run_id = ?')->execute([$payDate, $runId]);
        AuditService::log('Payroll Pay Date Set', 'Payroll', null, ['run_id' => $runId, 'pay_date' => $payDate]);
        return $this->getRun($runId);
    }

    public function returnToDraft(int $runId, int $userId): array
    {
        $run = $this->findRun($runId);
        if (!in_array($run['status'], ['On Hold', 'Rejected'], true)) {
            throw new RuntimeException('Only held or rejected payroll runs can be reopened.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE payroll_runs SET status = 'Draft' WHERE id = ?")->execute([$runId]);
            $this->pdo->prepare("UPDATE payroll_items SET status = 'Draft' WHERE payroll_run_id = ? AND is_included = TRUE")->execute([$runId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        AuditService::log('Payroll Reopened', 'Payroll', $userId, ['run_id' => $runId]);
        return $this->getRun($runId);
    }

    /** Releases the net pay to e-wallets: settles cash, records disbursements and posts the GL settlement. */
    public function markPaid(int $runId, int $userId): array
    {
        $run = $this->findRun($runId);
        if ($run['status'] !== 'Approved') {
            throw new RuntimeException('Finance must approve payroll before it can be disbursed.');
        }

        // Mandatory lead time: salary may only be released a set number of days
        // after Finance approves the run (payslips must be issued first).
        $earliest = $this->earliestPayDate($run);
        if ($earliest !== null && date('Y-m-d') < $earliest) {
            throw new RuntimeException("Disbursement is blocked until {$earliest}: " . self::leadTimeDays() . ' full day(s) must pass after Finance approval.', 422);
        }

        $this->pdo->beginTransaction();
        try {
            $cashStmt = $this->pdo->prepare('SELECT id, available_amount FROM cash_positions ORDER BY as_of_date DESC, id DESC LIMIT 1 FOR UPDATE');
            $cashStmt->execute();
            $cash = $cashStmt->fetch();
            $cashStmt->closeCursor();
            if (!$cash || (float) $cash['available_amount'] < (float) $run['total_net']) {
                throw new RuntimeException('Disbursement cannot proceed because the current cash position is insufficient.');
            }

            $refNo = 'DISB-' . $runId . '-' . date('Ymd-His');
            $this->pdo->prepare("UPDATE payroll_items SET status = 'Paid', pay_date = CURRENT_DATE WHERE payroll_run_id = ? AND is_included = TRUE")
                ->execute([$runId]);
            $this->pdo->prepare("UPDATE payroll_runs SET status = 'Paid', run_date = now() WHERE id = ?")->execute([$runId]);

            $this->pdo->prepare('DELETE FROM disbursement_records WHERE payroll_run_id = ?')->execute([$runId]);
            $items = $this->pdo->prepare('SELECT employee_id, net_pay, ewallet_provider FROM payroll_items WHERE payroll_run_id = ? AND is_included = TRUE');
            $items->execute([$runId]);
            $included = $items->fetchAll();
            $items->closeCursor();
            $disb = $this->pdo->prepare(
                "INSERT INTO disbursement_records (payroll_run_id, employee_id, amount, provider, reference_no, status, disbursed_at)
                 VALUES (?, ?, ?, ?, ?, 'Disbursed', now())"
            );
            foreach ($included as $line) {
                $disb->execute([$runId, $line['employee_id'], (float) $line['net_pay'], $line['ewallet_provider'] ?: 'Other', $refNo]);
            }

            $this->pdo->prepare('UPDATE cash_positions SET available_amount = available_amount - ? WHERE id = ?')
                ->execute([(float) $run['total_net'], $cash['id']]);

            $desc = 'Disbursement Settlement: ' . $run['period'] . " ({$refNo})";
            $gl = $this->pdo->prepare(
                'INSERT INTO general_ledger_entries (payroll_run_id, entry_date, account_code, account_name, debit, credit, memo)
                 VALUES (?, CURRENT_DATE, ?, ?, ?, ?, ?)'
            );
            $gl->execute([$runId, '2010', 'Payroll Payable (Accounts Payable)', (float) $run['total_net'], 0, $desc]);
            $gl->execute([$runId, '1010', 'Cash in Bank - E-Wallet Disbursement Account', 0, (float) $run['total_net'], $desc]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        AuditService::log('Payroll Disbursed', 'Finance', $userId, ['run_id' => $runId, 'reference' => $refNo]);
        return $this->getRun($runId);
    }

    public function closeRun(int $runId, int $userId): array
    {
        $run = $this->findRun($runId);
        if ($run['status'] !== 'Paid') {
            throw new RuntimeException('Only a paid payroll run can be closed.');
        }
        $this->pdo->prepare("UPDATE payroll_runs SET status = 'Closed' WHERE id = ?")->execute([$runId]);
        AuditService::log('Payroll Closed', 'Finance', $userId, ['run_id' => $runId]);
        return $this->getRun($runId);
    }

    /**
     * Adds payroll lines from parsed CSV rows: [employee code or id, days?, ot?].
     * New employees are appended to a draft run; existing lines are skipped.
     *
     * @param array<int, array<int, string|null>> $rows
     */
    public function importItems(int $runId, array $rows): array
    {
        $run = $this->findRun($runId);
        if ($run['status'] !== 'Draft') {
            throw new RuntimeException('Only draft payroll runs can be imported.');
        }

        $inserted = 0;
        $skipped = 0;
        $notFound = [];
        $this->pdo->beginTransaction();
        try {
            $findEmp = $this->pdo->prepare('SELECT id, first_name, last_name, department, ewallet_provider FROM employees WHERE code = ? OR id = ?');
            $exists  = $this->pdo->prepare('SELECT 1 FROM payroll_items WHERE payroll_run_id = ? AND employee_id = ?');
            $ins     = $this->pdo->prepare(
                "INSERT INTO payroll_items (payroll_run_id, employee_id, employee_name, department, days_worked, ot_hours, ewallet_provider, status, is_included)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'Draft', TRUE)"
            );
            foreach ($rows as $row) {
                $code = trim((string) ($row[0] ?? ''));
                if ($code === '') {
                    continue;
                }
                $findEmp->execute([$code, $code]);
                $emp = $findEmp->fetch();
                $findEmp->closeCursor();
                if (!$emp) {
                    $notFound[] = $code;
                    continue;
                }
                $exists->execute([$runId, $emp['id']]);
                $there = (bool) $exists->fetchColumn();
                $exists->closeCursor();
                if ($there) {
                    $skipped++;
                    continue;
                }
                $days = isset($row[1]) && is_numeric($row[1]) ? (float) $row[1] : 22.0;
                $ot   = isset($row[2]) && is_numeric($row[2]) ? (float) $row[2] : 0.0;
                $ins->execute([
                    $runId, $emp['id'], trim($emp['first_name'] . ' ' . $emp['last_name']),
                    $emp['department'], $days, $ot, $emp['ewallet_provider'],
                ]);
                $inserted++;
            }
            if ($inserted > 0) {
                $this->recalculateTotals($runId);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        AuditService::log('Payroll Items Imported', 'Payroll', null, ['run_id' => $runId, 'inserted' => $inserted, 'skipped' => $skipped]);
        return ['inserted' => $inserted, 'skipped' => $skipped, 'not_found' => $notFound];
    }

    public function recalculateTotals(int $runId): void
    {
        $this->pdo->prepare(
            'UPDATE payroll_runs
                SET total_gross = COALESCE((SELECT SUM(gross_pay) FROM payroll_items WHERE payroll_run_id = payroll_runs.id AND is_included), 0),
                    total_deductions = COALESCE((SELECT SUM(total_deductions) FROM payroll_items WHERE payroll_run_id = payroll_runs.id AND is_included), 0),
                    total_net = COALESCE((SELECT SUM(net_pay) FROM payroll_items WHERE payroll_run_id = payroll_runs.id AND is_included), 0)
              WHERE id = ?'
        )->execute([$runId]);
    }

    private function assertDate(string $value, string $field): void
    {
        if (\DateTime::createFromFormat('Y-m-d', $value) === false) {
            throw new RuntimeException("Field '{$field}' must be an ISO date (YYYY-MM-DD).");
        }
    }
}
