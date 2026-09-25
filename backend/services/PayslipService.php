<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Renders a plain-text payslip PDF and uploads it to the Supabase Storage
 * 'payslips' bucket through the Storage REST API — no Composer dependency.
 * Swap renderPdf() for Dompdf later if the layout needs to get richer.
 */
final class PayslipService
{
    public function __construct(private readonly PDO $pdo) {}

    public function build(int $payrollItemId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, r.period, r.period_start, r.period_end, e.tin, e.sss, e.philhealth, e.pagibig, e.position, e.department AS emp_department
               FROM payroll_items i
               JOIN payroll_runs r ON r.id = i.payroll_run_id
               JOIN employees e ON e.id = i.employee_id
              WHERE i.id = ?'
        );
        $stmt->execute([$payrollItemId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException("Payroll item {$payrollItemId} not found.", 404);
        }

        $lines = $this->lines($row);
        $pdf = $this->renderPdf($lines);
        $objectKey = sprintf('payslips/%s/%s-%s.pdf', $row['period'], $row['employee_id'], $row['pay_date'] ?: date('Y-m-d'));

        $uploaded = $this->upload($objectKey, $pdf);
        if ($uploaded) {
            $this->pdo->prepare('UPDATE payroll_items SET payslip_object_key = ? WHERE id = ?')
                ->execute([$objectKey, $payrollItemId]);
        }

        return [
            'payslip_item_id' => $payrollItemId,
            'employee'        => $row['employee_name'],
            'period'          => $row['period'],
            'object_key'      => $objectKey,
            'stored'          => $uploaded,
            'text'            => implode("\n", $lines),
        ];
    }

    /** A list of payslip-ready payroll lines (optionally filtered to a run / employee). */
    public function recent(?int $runId = null, ?string $employeeId = null, int $limit = 200): array
    {
        $sql = 'SELECT i.id, i.employee_id, i.employee_name, i.department, i.gross_pay, i.net_pay,
                       i.status, i.payslip_object_key, i.pay_date, r.period, r.id AS payroll_run_id
                  FROM payroll_items i JOIN payroll_runs r ON r.id = i.payroll_run_id
                 WHERE i.is_archived = FALSE';
        $params = [];
        if ($runId)       { $sql .= ' AND i.payroll_run_id = ?'; $params[] = $runId; }
        if ($employeeId)  { $sql .= ' AND i.employee_id = ?';    $params[] = $employeeId; }
        $sql .= ' ORDER BY r.period_start DESC, i.department, i.employee_name LIMIT ?';
        $params[] = $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Returns the rendered PDF bytes for an item without touching Storage (for in-browser view/download). */
    public function pdfBytes(int $payrollItemId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, r.period, r.period_start, r.period_end, e.position
               FROM payroll_items i
               JOIN payroll_runs r ON r.id = i.payroll_run_id
               JOIN employees e ON e.id = i.employee_id
              WHERE i.id = ?'
        );
        $stmt->execute([$payrollItemId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException("Payroll item {$payrollItemId} not found.", 404);
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $row['employee_name'] . '-' . $row['period']);
        return ['pdf' => $this->renderPdf($this->lines($row)), 'filename' => $name . '.pdf'];
    }

    /** Structured payslip data for the on-screen document (PHP payslips.php preview). */
    public function detail(int $payrollItemId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, r.period, r.period_start, r.period_end, r.status AS run_status,
                    e.position, e.department AS emp_department, e.code
               FROM payroll_items i
               JOIN payroll_runs r ON r.id = i.payroll_run_id
               JOIN employees e ON e.id = i.employee_id
              WHERE i.id = ?'
        );
        $stmt->execute([$payrollItemId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException("Payroll item {$payrollItemId} not found.", 404);
        }
        $num = static fn ($v) => round((float) $v, 2);
        return [
            'id'       => (int) $row['id'],
            'company'  => SettingsService::get('company_name', 'Company'),
            'period'   => $row['period'],
            'pay_date' => $row['pay_date'] ?: $row['period_end'],
            'status'   => $row['status'],
            'employee' => [
                'id'         => $row['employee_id'],
                'code'       => $row['code'],
                'name'       => $row['employee_name'],
                'department' => $row['emp_department'],
                'position'   => $row['position'],
            ],
            'days_worked' => $num($row['days_worked']),
            'ot_hours'    => $num($row['ot_hours']),
            'earnings' => [
                ['label' => 'Basic Pay', 'amount' => $num($row['basic_pay'])],
                ['label' => 'Overtime Pay (' . $num($row['ot_hours']) . ' hrs)', 'amount' => $num($row['overtime_pay'])],
                ['label' => 'Night Differential', 'amount' => $num($row['night_differential'])],
                ['label' => 'Holiday Pay', 'amount' => $num($row['holiday_pay'])],
                ['label' => 'Allowances', 'amount' => $num($row['allowances'])],
                ['label' => 'Approved Claims', 'amount' => $num($row['claims_amount'])],
            ],
            'gross_pay' => $num($row['gross_pay']),
            'deductions' => array_merge([
                ['label' => 'SSS (mandatory)', 'amount' => $num($row['sss_ee'])],
                ['label' => 'PhilHealth (mandatory)', 'amount' => $num($row['philhealth_ee'])],
                ['label' => 'Pag-IBIG', 'amount' => $num($row['pagibig_ee'])],
                ['label' => 'Withholding Tax', 'amount' => $num($row['withholding_tax'])],
                ['label' => 'Loan Amortization', 'amount' => $num($row['loans_deduction'])],
                ['label' => 'HMO Deduction', 'amount' => $num($row['hmo_deduction'])],
            ], $num($row['attendance_deduction'] ?? 0) > 0
                ? [['label' => 'Late Deduction (attendance)', 'amount' => $num($row['attendance_deduction'])]]
                : []),
            'total_deductions' => $num($row['total_deductions']),
            'net_pay' => $num($row['net_pay']),
        ];
    }

    private function lines(array $row): array
    {
        $money = static fn ($v) => number_format((float) $v, 2);
        $pairs = [
            ['Pay period', $row['period_start'] . ' to ' . $row['period_end']],
            ['Position', (string) $row['position']],
            ['Days worked', (string) $row['days_worked']],
            ['OT hours', (string) $row['ot_hours']],
            ['', ''],
            ['Basic pay', $money($row['basic_pay'])],
            ['Overtime pay', $money($row['overtime_pay'])],
            ['Night differential', $money($row['night_differential'])],
            ['Holiday pay', $money($row['holiday_pay'])],
            ['Allowances', $money($row['allowances'])],
            ['Approved claims', $money($row['claims_amount'])],
            ['Competency allowance', $money($row['competency_allowance'])],
            ['Training incentive', $money($row['training_incentive'])],
            ['Recognition bonus', $money($row['recognition_bonus'])],
            ['GROSS PAY', $money($row['gross_pay'])],
            ['', ''],
            ['SSS (employee)', $money($row['sss_ee'])],
            ['PhilHealth (employee)', $money($row['philhealth_ee'])],
            ['Pag-IBIG (employee)', $money($row['pagibig_ee'])],
            ['Withholding tax', $money($row['withholding_tax'])],
            ['Loan amortization', $money($row['loans_deduction'])],
            ['HMO deduction', $money($row['hmo_deduction'])],
            ['Late/attendance deduction', $money($row['attendance_deduction'] ?? 0)],
            ['Unpaid leave deduction', $money($row['unpaid_leave_deduction'] ?? 0)],
            ['TOTAL DEDUCTIONS', $money($row['total_deductions'])],
            ['', ''],
            ['NET PAY', $money($row['net_pay'])],
        ];

        $out = [
            SettingsService::get('company_name', 'Company') ?: 'Company',
            'PAYSIP — ' . $row['period'],
            '',
            $row['employee_name'] . '  (' . $row['employee_id'] . ')',
            '',
        ];
        foreach ($pairs as [$label, $value]) {
            $out[] = $value === '' ? '' : str_pad(substr($label, 0, 34), 36) . $value;
        }
        return $out;
    }

    /** Minimal single-page PDF with Helvetica text — valid without any library. */
    private function renderPdf(array $lines): string
    {
        $esc = fn (string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->toAscii($s));
        $text = "BT /F1 11 Tf 56 760 Td 14 TL\n";
        foreach ($lines as $line) {
            $text .= '(' . $esc($line) . ") Tj T*\n";
        }
        $text .= 'ET';

        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
            4 => "<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>",
            5 => "<< /Length " . strlen($text) . " >>\nstream\n{$text}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= 'xref 0 ' . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
        return $pdf;
    }

    private function toAscii(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '?', $value) : $converted;
    }

    private function upload(string $objectKey, string $bytes): bool
    {
        $url = Config::supabaseUrl();
        $key = Config::serviceRoleKey();
        if ($url === '' || $key === '') {
            return false; // Storage is not configured yet — caller still gets the rendered text.
        }
        $ch = curl_init("{$url}/storage/v1/object/{$objectKey}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'apikey: ' . $key,
                'Content-Type: application/pdf',
                'x-upsert: true',
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status >= 400) {
            error_log('[payslip] storage upload failed ' . $status . ' ' . substr((string) $response, 0, 300));
        }
        return $status < 400;
    }
}
