<?php
declare(strict_types=1);

/**
 * Seed sample attendance enrichment + incentive structures/earnings for the
 * September 2026 payroll period. Idempotent: safe to run repeatedly.
 *
 *   cd backend/backend && C:\\xampp\\php\\php.exe scripts/seed-sample-incentives.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$pdo = App\Database::pdo();
$svc = new App\IncentiveService($pdo);
$period = '2026-09';
$start = '2026-09-01';
$end = '2026-09-30';

/* 1) Attendance: make three employees perfect-attendance for the month
      (so the Attendance incentive has winners), and add visible lateness
      to two others. */
$perfect = ['emp-001', 'emp-009', 'emp-012'];
$del = $pdo->prepare("DELETE FROM attendance_logs WHERE employee_id = ? AND log_date BETWEEN ? AND ? AND status = 'A'");
foreach ($perfect as $emp) {
    $del->execute([$emp, $start, $end]);
}
$pdo->prepare(
    "UPDATE attendance_logs SET late_minutes = 35 WHERE employee_id = ? AND log_date = ?"
)->execute(['emp-002', '2026-09-08']);
$pdo->prepare(
    "UPDATE attendance_logs SET late_minutes = 50 WHERE employee_id = ? AND log_date = ?"
)->execute(['emp-003', '2026-09-15']);
echo "attendance: cleared absences for " . implode(', ', $perfect) . "; added late minutes for emp-002/emp-003\n";

/* 2) September performance reviews (drive the Performance incentives). */
$hasRating = $pdo->prepare('SELECT 1 FROM performance_ratings WHERE employee_id = ? AND review_date = ?');
$addRating = $pdo->prepare('INSERT INTO performance_ratings (employee_id, review_date, rating, bonus_amount) VALUES (?, ?, ?, 0)');
foreach ([['emp-002', '4.5'], ['emp-004', '4.0'], ['emp-013', '4.8']] as [$emp, $rating]) {
    $hasRating->execute([$emp, '2026-09-15']);
    if ($hasRating->fetchColumn() === false) {
        $addRating->execute([$emp, '2026-09-15', $rating]);
        echo "rating: {$emp} = {$rating}\n";
    }
}

/* 3) Incentive structures across all eight types. */
$exists = $pdo->prepare('SELECT 1 FROM incentive_structures WHERE employee_id = ? AND name = ?');
$structures = [
    ['emp-001', 'Perfect Attendance Bonus', 'Attendance', 'Fixed', 3000, 'Monthly', null, 'All regular employees'],
    ['emp-009', 'Perfect Attendance Bonus', 'Attendance', 'Fixed', 2500, 'Monthly', null, 'All regular employees'],
    ['emp-012', 'Perfect Attendance Bonus', 'Attendance', 'Percentage', 10, 'Monthly', null, 'All regular employees'],
    ['emp-002', 'Performance Excellence', 'Performance', 'Fixed', 1000, 'Monthly', null, 'Rating-based'],
    ['emp-013', 'Performance Excellence', 'Performance', 'Fixed', 1200, 'Monthly', null, 'Rating-based'],
    ['emp-004', 'Performance Share', 'Performance', 'Percentage', 20, 'Monthly', 5, 'Rating-based'],
    ['emp-005', 'Logistics Sales Commission', 'Sales', 'Percentage', 5, 'Monthly', null, 'Logistics revenue'],
    ['emp-011', 'Delivery Productivity Incentive', 'Productivity', 'Fixed', 150, 'Monthly', 100, 'Excess deliveries'],
    ['emp-010', 'Talent Referral Bonus', 'Referral', 'Fixed', 5000, 'One-Time', null, 'Referral completes probation'],
    ['emp-001', 'Spot Award', 'Spot', 'Fixed', 0, 'One-Time', null, 'HR-discretionary'],
    ['emp-002', 'Retention Service Award', 'Retention', 'Fixed', 10000, 'One-Time', 2, '2+ years of service'],
    ['emp-005', 'Operations Team Pot', 'Team', 'Fixed', 0, 'Monthly', null, 'Split across Operations'],
];
$ids = [];
foreach ($structures as [$emp, $name, $type, $rateType, $rate, $freq, $target, $elig]) {
    $exists->execute([$emp, $name]);
    if ($exists->fetchColumn() !== false) {
        continue;
    }
    $row = $svc->create([
        'employee_id' => $emp, 'name' => $name, 'type' => $type, 'rate_type' => $rateType,
        'rate' => $rate, 'frequency' => $freq, 'target' => $target, 'eligibility' => $elig,
        'effective_date' => '2026-01-01',
    ]);
    $ids["{$emp}:{$name}"] = (int) $row['id'];
    echo "structure: {$name} ({$type}) for {$emp}\n";
}
// Resolve ids for pre-existing structures too.
$lookup = $pdo->prepare('SELECT id FROM incentive_structures WHERE employee_id = ? AND name = ?');
foreach ($structures as [$emp, $name]) {
    $key = "{$emp}:{$name}";
    if (!isset($ids[$key])) {
        $lookup->execute([$emp, $name]);
        $ids[$key] = (int) $lookup->fetchColumn();
    }
}

/* 4) Auto-compute Performance / Attendance / Retention for September. */
$res = $svc->computePeriod($start, $end);
echo "computed: {$res['period']} -> " . count($res['created']) . " new earning(s)\n";
foreach ($res['created'] as $c) {
    echo "  {$c['employee_id']} {$c['type']} {$c['amount']} ({$c['basis']})\n";
}

/* 5) Record metrics for the manual types. */
$metrics = [
    ["emp-005:Logistics Sales Commission", '2026-09', 500000, 'September logistics revenue ₱500,000'],
    ["emp-011:Delivery Productivity Incentive", '2026-09', 110, '110 deliveries (target 100)'],
    ["emp-010:Talent Referral Bonus", '2026-09', 5000, 'Referred applicant completed probation'],
    ["emp-001:Spot Award", '2026-09', 2500, 'Handled emergency shipment'],
    ["emp-005:Operations Team Pot", '2026-09', 30000, 'September team performance pot'],
];
foreach ($metrics as [$key, $p, $value, $note]) {
    if (str_contains($key, 'Operations Team Pot')) {
        // Team shares are stored without a structure link, so guard on the note.
        $stmt = $pdo->prepare("SELECT 1 FROM incentive_earnings WHERE period = ? AND type = 'Team' AND basis = ?");
        $stmt->execute([$p, $note . sprintf(' (team share of %s)', 'PHP 30,000.00')]);
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM incentive_earnings en JOIN incentive_structures s ON s.id = en.incentive_structure_id WHERE s.id = ? AND en.period = ?');
        $stmt->execute([$ids[$key], $p]);
    }
    if ($stmt->fetchColumn() !== false) {
        continue;
    }
    $out = $svc->recordMetric($ids[$key], $p, (float) $value, $note);
    echo "metric: {$key} -> " . (isset($out['shared_with']) ? "{$out['each']} x {$out['shared_with']} members" : ($out['amount'] ?? 0)) . "\n";
}

/* 6) Summary. */
$total = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS s FROM incentive_earnings WHERE period = '{$period}'")->fetch();
echo "\nSeptember {$period}: {$total['n']} earning rows totalling PHP " . number_format((float) $total['s'], 2) . "\n";
echo "Next: open the Payroll Run page and recompute the September run to fold incentives into gross pay.\n";
