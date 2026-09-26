<?php
declare(strict_types=1);

/**
 * Payroll & Benefits API — front controller.
 *
 * Point a virtual host at this directory, or run it with the built-in server:
 *   php -S localhost:8080 -t backend/public
 *
 * Routes live under /api/... . When the host cannot expose PATH_INFO, pass the
 * same route as a query string: index.php?r=/api/payroll/runs
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Config;
use App\Database;
use App\Http;
use App\Router;
use App\ArchiveService;
use App\AuditService;
use App\BenefitsService;
use App\ClaimsService;
use App\DashboardService;
use App\EmployeeService;
use App\MailService;
use App\PayrollService;
use App\PayslipService;
use App\SettingsService;
use App\AttendanceService;
use App\ThirteenthMonthService;
use App\EventService;
use App\IntegrationAuth;
use App\IncentiveService;

$router = new Router();

$employees = static fn (): EmployeeService  => new EmployeeService(Database::pdo());
$payroll   = static fn (): PayrollService   => new PayrollService(Database::pdo());
$claims    = static fn (): ClaimsService    => new ClaimsService(Database::pdo());
$benefits  = static fn (): BenefitsService  => new BenefitsService(Database::pdo());
$dashboard = static fn (): DashboardService => new DashboardService(Database::pdo());
$payslip   = static fn (): PayslipService   => new PayslipService(Database::pdo());
$attendance = static fn (): AttendanceService => new AttendanceService(Database::pdo());
$thirteen   = static fn (): ThirteenthMonthService => new ThirteenthMonthService(Database::pdo());
$archiver   = static fn (): ArchiveService => new ArchiveService(Database::pdo());
$mail       = static fn (): MailService    => new MailService(Database::pdo());
$integration = static fn (): IntegrationAuth => new IntegrationAuth(Database::pdo());
$events      = static fn (): EventService    => new EventService(Database::pdo());
$incentives  = static fn (): IncentiveService => new IncentiveService(Database::pdo());

/* ── Public ─────────────────────────────────────────── */

$router->get('/api/health', static function (): void {
    $dbError = null;
    try {
        Database::pdo()->query('SELECT 1')->fetchColumn();
    } catch (\Throwable $e) {
        $dbError = $e->getMessage();
    }
    Http::json([
        'status'   => $dbError === null ? 'ok' : 'degraded',
        'database' => $dbError === null,
        'db_error' => $dbError,
        'pdo_drivers' => PDO::getAvailableDrivers(),
        'supabase' => [
            'url_configured'        => Config::supabaseUrl() !== '',
            'jwt_secret_configured' => Config::jwtSecret() !== '',
            'storage_configured'    => Config::serviceRoleKey() !== '',
        ],
    ]);
});

$router->get('/api/branding', static function (): void {
    // Public on purpose: only the company name shown on the login/register cards.
    Http::json(['company_name' => (string) SettingsService::get('company_name', '')]);
});

$router->post('/api/auth/login', static function (): void {
    $body = Http::body();
    $email = Http::requireString($body, 'email');
    $result = Auth::login($email, Http::requireString($body, 'password'));
    if ($result === null) {
        AuditService::log('Failed Login', 'Auth', null, ['email' => $email, 'reason' => 'bad credentials']);
        Http::error('Email or password is incorrect.', 401);
    }
    if (!empty($result['otp_required'])) {
        AuditService::log('Login Code Sent', 'Auth', null, ['email' => $result['email'], 'reason' => 'password ok, awaiting email code']);
        Http::json($result);
    }
    AuditService::log('User Login', 'Auth', (int) $result['user']['id'], ['email' => $result['user']['email']]);
    Http::json($result);
});

$router->post('/api/auth/logout', static function (): void {
    $user = Auth::user();
    AuditService::log('User Logout', 'Auth', (int) $user['id'], ['email' => $user['email']]);
    Http::json(['ok' => true]);
});

$router->post('/api/auth/register', static function (): void {
    $body = Http::body();
    Http::json(Auth::register(
        (string) ($body['email'] ?? ''),
        (string) ($body['username'] ?? ''),
        (string) ($body['password'] ?? ''),
        (string) ($body['registration_code'] ?? '')
    ), 201);
});

$router->post('/api/auth/supabase', static function (): void {
    $body = Http::body();
    $result = Auth::exchangeFromSupabase(Http::requireString($body, 'access_token'), $body);
    AuditService::log('User Login', 'Auth', (int) $result['user']['id'], ['email' => $result['user']['email'], 'via' => 'supabase']);
    Http::json($result);
});

$router->post('/api/auth/reset-password', static function (): void {
    $body = Http::body();
    $result = Auth::resetPassword(Http::requireString($body, 'access_token'), (string) ($body['password'] ?? ''));
    AuditService::log('Password Reset', 'Auth', null, ['email' => $result['email'], 'via' => 'supabase recovery']);
    Http::json($result);
});

/* ── Authenticated reads ────────────────────────────── */

$router->get('/api/auth/me', static function (): void {
    Http::json(Auth::user());
});

/* ── Audit log (Admin / HR) ─────────────────────────── */

$router->get('/api/audit', static function (): void {
    Auth::requireRole('Admin', 'HR');
    Http::json(AuditService::list($_GET));
});

$router->post('/api/audit/reset', static function (): void {
    $user = Auth::requireRole('Admin', 'HR');
    $password = (string) (Http::body()['password'] ?? '');
    if ($password === '' || !Auth::verifyPassword($password)) {
        AuditService::log('Failed Audit Reset', 'Audit', (int) $user['id'], ['reason' => 'wrong password']);
        Http::error('Incorrect password.', 403);
    }
    $deleted = (int) Database::pdo()->exec('DELETE FROM audit_log');
    AuditService::log('Audit Log Reset', 'Audit', (int) $user['id'], ['deleted' => $deleted]);
    Http::json(['ok' => true, 'deleted' => $deleted]);
});

/* ── Archive (soft-deleted records) ─────────────────── */

$router->get('/api/archive', static function () use ($archiver): void {
    Auth::requireRole('Admin', 'HR');
    Http::json(['data' => $archiver()->list()]);
});

$router->post('/api/archive/{id}/restore', static function (array $p) use ($archiver): void {
    $user = Auth::requireRole('Admin', 'HR');
    Http::json($archiver()->restore((int) $p['id'], (int) $user['id']));
});

$router->get('/api/notifications', static function (): void {
    Auth::user();
    $pdo = Database::pdo();
    $rows = $pdo->query('SELECT id, title, message, is_read, created_at FROM notifications ORDER BY created_at DESC LIMIT 10')->fetchAll();
    $unread = (int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE is_read = FALSE')->fetchColumn();
    Http::json(['data' => $rows, 'unread' => $unread]);
});

$router->post('/api/notifications/read-all', static function (): void {
    Auth::user();
    Database::pdo()->exec('UPDATE notifications SET is_read = TRUE');
    Http::json(['ok' => true]);
});

$router->get('/api/settings', static function (): void {
    Auth::user();
    Http::json(SettingsService::all());
});

$router->get('/api/dashboard/summary', static function () use ($dashboard): void {
    Auth::user();
    Http::json($dashboard()->summary());
});

$router->get('/api/employees', static function () use ($employees): void {
    Auth::user();
    Http::json(['data' => $employees()->all()]);
});

$router->get('/api/employees/{id}', static function (array $p) use ($employees): void {
    Auth::user();
    Http::json($employees()->find($p['id']));
});

$router->get('/api/employees/{id}/profile', static function (array $p) use ($employees): void {
    Auth::user();
    Http::json($employees()->profile($p['id']));
});

$router->get('/api/salary-history', static function (): void {
    Auth::user();
    Http::json(['data' => Database::pdo()->query(
        "SELECT sh.id, sh.effective_date, sh.basic_salary, sh.reason, sh.created_at,
                e.code, e.first_name || ' ' || e.last_name AS employee_name, e.department, e.position
           FROM salary_history sh
           JOIN employees e ON e.id = sh.employee_id
          WHERE e.is_archived = FALSE
          ORDER BY sh.effective_date DESC, sh.id DESC LIMIT 200"
    )->fetchAll()]);
});

/* ── Incentives (Compensation) ────────────────────────── */

$router->get('/api/incentives', static function () use ($incentives): void {
    Auth::user();
    Http::json([
        'structures' => $incentives()->structures($_GET['employee_id'] ?? null),
        'earnings'   => $incentives()->earnings($_GET['employee_id'] ?? null, $_GET['period'] ?? null, $_GET['status'] ?? null),
        'types'      => IncentiveService::TYPES,
        'frequencies' => IncentiveService::FREQUENCIES,
    ]);
});

$router->post('/api/incentives', static function () use ($incentives): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($incentives()->create(Http::body()), 201);
});

$router->put('/api/incentives/{id}', static function (array $p) use ($incentives): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($incentives()->update((int) $p['id'], Http::body()));
});

$router->delete('/api/incentives/{id}', static function (array $p) use ($incentives): void {
    Auth::requireRole('Admin', 'HR');
    Http::json($incentives()->delete((int) $p['id']));
});

$router->post('/api/incentives/compute', static function () use ($incentives): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    $body = Http::body();
    Http::json($incentives()->computePeriod(
        Http::requireString($body, 'period_start'),
        Http::requireString($body, 'period_end')
    ));
});

$router->post('/api/incentives/metric', static function () use ($incentives): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    $body = Http::body();
    Http::json($incentives()->recordMetric(
        (int) Http::requireString($body, 'incentive_structure_id'),
        Http::requireString($body, 'period'),
        (float) ($body['value'] ?? 0),
        trim((string) ($body['note'] ?? ''))
    ));
});

$router->get('/api/incentives/summary', static function () use ($incentives): void {
    Auth::user();
    Http::json($incentives()->summary());
});

/* ── Payroll ────────────────────────────────────────── */

$router->get('/api/payroll/runs', static function () use ($payroll): void {
    Auth::user();
    Http::json(['data' => $payroll()->listRuns()]);
});

$router->get('/api/payroll/runs/{id}', static function (array $p) use ($payroll): void {
    Auth::user();
    Http::json($payroll()->getRun((int) $p['id']));
});

/**
 * POST /api/payroll/process
 * body: { period_start, period_end, reseed?, compute?, submit_to_finance? }
 *
 * Opens the run for the period, computes every included line, and optionally
 * hands it straight to Finance — the single call behind the Payroll Run button.
 */
$router->post('/api/payroll/process', static function () use ($payroll): void {
    $user = Auth::requireRole('Admin', 'HR', 'Payroll');
    $body = Http::body();
    $service = $payroll();

    $run = $service->openRun(
        Http::requireString($body, 'period_start'),
        Http::requireString($body, 'period_end'),
        filter_var($body['reseed'] ?? false, FILTER_VALIDATE_BOOL)
    );
    if (filter_var($body['compute'] ?? true, FILTER_VALIDATE_BOOL)) {
        $service->process((int) $run['id']);
        $run = $service->getRun((int) $run['id']);
    }
    if (filter_var($body['submit_to_finance'] ?? false, FILTER_VALIDATE_BOOL)) {
        $run = $service->submitToFinance((int) $run['id'], (int) $user['id']);
    }
    Http::json($run, 201);
});

/** Recomputes a draft run and persists the figures. */
$router->post('/api/payroll/runs/{id}/compute', static function (array $p) use ($payroll): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($payroll()->process((int) $p['id']));
});

/** Returns the computation without writing anything, for a what-if preview. */
$router->post('/api/payroll/runs/{id}/preview', static function (array $p) use ($payroll): void {
    Auth::user();
    Http::json($payroll()->process((int) $p['id'], false));
});

$router->post('/api/payroll/runs/{id}/submit-to-finance', static function (array $p) use ($payroll, $events): void {
    $user = Auth::requireRole('Admin', 'HR', 'Payroll');
    $run = $payroll()->submitToFinance((int) $p['id'], (int) $user['id']);
    $events()->emit('payroll.submitted', ['run_id' => $run['id'] ?? (int) $p['id'], 'period' => $run['period'] ?? null, 'total_gross' => $run['total_gross'] ?? null]);
    Http::json($run);
});

$router->post('/api/payroll/runs/{id}/decision', static function (array $p) use ($payroll, $events): void {
    $user = Auth::requireRole('Admin', 'Finance');
    $body = Http::body();
    $decision = Http::requireString($body, 'decision');
    $run = $payroll()->decide(
        (int) $p['id'],
        (int) $user['id'],
        $decision,
        trim((string) ($body['notes'] ?? ''))
    );
    $events()->emit('payroll.' . ($decision === 'Approved' ? 'approved' : 'rejected'), [
        'run_id' => $run['id'] ?? (int) $p['id'],
        'period' => $run['period'] ?? null,
        'decision' => $decision,
        'total_net' => $run['total_net'] ?? null,
    ]);
    Http::json($run);
});

$router->get('/api/payroll/trend', static function () use ($payroll): void {
    Auth::user();
    Http::json(['data' => $payroll()->trend((string) ($_GET['range'] ?? '30d'))]);
});

$router->post('/api/payroll/runs/{id}/pay-date', static function (array $p) use ($payroll): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    $body = Http::body();
    Http::json($payroll()->setPayDate((int) $p['id'], Http::requireString($body, 'pay_date')));
});

$router->post('/api/payroll/runs/{id}/return-to-draft', static function (array $p) use ($payroll): void {
    $user = Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($payroll()->returnToDraft((int) $p['id'], (int) $user['id']));
});

$router->post('/api/payroll/runs/{id}/mark-paid', static function (array $p) use ($payroll, $events): void {
    $user = Auth::requireRole('Admin', 'Finance');
    $run = $payroll()->markPaid((int) $p['id'], (int) $user['id']);
    $events()->emit('payroll.paid', ['run_id' => $run['id'] ?? (int) $p['id'], 'period' => $run['period'] ?? null, 'total_net' => $run['total_net'] ?? null, 'pay_date' => $run['pay_date'] ?? null]);
    Http::json($run);
});

$router->post('/api/payroll/runs/{id}/close', static function (array $p) use ($payroll): void {
    $user = Auth::requireRole('Admin', 'Finance');
    Http::json($payroll()->closeRun((int) $p['id'], (int) $user['id']));
});

$router->post('/api/payroll/runs/{id}/import', static function (array $p) use ($payroll): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    $body = Http::body();
    $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
    Http::json($payroll()->importItems((int) $p['id'], $rows));
});

$router->post('/api/payroll/items/{id}/include', static function (array $p) use ($payroll): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    $body = Http::body();
    $runId = (int) Http::requireString($body, 'payroll_run_id');
    $payroll()->toggleIncluded($runId, (int) $p['id'], filter_var($body['is_included'] ?? true, FILTER_VALIDATE_BOOL));
    Http::json($payroll()->getRun($runId));
});

$router->post('/api/payslips/generate/{itemId}', static function (array $p) use ($payslip): void {
    Auth::user();
    Http::json($payslip()->build((int) $p['itemId']));
});

/* ── Claims ─────────────────────────────────────────── */

$router->get('/api/claims', static function () use ($claims): void {
    Auth::user();
    Http::json(['data' => $claims()->list($_GET['employee_id'] ?? null, $_GET['status'] ?? null)]);
});

$router->get('/api/claims/categories', static function () use ($claims): void {
    Auth::user();
    Http::json(['data' => $claims()->categories()]);
});

$router->get('/api/claims/{id}', static function (array $p) use ($claims): void {
    Auth::user();
    Http::json($claims()->get((int) $p['id']));
});

$router->post('/api/claims/submit', static function () use ($claims): void {
    Auth::requireRole('Admin', 'HR', 'Payroll', 'Finance');
    $body = Http::body();
    Http::json($claims()->submit(
        Http::requireString($body, 'employee_id'),
        Http::requireString($body, 'category'),
        Http::number($body, 'amount'),
        Http::requireString($body, 'claim_date'),
        trim((string) ($body['description'] ?? ''))
    ), 201);
});

$router->post('/api/claims/{id}/decision', static function (array $p) use ($claims): void {
    Auth::requireRole('Admin', 'HR');
    Http::json($claims()->decide((int) $p['id'], Http::requireString(Http::body(), 'status')));
});

/** Finance disbursement approval — the only way a claim becomes Paid. */
$router->post('/api/claims/{id}/disburse', static function (array $p) use ($claims): void {
    $user = Auth::requireRole('Admin', 'Finance');
    $body = Http::body();
    Http::json($claims()->disburse((int) $p['id'], (int) $user['id'], trim((string) ($body['notes'] ?? ''))));
});

$router->delete('/api/claims/{id}', static function (array $p) use ($archiver): void {
    $user = Auth::requireRole('Admin', 'HR');
    Http::json($archiver()->archive('claim', $p['id'], (int) $user['id']));
});

/* ── Benefits ──────────────────────────────────────── */

$router->get('/api/benefit-plans', static function () use ($benefits): void {
    Auth::user();
    Http::json(['data' => $benefits()->plans()]);
});

$router->get('/api/benefits/enrollments', static function () use ($benefits): void {
    Auth::user();
    Http::json(['data' => $benefits()->enrollments($_GET['employee_id'] ?? null)]);
});

$router->post('/api/benefits/enroll', static function () use ($benefits): void {
    Auth::requireRole('Admin', 'HR');
    $body = Http::body();
    Http::json($benefits()->enroll(
        Http::requireString($body, 'employee_id'),
        (int) Http::requireString($body, 'plan_id'),
        (int) ($body['dependents'] ?? 0)
    ), 201);
});

$router->post('/api/benefits/enrollments/{id}/cancel', static function (array $p) use ($benefits): void {
    Auth::requireRole('Admin', 'HR');
    Http::json($benefits()->cancel((int) $p['id']));
});

$router->delete('/api/benefits/enrollments/{id}', static function (array $p) use ($archiver): void {
    $user = Auth::requireRole('Admin', 'HR');
    Http::json($archiver()->archive('benefit_enrollment', $p['id'], (int) $user['id']));
});

/* ── Employees CRUD + compensation ──────────────────── */

$router->post('/api/employees', static function () use ($employees): void {
    Auth::requireRole('Admin', 'HR');
    Http::json($employees()->create(Http::body()), 201);
});

$router->patch('/api/employees/{id}', static function (array $p) use ($employees): void {
    Auth::requireRole('Admin', 'HR');
    Http::json($employees()->update($p['id'], Http::body()));
});

$router->delete('/api/employees/{id}', static function (array $p) use ($archiver): void {
    $user = Auth::requireRole('Admin');
    Http::json($archiver()->archive('employee', $p['id'], (int) $user['id']));
});

$router->post('/api/employees/{id}/salary', static function (array $p) use ($employees): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($employees()->updateSalary($p['id'], Http::body()));
});

$router->post('/api/employees/{id}/allowances', static function (array $p) use ($employees): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($employees()->addAllowance($p['id'], Http::body()), 201);
});

$router->delete('/api/allowances/{id}', static function (array $p) use ($employees): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($employees()->deleteAllowance((int) $p['id']));
});

$router->patch('/api/allowances/{id}', static function (array $p) use ($employees): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($employees()->updateAllowance((int) $p['id'], Http::body()));
});

$router->put('/api/loans/{id}', static function (array $p) use ($employees): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($employees()->updateLoan((int) $p['id'], Http::body()));
});

$router->post('/api/employees/{id}/loans', static function (array $p) use ($employees): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($employees()->addLoan($p['id'], Http::body()), 201);
});

$router->delete('/api/loans/{id}', static function (array $p) use ($employees): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($employees()->deleteLoan((int) $p['id']));
});

/* ── Attendance ─────────────────────────────────────── */

$router->get('/api/attendance/logs', static function () use ($attendance): void {
    Auth::user();
    Http::json(['data' => $attendance()->logs($_GET['employee_id'] ?? null, $_GET['start'] ?? null, $_GET['end'] ?? null)]);
});

$router->post('/api/attendance/logs', static function () use ($attendance): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($attendance()->log(Http::body()), 201);
});

$router->delete('/api/attendance/logs/{id}', static function (array $p) use ($attendance): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($attendance()->deleteLog((int) $p['id']));
});

$router->get('/api/attendance/timesheets', static function () use ($attendance): void {
    Auth::user();
    Http::json(['data' => $attendance()->timesheets($_GET['employee_id'] ?? null, $_GET['start'] ?? null, $_GET['end'] ?? null)]);
});

$router->get('/api/attendance/daily', static function () use ($attendance): void {
    Auth::user();
    Http::json(['data' => $attendance()->daily(Http::requireString($_GET, 'date'))]);
});

$router->get('/api/attendance/summary', static function () use ($attendance): void {
    Auth::user();
    Http::json(['data' => $attendance()->summary(Http::requireString($_GET, 'month'))]);
});

$router->get('/api/leave/balances', static function () use ($attendance): void {
    Auth::user();
    $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int) $_GET['year'] : null;
    Http::json(['data' => $attendance()->leaveBalances($year)]);
});

$router->get('/api/leave/requests', static function () use ($attendance): void {
    Auth::user();
    $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int) $_GET['year'] : null;
    Http::json(['data' => $attendance()->leaveRequests($year)]);
});

$router->post('/api/leave/requests', static function () use ($attendance): void {
    Auth::requireRole('Admin', 'HR', 'Payroll', 'Finance');
    Http::json($attendance()->fileLeave(Http::body()), 201);
});

$router->post('/api/leave/requests/{id}/decision', static function (array $p) use ($attendance): void {
    Auth::requireRole('Admin', 'HR');
    Http::json($attendance()->decideLeave((int) $p['id'], Http::requireString(Http::body(), 'status')));
});

$router->post('/api/attendance/timesheets', static function () use ($attendance): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($attendance()->saveTimesheet(Http::body()), 201);
});

$router->get('/api/attendance/shifts', static function () use ($attendance): void {
    Auth::user();
    Http::json(['data' => $attendance()->shifts($_GET['employee_id'] ?? null, $_GET['start'] ?? null, $_GET['end'] ?? null)]);
});

$router->post('/api/attendance/shifts', static function () use ($attendance): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    Http::json($attendance()->saveShift(Http::body()), 201);
});

/* ── Benefit plans maintenance ──────────────────────── */

$router->get('/api/benefit-plans/all', static function () use ($benefits): void {
    Auth::user();
    Http::json(['data' => $benefits()->allPlans()]);
});

$router->post('/api/benefit-plans', static function () use ($benefits): void {
    Auth::requireRole('Admin', 'HR');
    Http::json($benefits()->createPlan(Http::body()), 201);
});

$router->patch('/api/benefit-plans/{id}', static function (array $p) use ($benefits): void {
    Auth::requireRole('Admin', 'HR');
    Http::json($benefits()->updatePlan((int) $p['id'], Http::body()));
});

$router->delete('/api/benefit-plans/{id}', static function (array $p) use ($benefits): void {
    Auth::requireRole('Admin');
    Http::json($benefits()->deletePlan((int) $p['id']));
});

/* ── Settings update ────────────────────────────────── */

$router->put('/api/settings', static function (): void {
    $user = Auth::user();
    $body = Http::body();
    $password = (string) ($body['password'] ?? '');
    unset($body['password']);
    if ($password === '' || !Auth::verifyPassword($password)) {
        AuditService::log('Failed Settings Update', 'Settings', (int) $user['id'], ['reason' => 'wrong password']);
        Http::error('Incorrect password.', 403);
    }
    $updated = SettingsService::update($body);
    AuditService::log('Settings Updated', 'Settings', (int) $user['id'], ['keys' => array_keys($body)]);
    Http::json($updated);
});

/* ── Payslips list + PDF stream ─────────────────────── */

$router->get('/api/payslips', static function () use ($payslip): void {
    Auth::user();
    $runId = isset($_GET['run_id']) && $_GET['run_id'] !== '' ? (int) $_GET['run_id'] : null;
    Http::json(['data' => $payslip()->recent($runId, $_GET['employee_id'] ?? null)]);
});

/**
 * Password-gated payslip release (print / view / download). The caller's own
 * account password must be re-checked before the PDF leaves the server.
 */
$router->post('/api/payslips/pdf/{itemId}', static function (array $p) use ($payslip): void {
    $user = Auth::user();
    $body = Http::body();
    if (!Auth::verifyPassword(Http::requireString($body, 'password'))) {
        AuditService::log('Payslip Access Denied', 'Payslips', (int) $user['id'], ['item_id' => (int) $p['itemId']]);
        Http::error('Incorrect password. Enter your account password to release this payslip.', 403);
    }
    $result = $payslip()->pdfBytes((int) $p['itemId']);
    AuditService::log('Payslip Released', 'Payslips', (int) $user['id'], ['item_id' => (int) $p['itemId']]);
    http_response_code(200);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $result['filename'] . '"');
    header('Content-Length: ' . strlen($result['pdf']));
    echo $result['pdf'];
    exit;
});

/** Email the payslip to a verified address after the same password check. */
$router->post('/api/payslips/{itemId}/email', static function (array $p) use ($payslip, $mail): void {
    $user = Auth::user();
    $body = Http::body();
    if (!Auth::verifyPassword(Http::requireString($body, 'password'))) {
        AuditService::log('Payslip Email Denied', 'Payslips', (int) $user['id'], ['item_id' => (int) $p['itemId']]);
        Http::error('Incorrect password. Enter your account password to email this payslip.', 403);
    }
    $itemId = (int) $p['itemId'];
    $detail = $payslip()->detail($itemId);
    $toEmail = strtolower(trim((string) ($body['email'] ?? ''))) ?: (string) $user['email'];
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        Http::error('Enter a valid destination email address.', 422);
    }

    $money = static fn ($v) => number_format((float) $v, 2);
    $lines = ["Payslip for {$detail['employee']['name']} — {$detail['period']}", ''];
    foreach ($detail['earnings'] as $e) {
        $lines[] = sprintf('%-32s %14s', $e['label'], $money($e['amount']));
    }
    $lines[] = '';
    foreach ($detail['deductions'] as $e) {
        $lines[] = sprintf('%-32s %14s', $e['label'], $money($e['amount']));
    }
    $lines[] = '';
    $lines[] = 'GROSS PAY: ' . $money($detail['gross_pay']);
    $lines[] = 'TOTAL DEDUCTIONS: ' . $money($detail['total_deductions']);
    $lines[] = 'NET PAY: ' . $money($detail['net_pay']);
    $lines[] = '';
    $lines[] = 'Issued by ' . $user['name'] . ' — confidential payroll document.';

    $subject = $detail['company'] . ' Payslip — ' . $detail['employee']['name'] . ' (' . $detail['period'] . ')';
    $result = $mail()->send($toEmail, $subject, implode("\n", $lines), $detail['employee']['name'] . '-' . $detail['period'] . '.pdf');
    $result['period'] = $detail['period'];
    Http::json($result);
});

$router->get('/api/payslips/{itemId}/detail', static function (array $p) use ($payslip): void {
    Auth::user();
    Http::json($payslip()->detail((int) $p['itemId']));
});

$router->delete('/api/payslips/{itemId}', static function (array $p) use ($archiver): void {
    $user = Auth::requireRole('Admin', 'HR');
    Http::json($archiver()->archive('payslip', $p['itemId'], (int) $user['id']));
});

/* ── Thirteenth month ───────────────────────────────── */

$router->get('/api/thirteenth-month', static function () use ($thirteen): void {
    Auth::user();
    $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int) $_GET['year'] : null;
    Http::json(['data' => $thirteen()->list($year)]);
});

$router->post('/api/thirteenth-month/generate', static function () use ($thirteen): void {
    Auth::requireRole('Admin', 'HR', 'Payroll');
    $year = (int) (Http::body()['year'] ?? date('Y'));
    Http::json($thirteen()->generate($year), 201);
});

$router->post('/api/thirteenth-month/{id}/status', static function (array $p) use ($thirteen): void {
    Auth::requireRole('Admin', 'HR', 'Finance');
    Http::json($thirteen()->setStatus((int) $p['id'], Http::requireString(Http::body(), 'status')));
});


/* ── Integration (external HRMS modules) ───────────────── */

$router->get('/api/integration/capabilities', static function (): void {
    Http::json([
        'system' => 'Payroll & Benefits',
        'version' => '0.1.0',
        'auth' => 'X-Api-Key header; keys issued by an Admin or scripts/create-integration-key.php',
        'consumes' => [
            ['module' => 'Core HR', 'endpoint' => 'POST /api/integration/employees', 'scope' => 'core-hr:write'],
            ['module' => 'Workforce Management', 'endpoint' => 'POST /api/integration/attendance', 'scope' => 'workforce:write'],
            ['module' => 'Workforce Management', 'endpoint' => 'POST /api/integration/leave', 'scope' => 'workforce:write'],
            ['module' => 'Performance & Development', 'endpoint' => 'POST /api/integration/performance', 'scope' => 'performance:write'],
        ],
        'provides' => [
            ['module' => 'Financial Management', 'endpoint' => 'GET /api/integration/journal/{runId}', 'scope' => 'finance:read'],
            ['module' => 'All modules', 'events' => ['payroll.submitted', 'payroll.approved', 'payroll.rejected', 'payroll.paid'], 'delivery' => 'webhook (HMAC-SHA256 signed)'],
        ],
    ]);
});

$router->post('/api/integration/employees', static function () use ($integration): void {
    $integration()->requireScope('core-hr:write');
    $rows = Http::body()['employees'] ?? null;
    if (!is_array($rows) || $rows === []) {
        Http::error('Body must be {"employees": [ ... ]} with at least one record.', 422);
    }
    $pdo = Database::pdo();
    $str = static fn (array $e, string $k): ?string => (isset($e[$k]) && $e[$k] !== '') ? trim((string) $e[$k]) : null;
    $find = $pdo->prepare('SELECT id FROM employees WHERE code = ?');
    $upd = $pdo->prepare(
        'UPDATE employees SET first_name = COALESCE(?, first_name), middle_name = COALESCE(?, middle_name),
                last_name = COALESCE(?, last_name), suffix = COALESCE(?, suffix), email = COALESCE(?, email),
                mobile = COALESCE(?, mobile), birth_date = COALESCE(?, birth_date), gender = COALESCE(?, gender),
                department = COALESCE(?, department), position = COALESCE(?, position),
                employment_type = COALESCE(?, employment_type), hire_date = COALESCE(?, hire_date),
                basic_salary = COALESCE(?, basic_salary), status = COALESCE(?, status),
                sss = COALESCE(?, sss), philhealth = COALESCE(?, philhealth), pagibig = COALESCE(?, pagibig),
                tin = COALESCE(?, tin), ewallet_provider = COALESCE(?, ewallet_provider),
                ewallet_account = COALESCE(?, ewallet_account), ewallet_name = COALESCE(?, ewallet_name),
                updated_at = now()
           WHERE id = ?'
    );
    $ins = $pdo->prepare(
        'INSERT INTO employees (id, code, first_name, middle_name, last_name, suffix, email, mobile, birth_date, gender,
                                department, position, employment_type, hire_date, basic_salary, status, sss, philhealth, pagibig, tin,
                                ewallet_provider, ewallet_account, ewallet_name)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $pushed = 0;
    $created = 0;
    foreach ($rows as $e) {
        $code = is_array($e) ? $str($e, 'code') : null;
        $first = is_array($e) ? $str($e, 'first_name') : null;
        $last = is_array($e) ? $str($e, 'last_name') : null;
        if ($code === null || $first === null || $last === null) {
            Http::error('Each employee needs at least code, first_name and last_name.', 422);
        }
        $vals = [
            $first, $str($e, 'middle_name'), $last, $str($e, 'suffix'), $str($e, 'email'),
            $str($e, 'mobile'), $str($e, 'birth_date'), $str($e, 'gender'),
            $str($e, 'department'), $str($e, 'position'), $str($e, 'employment_type'), $str($e, 'hire_date'),
            $str($e, 'basic_salary') !== null ? (float) $str($e, 'basic_salary') : null, $str($e, 'status'),
            $str($e, 'sss'), $str($e, 'philhealth'), $str($e, 'pagibig'), $str($e, 'tin'),
            $str($e, 'ewallet_provider'), $str($e, 'ewallet_account'), $str($e, 'ewallet_name'),
        ];
        $find->execute([$code]);
        $id = $find->fetchColumn();
        if ($id !== false) {
            $upd->execute([...$vals, $id]);
        } else {
            $ins->execute(['hris-' . strtolower($code), $code, ...$vals]);
            $created++;
        }
        $pushed++;
    }
    AuditService::log('Core HR Sync', 'Integration', null, ['pushed' => $pushed, 'created' => $created]);
    Http::json(['ok' => true, 'pushed' => $pushed, 'created' => $created]);
});

$router->post('/api/integration/attendance', static function () use ($integration): void {
    $integration()->requireScope('workforce:write');
    $rows = Http::body()['logs'] ?? null;
    if (!is_array($rows) || $rows === []) {
        Http::error('Body must be {"logs": [ ... ]} with at least one record.', 422);
    }
    $pdo = Database::pdo();
    $check = $pdo->prepare('SELECT 1 FROM employees WHERE id = ?');
    $stmt = $pdo->prepare(
        'INSERT INTO attendance_logs (employee_id, log_date, status, actual_hours, ot_hours, nd_hours, holiday_hours, late_minutes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT (employee_id, log_date) DO UPDATE SET
           status = EXCLUDED.status, actual_hours = EXCLUDED.actual_hours, ot_hours = EXCLUDED.ot_hours,
           nd_hours = EXCLUDED.nd_hours, holiday_hours = EXCLUDED.holiday_hours, late_minutes = EXCLUDED.late_minutes'
    );
    $saved = 0;
    foreach ($rows as $l) {
        $eid = trim((string) (is_array($l) ? $l['employee_id'] ?? '' : ''));
        $date = trim((string) (is_array($l) ? $l['log_date'] ?? '' : ''));
        if ($eid === '' || $date === '') {
            Http::error('Each log needs employee_id and log_date (YYYY-MM-DD).', 422);
        }
        $check->execute([$eid]);
        if ($check->fetchColumn() === false) {
            Http::error('Unknown employee_id ' . $eid . '. Sync Core HR employees first.', 422);
        }
        $status = in_array((string) ($l['status'] ?? 'P'), ['P', 'H', 'A', 'OT'], true) ? (string) $l['status'] : 'P';
        $stmt->execute([
            $eid, $date, $status,
            (float) ($l['actual_hours'] ?? 0), (float) ($l['ot_hours'] ?? 0),
            (float) ($l['nd_hours'] ?? 0), (float) ($l['holiday_hours'] ?? 0), (float) ($l['late_minutes'] ?? 0),
        ]);
        $saved++;
    }
    AuditService::log('Workforce Sync', 'Integration', null, ['logs' => $saved]);
    Http::json(['ok' => true, 'saved' => $saved]);
});

$router->post('/api/integration/leave', static function () use ($integration): void {
    $integration()->requireScope('workforce:write');
    $rows = Http::body()['requests'] ?? null;
    if (!is_array($rows) || $rows === []) {
        Http::error('Body must be {"requests": [ ... ]} with at least one record.', 422);
    }
    $pdo = Database::pdo();
    $check = $pdo->prepare('SELECT 1 FROM employees WHERE id = ?');
    $stmt = $pdo->prepare(
        'INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, days, is_paid, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $saved = 0;
    foreach ($rows as $r) {
        $eid = trim((string) (is_array($r) ? $r['employee_id'] ?? '' : ''));
        $type = trim((string) (is_array($r) ? $r['leave_type'] ?? '' : ''));
        $start = trim((string) (is_array($r) ? $r['start_date'] ?? '' : ''));
        if ($eid === '' || $type === '' || $start === '') {
            Http::error('Each leave request needs employee_id, leave_type and start_date.', 422);
        }
        $check->execute([$eid]);
        if ($check->fetchColumn() === false) {
            Http::error('Unknown employee_id ' . $eid . '. Sync Core HR employees first.', 422);
        }
        $status = in_array((string) ($r['status'] ?? 'Approved'), ['Pending', 'Approved', 'Rejected'], true) ? (string) $r['status'] : 'Approved';
        $stmt->execute([
            $eid, $type, $start,
            trim((string) ($r['end_date'] ?? '')) ?: $start,
            (float) ($r['days'] ?? 0),
            (bool) ($r['is_paid'] ?? true),
            $status,
        ]);
        $saved++;
    }
    AuditService::log('Workforce Leave Sync', 'Integration', null, ['requests' => $saved]);
    Http::json(['ok' => true, 'saved' => $saved]);
});

$router->post('/api/integration/performance', static function () use ($integration): void {
    $integration()->requireScope('performance:write');
    $rows = Http::body()['ratings'] ?? null;
    if (!is_array($rows) || $rows === []) {
        Http::error('Body must be {"ratings": [ ... ]} with at least one record.', 422);
    }
    $pdo = Database::pdo();
    $stmt = $pdo->prepare('INSERT INTO performance_ratings (employee_id, review_date, rating, bonus_amount) VALUES (?, ?, ?, ?)');
    $saved = 0;
    foreach ($rows as $r) {
        $eid = trim((string) (is_array($r) ? $r['employee_id'] ?? '' : ''));
        $date = trim((string) (is_array($r) ? $r['review_date'] ?? '' : ''));
        if ($eid === '' || $date === '') {
            Http::error('Each rating needs employee_id and review_date.', 422);
        }
        $stmt->execute([$eid, $date, trim((string) ($r['rating'] ?? '')) ?: null, (float) ($r['bonus_amount'] ?? 0)]);
        $saved++;
    }
    AuditService::log('Performance Sync', 'Integration', null, ['ratings' => $saved]);
    Http::json(['ok' => true, 'saved' => $saved]);
});

$router->get('/api/integration/journal/{runId}', static function (array $p) use ($integration): void {
    $integration()->requireScope('finance:read');
    $pdo = Database::pdo();
    $stmt = $pdo->prepare('SELECT * FROM payroll_runs WHERE id = ?');
    $stmt->execute([(int) $p['runId']]);
    $run = $stmt->fetch();
    if ($run === false) {
        Http::error('No such payroll run.', 404);
    }
    $gl = $pdo->prepare('SELECT entry_date, account_code, account_name, debit, credit, memo FROM general_ledger_entries WHERE payroll_run_id = ? ORDER BY account_code');
    $gl->execute([(int) $p['runId']]);
    $disb = $pdo->prepare('SELECT employee_id, amount, provider, reference_no, status, disbursed_at FROM disbursement_records WHERE payroll_run_id = ? ORDER BY employee_id');
    $disb->execute([(int) $p['runId']]);
    Http::json([
        'run' => $run,
        'journal_entries' => $gl->fetchAll(),
        'disbursements' => $disb->fetchAll(),
    ]);
});

/* Key + webhook administration (signed-in Admin only). */

$router->get('/api/integration/keys', static function (): void {
    Auth::requireRole('Admin');
    Http::json(['data' => Database::pdo()->query(
        'SELECT id, name, scopes, is_active, created_at, last_used_at FROM integration_keys ORDER BY id'
    )->fetchAll()]);
});

$router->post('/api/integration/keys', static function () use ($integration): void {
    Auth::requireRole('Admin');
    $body = Http::body();
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        Http::error('A key name is required.', 422);
    }
    $scopes = trim((string) ($body['scopes'] ?? '*')) ?: '*';
    $plain = $integration()->issueKey($name, $scopes);
    AuditService::log('Integration Key Issued', 'Integration', null, ['name' => $name, 'scopes' => $scopes]);
    Http::json(['ok' => true, 'name' => $name, 'key' => $plain, 'scopes' => $scopes], 201);
});

$router->delete('/api/integration/keys/{id}', static function (array $p): void {
    Auth::requireRole('Admin');
    $stmt = Database::pdo()->prepare('UPDATE integration_keys SET is_active = FALSE WHERE id = ?');
    $stmt->execute([(int) $p['id']]);
    Http::json(['ok' => true]);
});

$router->get('/api/integration/webhooks', static function (): void {
    Auth::requireRole('Admin');
    Http::json(['data' => Database::pdo()->query(
        'SELECT id, name, event, url, is_active, created_at FROM webhook_endpoints ORDER BY id'
    )->fetchAll()]);
});

$router->post('/api/integration/webhooks', static function (): void {
    Auth::requireRole('Admin');
    $body = Http::body();
    $name = trim((string) ($body['name'] ?? ''));
    $event = trim((string) ($body['event'] ?? '*'));
    $url = trim((string) ($body['url'] ?? ''));
    if ($name === '' || !preg_match('#^https?://#', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        Http::error('name and a valid http(s) url are required.', 422);
    }
    $stmt = Database::pdo()->prepare(
        "INSERT INTO webhook_endpoints (name, event, url, secret, is_active) VALUES (?, ?, ?, ?, TRUE)
         ON CONFLICT (name) DO UPDATE SET event = EXCLUDED.event, url = EXCLUDED.url, secret = EXCLUDED.secret, is_active = TRUE"
    );
    $stmt->execute([$name, $event !== '' ? $event : '*', $url, trim((string) ($body['secret'] ?? ''))]);
    Http::json(['ok' => true]);
});

$router->delete('/api/integration/webhooks/{id}', static function (array $p): void {
    Auth::requireRole('Admin');
    $stmt = Database::pdo()->prepare('UPDATE webhook_endpoints SET is_active = FALSE WHERE id = ?');
    $stmt->execute([(int) $p['id']]);
    Http::json(['ok' => true]);
});

/* ── Dispatch ───────────────────────────────────────── */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/' || str_ends_with($path, '/index.php')) {
    $path = '/' . ltrim((string) ($_GET['r'] ?? ''), '/');
}
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
