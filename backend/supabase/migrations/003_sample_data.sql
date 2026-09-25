-- =====================================================
-- PAYROLL & BENEFITS — Supabase / PostgreSQL: FULL SAMPLE DATA
-- Run AFTER 001_schema.sql.
--
-- Idempotent demo dataset translated from the legacy MySQL app
-- (schema.sql + migrations/sample_*.sql), rewritten for the Postgres
-- target schema used by both the PHP version and the React web version.
-- Re-running resets the demo tables to a known state.
--
-- DEMO LOGIN (all accounts) — password:  Admin@1234
--   admin@trilogistics.com / hr@trilogistics.com /
--   finance@trilogistics.com / payroll@trilogistics.com
-- =====================================================

TRUNCATE
  public.audit_log, public.notifications, public.app_users,
  public.general_ledger_entries, public.disbursement_records, public.finance_approvals,
  public.payroll_items, public.payroll_runs, public.benefit_enrollments, public.benefit_plans,
  public.claims, public.claim_categories, public.leave_conversions, public.leave_requests,
  public.leave_balances, public.performance_ratings, public.recognition_awards,
  public.training_records, public.competency_assessments, public.cash_positions,
  public.budget_allocations, public.shift_schedules, public.timesheets, public.attendance_logs,
  public.loans, public.allowances, public.salary_history, public.employees, public.departments,
  public.system_settings
RESTART IDENTITY CASCADE;

-- ── APP USERS (bcrypt hash of 'Admin@1234') ──
INSERT INTO public.app_users (email, password, name, role, initials, is_active) VALUES
  ('admin@trilogistics.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin User',     'Admin',   'AU', TRUE),
  ('hr@trilogistics.com',      '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria Santos',   'HR',      'MS', TRUE),
  ('finance@trilogistics.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Eduardo Ramos',  'Finance', 'ER', TRUE),
  ('payroll@trilogistics.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Juan Dela Cruz', 'Payroll', 'JD', TRUE);

-- ── DEPARTMENTS ──
INSERT INTO public.departments (name) VALUES
  ('IT Department'), ('HR Department'), ('Finance Department'), ('Marketing'), ('Operations');

-- ── SYSTEM SETTINGS (rates the payroll engine reads) ──
INSERT INTO public.system_settings (setting_key, setting_value) VALUES
  ('company_name', 'TRI-M GLOBAL LOGISTICS & TRADING INC.'),
  ('currency_symbol', '₱'),
  ('currency_decimals', '2'),
  ('work_days_per_month', '22'),
  ('work_hours_per_day', '8'),
  ('philhealth_rate', '2'),
  ('sss_ee_fixed', '900'),
  ('sss_er_rate', '9.5'),
  ('sss_er_cap', '1900'),
  ('pagibig_ee_min', '50'),
  ('pagibig_ee_max', '100'),
  ('pagibig_er', '100');

-- ── EMPLOYEES (20) ──
INSERT INTO public.employees
  (id, code, first_name, middle_name, last_name, suffix, email, mobile,
   birth_date, gender, department, position, employment_type, hire_date,
   basic_salary, status, sss, philhealth, pagibig, tin,
   ewallet_provider, ewallet_account, ewallet_name, ewallet_primary) VALUES
('emp-001','EMP-2026-001','Juan','Carlos','Dela Cruz',NULL,'juan.delacruz@trilogistics.com','09171234501','1990-05-15','Male','IT Department','Senior Software Engineer','Regular','2025-01-15',65000.00,'Active','12-3456789-0','12-345678901-2','1234-5678-9012','123-456-789-000','GCash','09171234501','Juan Dela Cruz',TRUE),
('emp-002','EMP-2026-002','Maria','Isabel','Santos',NULL,'maria.santos@trilogistics.com','09281234502','1988-11-22','Female','HR Department','HR Manager','Regular','2024-03-01',72000.00,'Active','09-8765432-1','09-876543210-1','9876-5432-1098','987-654-321-000','Maya','09281234502','Maria Santos',TRUE),
('emp-003','EMP-2026-003','Jose','Miguel','Reyes',NULL,'jose.reyes@trilogistics.com','09391234503','1992-03-08','Male','Finance Department','Accountant','Regular','2025-06-15',58000.00,'Active','56-7890123-4','56-789012345-6','5678-9012-3456','567-890-123-000','GCash','09391234503','Jose Reyes',TRUE),
('emp-004','EMP-2026-004','Anna','Marie','Garcia',NULL,'anna.garcia@trilogistics.com','09451234504','1995-07-30','Female','Marketing','Marketing Specialist','Regular','2025-09-01',52000.00,'On Leave','23-4567890-1','23-456789012-3','2345-6789-0123','234-567-890-000','Bank','001234567890','Anna Garcia',TRUE),
('emp-005','EMP-2026-005','Miguel','Antonio','Fernandez',NULL,'miguel.fernandez@trilogistics.com','09561234505','1987-01-12','Male','Operations','Operations Manager','Regular','2024-11-01',78000.00,'Active','34-5678901-2','34-567890123-4','3456-7890-1234','345-678-901-000','Bank','002345678901','Miguel Fernandez',TRUE),
('emp-006','EMP-2026-006','Liza','Ann','Tan',NULL,'liza.tan@trilogistics.com','09671234506','1993-09-25','Female','IT Department','QA Engineer','Regular','2024-08-15',48000.00,'Resigned','45-6789012-3','45-678901234-5','4567-8901-2345','456-789-012-000','GCash','09671234506','Liza Tan',TRUE),
('emp-007','EMP-2026-007','Ramon','Luis','Villanueva',NULL,'ramon.villanueva@trilogistics.com','09781234507','1991-12-03','Male','Operations','Operations Supervisor','Regular','2025-04-01',62000.00,'Active','67-8901234-5','67-890123456-7','6789-0123-4567','678-901-234-000','Maya','09781234507','Ramon Villanueva',TRUE),
('emp-008','EMP-2026-008','Cristina','Grace','Lopez',NULL,'cristina.lopez@trilogistics.com','09891234508','1996-04-18','Female','HR Department','HR Associate','Regular','2025-07-15',45000.00,'Active','78-9012345-6','78-901234567-8','7890-1234-5678','789-012-345-000','Cash','','Cristina Lopez',TRUE),
('emp-009','EMP-2026-009','Roberto','Jose','Aquino',NULL,'roberto.aquino@trilogistics.com','09171234509','1989-08-20','Male','IT Department','Systems Analyst','Regular','2025-02-01',55000.00,'Active','89-0123456-7','89-012345678-9','8901-2345-6789','890-123-456-000','GCash','09171234509','Roberto Aquino',TRUE),
('emp-010','EMP-2026-010','Diana','Rose','Mendoza',NULL,'diana.mendoza@trilogistics.com','09281234510','1994-02-14','Female','Finance Department','Finance Officer','Regular','2025-05-01',56000.00,'Active','90-1234567-8','90-123456789-0','9012-3456-7890','901-234-567-000','Maya','09281234510','Diana Mendoza',TRUE),
('emp-011','EMP-2026-011','Carlos','Eduardo','Torres',NULL,'carlos.torres@trilogistics.com','09391234511','1985-06-10','Male','Operations','Logistics Coordinator','Regular','2024-07-01',47000.00,'Active','01-2345678-9','01-234567890-1','0123-4567-8901','012-345-678-000','Bank','003456789012','Carlos Torres',TRUE),
('emp-012','EMP-2026-012','Patricia','Anne','Cruz',NULL,'patricia.cruz@trilogistics.com','09451234512','1997-10-05','Female','Marketing','Digital Marketing Associate','Probationary','2026-01-15',38000.00,'Active','12-3456780-1','12-345678902-3','1234-5678-9013','123-456-780-000','GCash','09451234512','Patricia Cruz',TRUE),
('emp-013','EMP-2026-013','Eduardo','Victor','Ramos',NULL,'eduardo.ramos@trilogistics.com','09561234513','1983-03-28','Male','Finance Department','Finance Manager','Regular','2023-09-01',85000.00,'Active','23-4567891-2','23-456789013-4','2345-6789-0134','234-567-891-000','Bank','004567890123','Eduardo Ramos',TRUE),
('emp-014','EMP-2026-014','Josephine','Clara','Bautista',NULL,'josephine.bautista@trilogistics.com','09671234514','1991-07-17','Female','IT Department','Software Developer','Regular','2025-03-01',52000.00,'Active','34-5678902-3','34-567890124-5','3456-7890-1235','345-678-902-000','Maya','09671234514','Josephine Bautista',TRUE),
('emp-015','EMP-2026-015','Andres','Pablo','Navarro',NULL,'andres.navarro@trilogistics.com','09781234515','1988-12-01','Male','Operations','Warehouse Supervisor','Regular','2024-04-15',55000.00,'Active','45-6789013-4','45-678901235-6','4567-8901-2356','456-789-013-000','Cash','','Andres Navarro',TRUE),
('emp-016','EMP-2026-016','Rosario','Luz','Santiago',NULL,'rosario.santiago@trilogistics.com','09891234516','1993-05-22','Female','HR Department','Recruitment Specialist','Regular','2025-10-01',48000.00,'Active','56-7890124-5','56-789012346-7','5678-9012-3467','567-890-124-000','GCash','09891234516','Rosario Santiago',TRUE),
('emp-017','EMP-2026-017','Ferdinand','Noel','Castillo',NULL,'ferdinand.castillo@trilogistics.com','09171234517','1986-09-14','Male','Marketing','Brand Manager','Regular','2024-06-01',68000.00,'Active','67-8901235-6','67-890123457-8','6789-0123-4578','678-901-235-000','Bank','005678901234','Ferdinand Castillo',TRUE),
('emp-018','EMP-2026-018','Maricel','Joy','Diaz',NULL,'maricel.diaz@trilogistics.com','09281234518','1998-01-30','Female','IT Department','Junior Developer','Probationary','2026-03-01',35000.00,'Active','78-9012346-7','78-901234568-9','7890-1234-5679','789-012-346-000','GCash','09281234518','Maricel Diaz',TRUE),
('emp-019','EMP-2026-019','Ronaldo','Mark','Lim',NULL,'ronaldo.lim@trilogistics.com','09391234519','1980-11-08','Male','Operations','Fleet Manager','Regular','2023-06-01',72000.00,'Active','89-0123457-8','89-012345679-0','8901-2345-6790','890-123-457-000','Maya','09391234519','Ronaldo Lim',TRUE),
('emp-020','EMP-2026-020','Grace','Faith','Uy',NULL,'grace.uy@trilogistics.com','09451234520','1995-04-03','Female','Finance Department','Budget Analyst','Regular','2025-08-01',50000.00,'Active','90-1234568-9','90-123456780-1','9012-3456-7891','901-234-568-000','GCash','09451234520','Grace Uy',TRUE);

-- ── SALARY HISTORY ──
INSERT INTO public.salary_history (employee_id, basic_salary, effective_date, reason) VALUES
('emp-001', 60000.00, '2025-01-15', 'Initial salary on hire'),('emp-001', 65000.00, '2026-01-15', 'Annual merit increase 8%'),
('emp-002', 65000.00, '2024-03-01', 'Initial salary on hire'),('emp-002', 72000.00, '2026-01-01', 'Promoted to HR Manager'),
('emp-003', 55000.00, '2025-06-15', 'Initial salary on hire'),('emp-003', 58000.00, '2026-01-01', 'Performance-based increase'),
('emp-004', 50000.00, '2025-09-01', 'Initial salary on hire'),('emp-004', 52000.00, '2026-01-01', 'Merit increase'),
('emp-005', 72000.00, '2024-11-01', 'Initial salary on hire'),('emp-005', 78000.00, '2026-01-01', 'Promoted to Operations Manager'),
('emp-006', 45000.00, '2024-08-15', 'Initial salary on hire'),('emp-006', 48000.00, '2025-08-15', 'Annual increase'),
('emp-007', 58000.00, '2025-04-01', 'Initial salary on hire'),('emp-007', 62000.00, '2026-01-01', 'Merit increase'),
('emp-008', 43000.00, '2025-07-15', 'Initial salary on hire'),('emp-008', 45000.00, '2026-01-01', 'Probationary completion increase'),
('emp-009', 52000.00, '2025-02-01', 'Initial salary on hire'),('emp-009', 55000.00, '2026-01-01', 'Annual merit increase'),
('emp-010', 54000.00, '2025-05-01', 'Initial salary on hire'),('emp-010', 56000.00, '2026-01-01', 'Performance increase'),
('emp-011', 44000.00, '2024-07-01', 'Initial salary on hire'),('emp-011', 47000.00, '2026-01-01', 'Annual increase'),
('emp-012', 38000.00, '2026-01-15', 'Initial salary on hire'),
('emp-013', 80000.00, '2023-09-01', 'Initial salary on hire'),('emp-013', 85000.00, '2026-01-01', 'Promoted to Finance Manager'),
('emp-014', 50000.00, '2025-03-01', 'Initial salary on hire'),('emp-014', 52000.00, '2026-01-01', 'Merit increase'),
('emp-015', 52000.00, '2024-04-15', 'Initial salary on hire'),('emp-015', 55000.00, '2026-01-01', 'Annual increase'),
('emp-016', 48000.00, '2025-10-01', 'Initial salary on hire'),
('emp-017', 62000.00, '2024-06-01', 'Initial salary on hire'),('emp-017', 68000.00, '2026-01-01', 'Promoted to Brand Manager'),
('emp-018', 35000.00, '2026-03-01', 'Initial salary on hire'),
('emp-019', 68000.00, '2023-06-01', 'Initial salary on hire'),('emp-019', 72000.00, '2026-01-01', 'Annual merit increase'),
('emp-020', 48000.00, '2025-08-01', 'Initial salary on hire'),('emp-020', 50000.00, '2026-01-01', 'Performance increase');

-- ── ALLOWANCES (effective_date -> start_date) ──
INSERT INTO public.allowances (employee_id, type, amount, frequency, start_date, is_active) VALUES
('emp-001','Rice',2000.00,'Monthly','2025-01-15',TRUE),('emp-001','Transport',1500.00,'Monthly','2025-01-15',TRUE),
('emp-001','Communication',1000.00,'Monthly','2025-01-15',TRUE),('emp-001','Meal',1500.00,'Monthly','2025-01-15',TRUE),
('emp-001','Clothing',5000.00,'Annual','2025-01-15',TRUE),
('emp-002','Rice',2000.00,'Monthly','2024-03-01',TRUE),('emp-002','Transport',1500.00,'Monthly','2024-03-01',TRUE),
('emp-002','Communication',1000.00,'Monthly','2024-03-01',TRUE),('emp-002','Meal',1500.00,'Monthly','2024-03-01',TRUE),
('emp-002','Housing',5000.00,'Monthly','2024-03-01',TRUE),
('emp-003','Rice',2000.00,'Monthly','2025-06-15',TRUE),('emp-003','Transport',1000.00,'Monthly','2025-06-15',TRUE),('emp-003','Meal',500.00,'Monthly','2025-06-15',TRUE),
('emp-004','Rice',2000.00,'Monthly','2025-09-01',TRUE),('emp-004','Transport',1000.00,'Monthly','2025-09-01',TRUE),('emp-004','Meal',1000.00,'Monthly','2025-09-01',TRUE),
('emp-005','Rice',2000.00,'Monthly','2024-11-01',TRUE),('emp-005','Transport',2000.00,'Monthly','2024-11-01',TRUE),
('emp-005','Communication',1500.00,'Monthly','2024-11-01',TRUE),('emp-005','Meal',1500.00,'Monthly','2024-11-01',TRUE),('emp-005','Housing',5000.00,'Monthly','2024-11-01',TRUE),
('emp-006','Rice',2000.00,'Monthly','2024-08-15',TRUE),('emp-006','Transport',1000.00,'Monthly','2024-08-15',TRUE),
('emp-007','Rice',2000.00,'Monthly','2025-04-01',TRUE),('emp-007','Transport',1500.00,'Monthly','2025-04-01',TRUE),('emp-007','Communication',500.00,'Monthly','2025-04-01',TRUE),('emp-007','Meal',1000.00,'Monthly','2025-04-01',TRUE),
('emp-008','Rice',2000.00,'Monthly','2025-07-15',TRUE),('emp-008','Transport',800.00,'Monthly','2025-07-15',TRUE),
('emp-009','Rice',2000.00,'Monthly','2025-02-01',TRUE),('emp-009','Transport',1000.00,'Monthly','2025-02-01',TRUE),('emp-009','Communication',500.00,'Monthly','2025-02-01',TRUE),
('emp-010','Rice',2000.00,'Monthly','2025-05-01',TRUE),('emp-010','Transport',1000.00,'Monthly','2025-05-01',TRUE),('emp-010','Meal',500.00,'Monthly','2025-05-01',TRUE),
('emp-011','Rice',2000.00,'Monthly','2024-07-01',TRUE),('emp-011','Transport',1500.00,'Monthly','2024-07-01',TRUE),
('emp-012','Rice',2000.00,'Monthly','2026-01-15',TRUE),('emp-012','Transport',800.00,'Monthly','2026-01-15',TRUE),
('emp-013','Rice',2000.00,'Monthly','2023-09-01',TRUE),('emp-013','Transport',2000.00,'Monthly','2023-09-01',TRUE),('emp-013','Communication',1500.00,'Monthly','2023-09-01',TRUE),('emp-013','Housing',6000.00,'Monthly','2023-09-01',TRUE),('emp-013','Meal',2000.00,'Monthly','2023-09-01',TRUE),
('emp-014','Rice',2000.00,'Monthly','2025-03-01',TRUE),('emp-014','Transport',1000.00,'Monthly','2025-03-01',TRUE),('emp-014','Communication',500.00,'Monthly','2025-03-01',TRUE),
('emp-015','Rice',2000.00,'Monthly','2024-04-15',TRUE),('emp-015','Transport',1500.00,'Monthly','2024-04-15',TRUE),('emp-015','Meal',1000.00,'Monthly','2024-04-15',TRUE),
('emp-016','Rice',2000.00,'Monthly','2025-10-01',TRUE),('emp-016','Transport',1000.00,'Monthly','2025-10-01',TRUE),
('emp-017','Rice',2000.00,'Monthly','2024-06-01',TRUE),('emp-017','Transport',1500.00,'Monthly','2024-06-01',TRUE),('emp-017','Communication',1000.00,'Monthly','2024-06-01',TRUE),('emp-017','Meal',1500.00,'Monthly','2024-06-01',TRUE),
('emp-018','Rice',2000.00,'Monthly','2026-03-01',TRUE),('emp-018','Transport',700.00,'Monthly','2026-03-01',TRUE),
('emp-019','Rice',2000.00,'Monthly','2023-06-01',TRUE),('emp-019','Transport',2000.00,'Monthly','2023-06-01',TRUE),('emp-019','Communication',1000.00,'Monthly','2023-06-01',TRUE),('emp-019','Meal',1500.00,'Monthly','2023-06-01',TRUE),('emp-019','Housing',4000.00,'Monthly','2023-06-01',TRUE),
('emp-020','Rice',2000.00,'Monthly','2025-08-01',TRUE),('emp-020','Transport',1000.00,'Monthly','2025-08-01',TRUE),('emp-020','Meal',500.00,'Monthly','2025-08-01',TRUE);

-- ── LOANS (type->loan_type, total_amount->principal, remaining_balance->balance, start_date->approved_at) ──
INSERT INTO public.loans (employee_id, loan_type, principal, monthly_deduction, balance, status, approved_at) VALUES
('emp-001','SSS Loan',30000.00,2500.00,20000.00,'Active','2025-06-01'),('emp-002','Pag-IBIG Loan',50000.00,2000.00,40000.00,'Active','2025-03-01'),
('emp-003','Company Loan',20000.00,1000.00,15000.00,'Active','2025-09-01'),('emp-005','SSS Loan',60000.00,3000.00,45000.00,'Active','2025-07-01'),
('emp-007','Pag-IBIG Loan',25000.00,1250.00,18750.00,'Active','2025-08-01'),('emp-009','SSS Loan',15000.00,1000.00,12000.00,'Active','2025-10-01'),
('emp-013','Company Loan',80000.00,4000.00,64000.00,'Active','2025-05-01'),('emp-015','Pag-IBIG Loan',30000.00,1500.00,24000.00,'Active','2025-11-01'),
('emp-017','SSS Loan',40000.00,2000.00,30000.00,'Active','2025-08-01'),('emp-019','Company Loan',60000.00,3000.00,48000.00,'Active','2024-12-01');

-- ── LEAVE BALANCES (2026) ──
INSERT INTO public.leave_balances (employee_id, leave_type, accrued, used, balance, year) VALUES
('emp-001','Vacation Leave',15,3,12,2026),('emp-001','Sick Leave',15,2,13,2026),
('emp-002','Vacation Leave',15,5,10,2026),('emp-002','Sick Leave',15,1,14,2026),
('emp-003','Vacation Leave',15,0,15,2026),('emp-003','Sick Leave',15,0,15,2026),
('emp-004','Vacation Leave',15,8,7,2026),('emp-004','Sick Leave',15,4,11,2026),
('emp-005','Vacation Leave',15,2,13,2026),('emp-005','Sick Leave',15,0,15,2026),
('emp-007','Vacation Leave',15,1,14,2026),('emp-007','Sick Leave',15,2,13,2026),
('emp-008','Vacation Leave',15,0,15,2026),('emp-008','Sick Leave',15,3,12,2026),
('emp-009','Vacation Leave',15,2,13,2026),('emp-009','Sick Leave',15,1,14,2026),
('emp-010','Vacation Leave',15,0,15,2026),('emp-010','Sick Leave',15,0,15,2026),
('emp-011','Vacation Leave',15,4,11,2026),('emp-011','Sick Leave',15,2,13,2026),
('emp-012','Vacation Leave',5,0,5,2026),('emp-012','Sick Leave',5,0,5,2026),
('emp-013','Vacation Leave',15,3,12,2026),('emp-013','Sick Leave',15,1,14,2026),
('emp-014','Vacation Leave',15,0,15,2026),('emp-014','Sick Leave',15,1,14,2026),
('emp-015','Vacation Leave',15,2,13,2026),('emp-015','Sick Leave',15,0,15,2026),
('emp-016','Vacation Leave',15,0,15,2026),('emp-016','Sick Leave',15,0,15,2026),
('emp-017','Vacation Leave',15,1,14,2026),('emp-017','Sick Leave',15,2,13,2026),
('emp-018','Vacation Leave',5,0,5,2026),('emp-018','Sick Leave',5,0,5,2026),
('emp-019','Vacation Leave',15,3,12,2026),('emp-019','Sick Leave',15,1,14,2026),
('emp-020','Vacation Leave',15,0,15,2026),('emp-020','Sick Leave',15,0,15,2026);

-- ── BENEFIT PLANS ──
INSERT INTO public.benefit_plans (plan_code, plan_name, plan_type, provider, monthly_premium, employer_share, employee_share, description, is_active) VALUES
('HMO-BASIC','Basic HMO Plan','HMO','Maxicare',1500.00,80,20,'Basic health maintenance coverage',TRUE),
('HMO-PLUS','HMO Plus Plan','HMO','Maxicare',2500.00,90,10,'Enhanced HMO with dental and optical',TRUE),
('LIFE-BASIC','Group Life Insurance','Life Insurance','Sun Life',500.00,100,0,'Group term life equal to 24x monthly salary',TRUE);

-- ── BENEFIT ENROLLMENTS (derived from plans; idempotent) ──
INSERT INTO public.benefit_enrollments
  (employee_id, employee_name, plan_id, plan_name, provider, monthly_premium, employer_share, employee_share, dependents, effective_date, status)
SELECT e.id, trim(e.first_name || ' ' || e.last_name), bp.id, bp.plan_name, bp.provider, bp.monthly_premium, bp.employer_share, bp.employee_share, 0, e.hire_date, 'Active'
  FROM public.employees e JOIN public.benefit_plans bp ON bp.plan_code = 'HMO-BASIC'
 WHERE e.id IN ('emp-001','emp-002','emp-003','emp-005','emp-007','emp-008','emp-009','emp-010','emp-011','emp-014','emp-015','emp-016','emp-018','emp-020')
   AND NOT EXISTS (SELECT 1 FROM public.benefit_enrollments be WHERE be.employee_id = e.id AND be.plan_id = bp.id);

INSERT INTO public.benefit_enrollments
  (employee_id, employee_name, plan_id, plan_name, provider, monthly_premium, employer_share, employee_share, dependents, effective_date, status)
SELECT e.id, trim(e.first_name || ' ' || e.last_name), bp.id, bp.plan_name, bp.provider, bp.monthly_premium, bp.employer_share, bp.employee_share, 2, e.hire_date, 'Active'
  FROM public.employees e JOIN public.benefit_plans bp ON bp.plan_code = 'HMO-PLUS'
 WHERE e.id IN ('emp-004','emp-006','emp-012','emp-013','emp-017','emp-019')
   AND NOT EXISTS (SELECT 1 FROM public.benefit_enrollments be WHERE be.employee_id = e.id AND be.plan_id = bp.id);

INSERT INTO public.benefit_enrollments
  (employee_id, employee_name, plan_id, plan_name, provider, monthly_premium, employer_share, employee_share, dependents, effective_date, status)
SELECT e.id, trim(e.first_name || ' ' || e.last_name), bp.id, bp.plan_name, bp.provider, bp.monthly_premium, bp.employer_share, bp.employee_share, 0, e.hire_date, 'Active'
  FROM public.employees e JOIN public.benefit_plans bp ON bp.plan_code = 'LIFE-BASIC'
 WHERE e.status = 'Active'
   AND NOT EXISTS (SELECT 1 FROM public.benefit_enrollments be WHERE be.employee_id = e.id AND be.plan_id = bp.id);

-- ── CLAIM CATEGORIES ──
INSERT INTO public.claim_categories (id, name, max_amount) VALUES
  ('cat-transport','Transportation',1000.00),('cat-meal','Meal Allowance',500.00),
  ('cat-medical','Medical',5000.00),('cat-supplies','Office Supplies',2000.00),
  ('cat-training','Training',10000.00),('cat-ot','Overtime',300.00);

-- ── CLAIMS ──
INSERT INTO public.claims (claim_number, employee_name, employee_id, category, description, amount, claim_date, status) VALUES
('CLM202609180011','Juan Carlos Dela Cruz','emp-001','Transportation','Client site visit in Taguig for payroll migration',420.00,'2026-09-18','Pending'),
('CLM202609170012','Maria Isabel Santos','emp-002','Meal Allowance','Team building luncheon with new hires',480.00,'2026-09-17','Pending'),
('CLM202609160013','Jose Miguel Reyes','emp-003','Office Supplies','Ledger paper and toner for audit working files',720.00,'2026-09-16','Approved'),
('CLM202609150014','Miguel Antonio Fernandez','emp-005','Transportation','Warehouse inspection travel to Cavite',650.00,'2026-09-15','Paid'),
('CLM202609140015','Liza Ann Tan','emp-006','Medical','Dental consultation and cleaning',950.00,'2026-09-14','Pending'),
('CLM202609120016','Ramon Luis Villanueva','emp-007','Overtime','Night shift support for refrigerated delivery',300.00,'2026-09-12','Approved'),
('CLM202609110017','Roberto Jose Aquino','emp-009','Training','ITIL 4 Foundation exam fee',1800.00,'2026-09-11','Paid'),
('CLM202609100018','Diana Rose Mendoza','emp-010','Office Supplies','External hard drive for backed-up remittance records',1450.00,'2026-09-10','Approved'),
('CLM202609090019','Carlos Eduardo Torres','emp-011','Transportation','Dispatch run to Bulacan client drop',560.00,'2026-09-09','Rejected'),
('CLM202609080020','Eduardo Victor Ramos','emp-013','Meal Allowance','Working lunch with external auditors',1250.00,'2026-09-08','Paid'),
('CLM202609070021','Josephine Clara Bautista','emp-014','Medical','Consultation at The Medical City',1100.00,'2026-09-07','Pending'),
('CLM202609050022','Andres Pablo Navarro','emp-015','Overtime','Overtime for month-end inventory count',285.00,'2026-09-05','Approved'),
('CLM202609040023','Rosario Luz Santiago','emp-016','Transportation','Candidate interview travel to Makati office',380.00,'2026-09-04','Paid'),
('CLM202609030024','Ferdinand Noel Castillo','emp-017','Office Supplies','Printed collateral for brand shoot',780.00,'2026-09-03','Pending'),
('CLM202609020025','Ronaldo Mark Lim','emp-019','Transportation','Fuel card reconciliation trip to Las Pinas depot',610.00,'2026-09-02','Approved'),
('CLM202609010026','Grace Faith Uy','emp-020','Meal Allowance','Quarterly budget review lunch',420.00,'2026-09-01','Paid'),
('CLM202608280027','Juan Carlos Dela Cruz','emp-001','Office Supplies','Mechanical keyboard and monitor stand',1900.00,'2026-08-28','Rejected'),
('CLM202608260028','Anna Marie Garcia','emp-004','Meal Allowance','Content workshop refreshments',460.00,'2026-08-26','Paid'),
('CLM202608220029','Cristina Grace Lopez','emp-008','Training','Data Privacy Act seminar registration',900.00,'2026-08-22','Approved'),
('CLM202608200030','Patricia Anne Cruz','emp-012','Medical','ENT follow-up consultation',700.00,'2026-08-20','Paid'),
('CLM202608180031','Maricel Joy Diaz','emp-018','Transportation','Team offsite travel in Bonifacio Global City',520.00,'2026-08-18','Pending'),
('CLM202608140032','Miguel Antonio Fernandez','emp-005','Training','Warehouse automation seminar registration',4200.00,'2026-08-14','Paid'),
('CLM202607100033','Maria Isabel Santos','emp-002','Office Supplies','Employee handbook reprint',640.00,'2026-07-10','Paid'),
('CLM202607060034','Jose Miguel Reyes','emp-003','Medical','Annual executive physical',2400.00,'2026-07-06','Paid'),
('CLM202606120035','Diana Rose Mendoza','emp-010','Training','Payroll controls forum registration',900.00,'2026-06-12','Paid'),
('CLM202606050036','Ronaldo Mark Lim','emp-019','Transportation','Fleet audit travel to Batangas',780.00,'2026-06-05','Paid');

-- ── COMPETENCY ASSESSMENTS ──
INSERT INTO public.competency_assessments (employee_id, assessment_date, competency_name, allowance_amount) VALUES
('emp-001','2026-03-05','Full-Stack Architecture & Cloud Deployment',2000.00),('emp-001','2026-06-10','Database Performance Tuning & PHP 8 Modernisation',1500.00),
('emp-002','2026-03-05','Strategic Talent Acquisition Level 3',1800.00),('emp-002','2026-06-10','Employee Relations & Conflict Mediation',1200.00),
('emp-003','2026-03-05','Financial Reporting & Statutory Audit',1500.00),('emp-003','2026-06-10','Advanced Microsoft Excel Modelling',1000.00),
('emp-004','2026-03-06','Integrated Campaign Planning & Analytics',1200.00),('emp-004','2026-06-11','Content Strategy & Copywriting',900.00),
('emp-005','2026-03-06','Supply Chain Management & Fleet Optimisation',2500.00),('emp-005','2026-06-11','Lean Operations & Process Re-engineering',2000.00),
('emp-006','2026-03-06','Automated Test Engineering (Playwright & API)',1400.00),('emp-006','2026-06-11','Regression Suite Architecture',1000.00),
('emp-007','2026-03-07','Warehouse Operations & Cold Chain Control',1500.00),('emp-007','2026-06-12','Shift Leadership & Incident Command',1200.00),
('emp-008','2026-03-07','Compensation & Benefits Administration',1000.00),('emp-008','2026-06-12','Company Records Management & Data Privacy',800.00),
('emp-009','2026-03-07','Enterprise Systems Analysis & Process Mapping',1600.00),('emp-009','2026-06-12','Infrastructure & Access Administration',1200.00),
('emp-010','2026-03-08','Treasury Operations & Cash Flow Forecasting',1800.00),('emp-010','2026-06-13','Statutory Remittance Compliance (SSS/PhilHealth/Pag-IBIG)',1400.00),
('emp-011','2026-03-08','Route Planning & Dispatch Scheduling',1100.00),('emp-011','2026-06-13','Dangerous Goods Handling (DOTR Accredited)',1000.00),
('emp-012','2026-03-08','Paid Media & Search Engine Optimisation',1000.00),
('emp-013','2026-03-09','Budget Governance & Cost Control',2200.00),('emp-013','2026-06-14','Financial Risk Management',1800.00),
('emp-014','2026-03-09','Front-End Accessibility & Responsive Design',1200.00),('emp-014','2026-06-14','Secure Coding Practices (OWASP Top 10)',1300.00),
('emp-015','2026-03-09','Inventory Accuracy & Cycle Counting',1100.00),('emp-015','2026-06-14','Forklift & Materials Handling Operation',1000.00),
('emp-016','2026-03-10','End-to-End Recruitment Pipeline Management',1200.00),('emp-016','2026-06-15','Employer Branding & Candidate Experience',900.00),
('emp-017','2026-03-10','Brand Strategy & Market Positioning',1800.00),('emp-017','2026-06-15','Crisis Communication & Stakeholder Management',1400.00),
('emp-018','2026-03-10','Software Testing Fundamentals & Version Control',800.00),
('emp-019','2026-03-11','Fleet Utilisation & Fuel Cost Analytics',1600.00),('emp-019','2026-06-15','Preventive Maintenance Programme Design',1300.00),
('emp-020','2026-03-11','Budget Variance Analysis & Reporting',1300.00),('emp-020','2026-06-16','Management Accounting & Costing',1100.00);

-- ── TRAINING RECORDS ──
INSERT INTO public.training_records (employee_id, completed_date, training_name, incentive_amount) VALUES
('emp-001','2026-02-10','Secure Coding & Cloud Database Administration',1500.00),('emp-001','2026-04-18','DevOps Pipeline & CI/CD Automation Bootcamp',1800.00),('emp-001','2026-07-22','MySQL Query Optimisation Masterclass',1200.00),
('emp-002','2026-02-12','Philippine Labor Law Compliance & DOLE Regulations',1200.00),('emp-002','2026-05-09','Performance Management Systems Workshop',1500.00),
('emp-003','2026-02-11','TRAIN Law & Corporate Tax Optimisation',1000.00),('emp-003','2026-06-05','BIR Annual Income Tax & Withholding Refresher',1200.00),
('emp-004','2026-03-14','Data-Driven Marketing Analytics with GA4',1000.00),('emp-004','2026-07-04','Brand Storytelling & Visual Content Studio',800.00),
('emp-005','2026-02-08','Logistics Fleet Telematics & Safety Systems',2000.00),('emp-005','2026-04-25','Lean Six Sigma Green Belt Certification',2500.00),('emp-005','2026-08-08','Warehouse Automation Roadmap Seminar',1500.00),
('emp-006','2026-02-14','Agile Product Delivery & API Integrations',1000.00),('emp-006','2026-05-23','Playwright End-to-End Test Automation',1200.00),
('emp-007','2026-02-09','Hazardous Materials & Cold Chain Handling',1200.00),('emp-007','2026-06-20','Occupational Safety and Health (BOSH) Certification',1500.00),
('emp-008','2026-03-20','Employee Records Digitisation Workshop',800.00),('emp-008','2026-08-01','Data Privacy Act Essentials for HR Teams',1000.00),
('emp-009','2026-04-04','Cloud Infrastructure & Disaster Recovery Planning',1500.00),('emp-009','2026-07-11','ITIL 4 Foundation Certification',1800.00),
('emp-010','2026-03-27','Cash Management & Banking Relations Forum',1400.00),('emp-010','2026-06-27','Payroll System Controls & Audit Readiness',1200.00),
('emp-011','2026-04-11','Defensive & Eco Driving Certification',1000.00),('emp-011','2026-08-06','Dispatch Coordination with Fleet Telematics',1100.00),
('emp-012','2026-05-16','Meta & Google Ads Performance Certification',1000.00),
('emp-013','2026-03-05','IFRS for SMEs Update Seminar',1800.00),('emp-013','2026-07-18','Leadership for Finance Professionals',2000.00),
('emp-014','2026-04-30','Laravel Application Development Intensive',1200.00),
('emp-015','2026-05-30','Warehouse Management System (WMS) Operations',1100.00),
('emp-016','2026-04-18','Behavioural Interviewing Techniques',1000.00),
('emp-017','2026-06-06','Market Research & Consumer Insights Summit',1600.00),
('emp-018','2026-05-02','PHP Fundamentals Bootcamp (Internal L&D)',900.00),('emp-018','2026-08-15','Git Workflow & Code Review Best Practices',1000.00),
('emp-019','2026-03-13','Fleet Preventive Maintenance Programme',1400.00),('emp-019','2026-07-25','Fuel Card Audit & Cost Control Training',1200.00),
('emp-020','2026-04-09','Advanced Excel & Power BI for Budget Analysts',1200.00),('emp-020','2026-08-22','Government Reporting & Auditing Standards',1300.00);

-- ── RECOGNITION AWARDS ──
INSERT INTO public.recognition_awards (employee_id, award_date, award_name, bonus_amount) VALUES
('emp-001','2026-02-20','Quarterly Excellence in Engineering',2500.00),('emp-001','2026-06-30','Zero-Downtime Release Champion',3000.00),
('emp-002','2026-02-20','Employee Engagement Champion',2000.00),('emp-002','2026-08-20','HR Values Award - Fairness & Integrity',2500.00),
('emp-003','2026-05-15','Accuracy Star - Statutory Remittances',1500.00),
('emp-004','2026-07-15','Campaign Impact Award (Q2 Launch)',2000.00),
('emp-005','2026-02-20','Operations Star of the Month',3000.00),('emp-005','2026-06-30','Cost Savings Hero - Fleet Re-routing Initiative',5000.00),
('emp-006','2026-04-20','Quality Guardian Award',1800.00),
('emp-007','2026-02-20','Zero Incident Safety Citation',1500.00),('emp-007','2026-09-05','Team Leadership Excellence Award',2200.00),
('emp-008','2026-03-31','Service Excellence - Records Digitisation Drive',1200.00),
('emp-009','2026-05-15','Innovation Spotlight Award',2000.00),
('emp-010','2026-06-30','Cash Flow Stewardship Award',2500.00),
('emp-011','2026-08-20','On-Time Delivery Champion',1500.00),
('emp-012','2026-04-20','Digital Reach Milestone Award',1200.00),
('emp-013','2026-07-15','Finance Leadership Award - Audit Ready Quarter',3500.00),
('emp-014','2026-09-10','Rising Star Developer Award',1500.00),
('emp-015','2026-03-31','Inventory Accuracy Champion',1400.00),
('emp-016','2026-06-30','Talent Acquisition Star',1800.00),
('emp-017','2026-08-20','Brand Ambassador of the Year',3000.00),
('emp-018','2026-09-10','Fastest Learner - Graduate Developer Track',1000.00),
('emp-019','2026-05-15','Fleet Uptime Excellence Award',2000.00),
('emp-020','2026-07-15','Budget Analyst of the Quarter',1600.00);

-- ── PERFORMANCE RATINGS (rating is VARCHAR in Postgres) ──
INSERT INTO public.performance_ratings (employee_id, review_date, rating, bonus_amount) VALUES
('emp-001','2026-06-30','4.50',5000.00),('emp-002','2026-06-30','4.80',6000.00),
('emp-003','2026-06-30','4.20',4000.00),('emp-005','2026-06-30','4.90',7000.00),
('emp-007','2026-06-30','4.30',4500.00),('emp-009','2026-06-30','4.10',3500.00),
('emp-010','2026-06-30','4.60',5500.00),('emp-013','2026-06-30','4.70',8000.00),
('emp-017','2026-06-30','4.40',5000.00),('emp-019','2026-06-30','4.50',6000.00);

-- ── ATTENDANCE LOGS (total_hours -> actual_hours; P/H/A/OT) ──
INSERT INTO public.attendance_logs (employee_id, log_date, status, actual_hours, ot_hours) VALUES
('emp-001','2026-09-01','P',8.00,0),('emp-002','2026-09-01','P',8.00,0),('emp-003','2026-09-01','P',8.00,0),('emp-005','2026-09-01','P',8.00,0),('emp-007','2026-09-01','P',10.00,2.0),('emp-008','2026-09-01','P',8.00,0),('emp-009','2026-09-01','P',8.00,0),('emp-010','2026-09-01','P',8.00,0),('emp-011','2026-09-01','P',8.00,0),('emp-012','2026-09-01','P',8.00,0),('emp-013','2026-09-01','P',8.00,0),('emp-014','2026-09-01','P',8.00,0),('emp-015','2026-09-01','P',8.00,0),('emp-016','2026-09-01','P',8.00,0),('emp-017','2026-09-01','P',8.00,0),('emp-018','2026-09-01','P',8.00,0),('emp-019','2026-09-01','P',11.50,2.5),('emp-020','2026-09-01','P',8.00,0),
('emp-001','2026-09-02','P',8.00,0),('emp-002','2026-09-02','A',0,0),('emp-003','2026-09-02','P',8.00,0),('emp-005','2026-09-02','P',10.00,2.0),('emp-007','2026-09-02','P',8.00,0),('emp-009','2026-09-02','P',8.00,0),('emp-013','2026-09-02','P',8.00,0),('emp-019','2026-09-02','P',8.00,0),
('emp-001','2026-09-15','P',8.00,0),('emp-002','2026-09-15','P',8.00,0),('emp-003','2026-09-15','P',8.00,0),('emp-004','2026-09-15','A',0,0),('emp-005','2026-09-15','P',8.00,0),('emp-007','2026-09-15','P',8.00,0),('emp-008','2026-09-15','P',8.00,0),('emp-009','2026-09-15','P',8.00,0),('emp-010','2026-09-15','P',8.00,0),('emp-011','2026-09-15','P',8.00,0),('emp-012','2026-09-15','P',8.00,0),('emp-013','2026-09-15','P',8.00,0),('emp-014','2026-09-15','P',8.00,0),('emp-015','2026-09-15','P',8.00,0),('emp-016','2026-09-15','P',8.00,0),('emp-017','2026-09-15','P',8.00,0),('emp-018','2026-09-15','P',8.00,0),('emp-019','2026-09-15','P',8.00,0),('emp-020','2026-09-15','P',8.00,0),
('emp-001','2026-09-16','P',8.00,0),('emp-002','2026-09-16','P',8.00,0),('emp-003','2026-09-16','P',8.00,0),('emp-004','2026-09-16','A',0,0),('emp-005','2026-09-16','OT',11.50,3.5),('emp-007','2026-09-16','P',8.00,0),('emp-008','2026-09-16','P',8.00,0),('emp-009','2026-09-16','P',8.00,0),('emp-010','2026-09-16','P',8.00,0),('emp-011','2026-09-16','P',8.00,0),('emp-012','2026-09-16','P',8.00,0),('emp-013','2026-09-16','P',8.00,0),('emp-014','2026-09-16','P',8.00,0),('emp-015','2026-09-16','P',8.00,0),('emp-016','2026-09-16','P',8.00,0),('emp-017','2026-09-16','P',8.00,0),('emp-018','2026-09-16','P',8.00,0),('emp-019','2026-09-16','P',8.00,0),('emp-020','2026-09-16','P',8.00,0),
('emp-001','2026-09-17','P',8.00,0),('emp-002','2026-09-17','P',8.00,0),('emp-003','2026-09-17','P',8.00,0),('emp-004','2026-09-17','A',0,0),('emp-005','2026-09-17','P',8.00,0),('emp-007','2026-09-17','P',8.00,0),('emp-008','2026-09-17','P',8.00,0),('emp-009','2026-09-17','P',8.00,0),('emp-010','2026-09-17','P',8.00,0),('emp-011','2026-09-17','P',8.00,0),('emp-012','2026-09-17','P',8.00,0),('emp-013','2026-09-17','P',8.00,0),('emp-014','2026-09-17','P',8.00,0),('emp-015','2026-09-17','P',8.00,0),('emp-016','2026-09-17','P',8.00,0),('emp-017','2026-09-17','P',8.00,0),('emp-018','2026-09-17','P',8.00,0),('emp-019','2026-09-17','OT',11.00,3.0),('emp-020','2026-09-17','P',8.00,0),
('emp-001','2026-09-18','OT',10.50,2.5),('emp-002','2026-09-18','P',8.00,0),('emp-003','2026-09-18','P',8.00,0),('emp-004','2026-09-18','A',0,0),('emp-005','2026-09-18','P',8.00,0),('emp-007','2026-09-18','P',8.00,0),('emp-008','2026-09-18','P',8.00,0),('emp-009','2026-09-18','P',8.00,0),('emp-010','2026-09-18','P',8.00,0),('emp-011','2026-09-18','P',8.00,0),('emp-012','2026-09-18','P',8.00,0),('emp-013','2026-09-18','P',8.00,0),('emp-014','2026-09-18','P',8.00,0),('emp-015','2026-09-18','P',8.00,0),('emp-016','2026-09-18','P',8.00,0),('emp-017','2026-09-18','P',8.00,0),('emp-018','2026-09-18','P',8.00,0),('emp-019','2026-09-18','P',8.00,0),('emp-020','2026-09-18','P',8.00,0),
('emp-001','2026-09-19','P',8.00,0),('emp-002','2026-09-19','P',8.00,0),('emp-003','2026-09-19','P',8.00,0),('emp-004','2026-09-19','A',0,0),('emp-005','2026-09-19','P',8.00,0),('emp-007','2026-09-19','P',8.00,0),('emp-008','2026-09-19','P',8.00,0),('emp-009','2026-09-19','P',8.00,0),('emp-010','2026-09-19','P',8.00,0),('emp-011','2026-09-19','P',8.00,0),('emp-012','2026-09-19','P',8.00,0),('emp-013','2026-09-19','P',8.00,0),('emp-014','2026-09-19','P',8.00,0),('emp-015','2026-09-19','P',8.00,0),('emp-016','2026-09-19','P',8.00,0),('emp-017','2026-09-19','P',8.00,0),('emp-018','2026-09-19','P',8.00,0),('emp-019','2026-09-19','P',8.00,0),('emp-020','2026-09-19','P',8.00,0),
('emp-001','2026-09-21','P',8.00,0),('emp-002','2026-09-21','P',8.00,0),('emp-003','2026-09-21','P',8.00,0),('emp-004','2026-09-21','A',0,0),('emp-005','2026-09-21','OT',11.50,3.5),('emp-007','2026-09-21','P',8.00,0),('emp-008','2026-09-21','P',8.00,0),('emp-009','2026-09-21','P',8.00,0),('emp-010','2026-09-21','H',4.00,0),('emp-011','2026-09-21','P',8.00,0),('emp-012','2026-09-21','P',8.00,0),('emp-013','2026-09-21','P',8.00,0),('emp-014','2026-09-21','P',8.00,0),('emp-015','2026-09-21','P',8.00,0),('emp-016','2026-09-21','P',8.00,0),('emp-017','2026-09-21','P',8.00,0),('emp-018','2026-09-21','P',8.00,0),('emp-019','2026-09-21','P',8.00,0),('emp-020','2026-09-21','P',8.00,0),
('emp-001','2026-09-22','OT',10.50,2.5),('emp-002','2026-09-22','P',8.00,0),('emp-003','2026-09-22','P',8.00,0),('emp-004','2026-09-22','A',0,0),('emp-005','2026-09-22','OT',11.50,3.5),('emp-007','2026-09-22','OT',10.00,2.0),('emp-008','2026-09-22','P',8.00,0),('emp-009','2026-09-22','P',8.00,0),('emp-010','2026-09-22','P',8.00,0),('emp-011','2026-09-22','P',8.00,0),('emp-012','2026-09-22','P',7.58,0),('emp-013','2026-09-22','P',8.00,0),('emp-014','2026-09-22','P',8.00,0),('emp-015','2026-09-22','A',0,0),('emp-016','2026-09-22','P',8.00,0),('emp-017','2026-09-22','P',8.00,0),('emp-018','2026-09-22','P',8.00,0),('emp-019','2026-09-22','OT',11.00,3.0),('emp-020','2026-09-22','P',8.00,0);

-- ── SHIFT SCHEDULES (shift_type -> shift_name) ──
INSERT INTO public.shift_schedules (employee_id, schedule_date, shift_name, night_diff_hours, holiday_hours) VALUES
('emp-001','2026-09-22','Night',4.00,0.00),('emp-002','2026-09-22','Day',0.00,0.00),('emp-003','2026-09-22','Day',0.00,0.00),('emp-004','2026-09-22','Day',0.00,0.00),('emp-005','2026-09-22','Mid',0.00,0.00),('emp-006','2026-09-22','Day',0.00,0.00),('emp-007','2026-09-22','Mid',0.00,0.00),('emp-008','2026-09-22','Day',0.00,0.00),('emp-009','2026-09-22','Night',4.00,0.00),('emp-010','2026-09-22','Day',0.00,0.00),('emp-011','2026-09-22','Day',0.00,0.00),('emp-012','2026-09-22','Day',0.00,0.00),('emp-013','2026-09-22','Day',0.00,0.00),('emp-014','2026-09-22','Day',0.00,0.00),('emp-015','2026-09-22','Day',0.00,0.00),('emp-016','2026-09-22','Day',0.00,0.00),('emp-017','2026-09-22','Day',0.00,0.00),('emp-018','2026-09-22','Day',0.00,0.00),('emp-019','2026-09-22','Mid',0.00,0.00),('emp-020','2026-09-22','Day',0.00,0.00);

-- ── TIMESHEETS (approved_at dropped; hours populated) ──
INSERT INTO public.timesheets (employee_id, period_start, period_end, hours, status) VALUES
('emp-001','2026-09-01','2026-09-30',176.00,'Approved'),('emp-002','2026-09-01','2026-09-30',176.00,'Approved'),('emp-003','2026-09-01','2026-09-30',176.00,'Approved'),('emp-004','2026-09-01','2026-09-30',144.00,'Approved'),('emp-005','2026-09-01','2026-09-30',176.00,'Approved'),('emp-006','2026-09-01','2026-09-30',80.00,'Approved'),('emp-007','2026-09-01','2026-09-30',176.00,'Approved'),('emp-008','2026-09-01','2026-09-30',176.00,'Approved'),('emp-009','2026-09-01','2026-09-30',176.00,'Approved'),('emp-010','2026-09-01','2026-09-30',176.00,'Approved'),('emp-011','2026-09-01','2026-09-30',176.00,'Approved'),('emp-012','2026-09-01','2026-09-30',176.00,'Submitted'),('emp-013','2026-09-01','2026-09-30',176.00,'Approved'),('emp-014','2026-09-01','2026-09-30',176.00,'Approved'),('emp-015','2026-09-01','2026-09-30',176.00,'Approved'),('emp-016','2026-09-01','2026-09-30',176.00,'Approved'),('emp-017','2026-09-01','2026-09-30',176.00,'Approved'),('emp-018','2026-09-01','2026-09-30',176.00,'Submitted'),('emp-019','2026-09-01','2026-09-30',176.00,'Approved'),('emp-020','2026-09-01','2026-09-30',176.00,'Approved');

-- ── LEAVE REQUESTS (days computed; is_paid boolean) ──
INSERT INTO public.leave_requests (employee_id, leave_type, start_date, end_date, days, is_paid, status) VALUES
('emp-004','Vacation Leave','2026-09-18','2026-09-25',8,TRUE,'Approved'),
('emp-008','Sick Leave','2026-09-10','2026-09-11',2,TRUE,'Approved'),
('emp-010','Emergency Leave','2026-09-21','2026-09-21',1,TRUE,'Approved'),
('emp-014','Vacation Leave','2026-09-28','2026-09-30',3,TRUE,'Approved');

-- ── BUDGET / CASH ──
INSERT INTO public.budget_allocations (period_start, period_end, department, allocated_amount, committed_amount) VALUES
('2026-09-01','2026-09-30','ALL',1200000.00,0.00);
INSERT INTO public.cash_positions (as_of_date, available_amount, source) VALUES
('2026-09-22',5000000.00,'Cash Management - September 2026');

-- ── PAYROLL RUNS ──
INSERT INTO public.payroll_runs (period, period_start, period_end, total_gross, total_deductions, total_net, status, pay_date, run_date, created_at) VALUES
('September 2026','2026-09-01','2026-09-30',0.00,0.00,0.00,'Draft','2026-09-30',NULL,now())
ON CONFLICT (period_start, period_end) DO NOTHING;
INSERT INTO public.payroll_runs (period, period_start, period_end, total_gross, total_deductions, total_net, status, pay_date, run_date, created_at) VALUES
('August 2026','2026-08-01','2026-08-31',1052000.00,148500.00,903500.00,'Closed','2026-08-31','2026-09-01 09:30:00','2026-08-25 08:00:00')
ON CONFLICT (period_start, period_end) DO NOTHING;

-- ── PAYROLL MASTER LIST (September 2026 line items) ──
DO $$
DECLARE v_run INT;
BEGIN
  SELECT id INTO v_run FROM public.payroll_runs WHERE period_start = '2026-09-01' AND period_end = '2026-09-30' LIMIT 1;
  IF v_run IS NULL THEN RETURN; END IF;

  INSERT INTO public.payroll_items (
    payroll_run_id, employee_id, employee_name, department, days_worked, ot_hours,
    basic_pay, overtime_pay, night_differential, holiday_pay, allowances, claims_amount,
    performance_bonus, competency_allowance, training_incentive, recognition_bonus, gross_pay,
    sss_ee, sss_er, philhealth_ee, philhealth_er, pagibig_ee, pagibig_er, withholding_tax,
    loans_deduction, unpaid_leave_deduction, hmo_deduction, total_deductions, net_pay,
    ewallet_provider, status, is_included, pay_date) VALUES
  (v_run,'emp-001','Juan Carlos Dela Cruz','IT Department',22.0,2.0,65000.00,923.30,0.00,0.00,12000.00,850.00,0.00,0.00,0.00,0.00,78773.30,900.00,1900.00,1300.00,1300.00,100.00,100.00,10993.33,3500.00,0.00,1900.00,18693.33,60079.97,'GCash','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-002','Maria Isabel Santos','HR Department',22.0,0.0,72000.00,0.00,0.00,0.00,22000.00,0.00,3000.00,0.00,0.00,0.00,97000.00,900.00,1900.00,1440.00,1440.00,100.00,100.00,15515.00,10000.00,0.00,1300.00,29255.00,67745.00,'Maya','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-003','Jose Miguel Reyes','Finance Department',22.0,0.0,58000.00,0.00,0.00,0.00,6500.00,1200.00,0.00,0.00,0.00,0.00,65700.00,900.00,1900.00,1160.00,1160.00,100.00,100.00,7916.33,3000.00,0.00,300.00,13376.33,52323.67,'GCash','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-004','Anna Marie Garcia','Marketing',18.0,0.0,42545.45,0.00,0.00,0.00,8000.00,0.00,0.00,0.00,0.00,0.00,50545.45,900.00,1900.00,1040.00,1040.00,100.00,100.00,4909.42,0.00,9454.55,250.00,16653.97,33891.48,'Bank','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-005','Miguel Antonio Fernandez','Operations',22.0,4.0,78000.00,2215.91,0.00,0.00,24000.00,0.00,3000.00,0.00,0.00,0.00,107215.91,900.00,1900.00,1560.00,1560.00,100.00,100.00,18038.98,18000.00,0.00,300.00,38898.98,68316.93,'Bank','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-006','Liza Ann Tan','IT Department',10.0,0.0,21818.18,0.00,0.00,0.00,6000.00,0.00,0.00,0.00,0.00,0.00,27818.18,900.00,1900.00,960.00,960.00,100.00,100.00,753.73,0.00,0.00,250.00,2963.73,24854.45,'GCash','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-007','Ramon Luis Villanueva','Operations',22.0,2.0,62000.00,880.68,0.00,0.00,10000.00,0.00,0.00,0.00,0.00,0.00,72880.68,900.00,1900.00,1240.00,1240.00,100.00,100.00,9535.17,2750.00,0.00,300.00,14825.17,58055.51,'Maya','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-008','Cristina Grace Lopez','HR Department',22.0,0.0,45000.00,0.00,0.00,0.00,5800.00,0.00,0.00,0.00,0.00,0.00,50800.00,900.00,1900.00,900.00,900.00,100.00,100.00,4988.33,0.00,0.00,300.00,7188.33,43611.67,'Cash','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-009','Roberto Jose Aquino','IT Department',22.0,0.0,55000.00,0.00,0.00,0.00,3500.00,0.00,0.00,0.00,0.00,0.00,58500.00,900.00,1900.00,1100.00,1100.00,100.00,100.00,6488.33,1000.00,0.00,300.00,9888.33,48611.67,'GCash','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-010','Diana Rose Mendoza','Finance Department',22.0,0.0,56000.00,0.00,0.00,0.00,3500.00,0.00,0.00,0.00,0.00,0.00,59500.00,900.00,1900.00,1120.00,1120.00,100.00,100.00,6684.33,0.00,0.00,300.00,9104.33,50395.67,'Maya','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-011','Carlos Eduardo Torres','Operations',22.0,0.0,47000.00,0.00,0.00,0.00,3500.00,0.00,0.00,0.00,0.00,0.00,50500.00,900.00,1900.00,940.00,940.00,100.00,100.00,4920.33,0.00,0.00,300.00,7160.33,43339.67,'Bank','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-012','Patricia Anne Cruz','Marketing',22.0,0.0,38000.00,0.00,0.00,0.00,2800.00,0.00,0.00,0.00,0.00,0.00,40800.00,900.00,1900.00,760.00,760.00,100.00,100.00,3016.33,0.00,0.00,250.00,5026.33,35773.67,'GCash','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-013','Eduardo Victor Ramos','Finance Department',22.0,0.0,85000.00,0.00,0.00,0.00,13500.00,0.00,5000.00,0.00,0.00,0.00,103500.00,900.00,1900.00,1700.00,1700.00,100.00,100.00,17075.00,4000.00,0.00,250.00,24025.00,79475.00,'Bank','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-014','Josephine Clara Bautista','IT Department',22.0,0.0,52000.00,0.00,0.00,0.00,3500.00,0.00,0.00,0.00,0.00,0.00,55500.00,900.00,1900.00,1040.00,1040.00,100.00,100.00,5900.33,0.00,0.00,300.00,8240.33,47259.67,'Maya','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-015','Andres Pablo Navarro','Operations',22.0,0.0,55000.00,0.00,0.00,0.00,4500.00,0.00,0.00,0.00,0.00,0.00,59500.00,900.00,1900.00,1100.00,1100.00,100.00,100.00,6688.33,1500.00,0.00,300.00,10588.33,48911.67,'Cash','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-016','Rosario Luz Santiago','HR Department',22.0,0.0,48000.00,0.00,0.00,0.00,3000.00,0.00,0.00,0.00,0.00,0.00,51000.00,900.00,1900.00,960.00,960.00,100.00,100.00,5016.33,0.00,0.00,300.00,7276.33,43723.67,'GCash','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-017','Ferdinand Noel Castillo','Marketing',22.0,0.0,68000.00,0.00,0.00,0.00,6000.00,0.00,0.00,0.00,0.00,0.00,74000.00,900.00,1900.00,1360.00,1360.00,100.00,100.00,9785.00,2000.00,0.00,250.00,14395.00,59605.00,'Bank','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-018','Maricel Joy Diaz','IT Department',22.0,0.0,35000.00,0.00,0.00,0.00,2700.00,0.00,0.00,0.00,0.00,0.00,37700.00,900.00,1900.00,700.00,700.00,100.00,100.00,2408.33,0.00,0.00,300.00,4408.33,33291.67,'GCash','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-019','Ronaldo Mark Lim','Operations',22.0,3.5,72000.00,1789.77,0.00,0.00,10500.00,0.00,0.00,0.00,0.00,0.00,84289.77,900.00,1900.00,1440.00,1440.00,100.00,100.00,12337.44,3000.00,0.00,250.00,18027.44,66262.33,'Maya','Draft',TRUE,'2026-09-30'),
  (v_run,'emp-020','Grace Faith Uy','Finance Department',22.0,0.0,50000.00,0.00,0.00,0.00,3500.00,0.00,0.00,0.00,0.00,0.00,53500.00,900.00,1900.00,1000.00,1000.00,100.00,100.00,5508.33,0.00,0.00,300.00,7808.33,45691.67,'GCash','Draft',TRUE,'2026-09-30')
  ON CONFLICT (payroll_run_id, employee_id) DO NOTHING;

  -- Recompute the run totals from the included lines.
  UPDATE public.payroll_runs
     SET total_gross      = COALESCE((SELECT SUM(gross_pay)       FROM public.payroll_items WHERE payroll_run_id = v_run AND is_included), 0),
         total_deductions = COALESCE((SELECT SUM(total_deductions) FROM public.payroll_items WHERE payroll_run_id = v_run AND is_included), 0),
         total_net        = COALESCE((SELECT SUM(net_pay)         FROM public.payroll_items WHERE payroll_run_id = v_run AND is_included), 0)
   WHERE id = v_run;
END $$;

-- ── NOTIFICATIONS (title = legacy type; message = text) ──
INSERT INTO public.notifications (user_name, title, message, is_read, created_at) VALUES
  ('System','Payroll','Payroll for February 2026 processed',FALSE,'2026-02-15 09:00:00'),
  ('System','Employee','New employee added: Cristina Lopez',FALSE,'2026-02-14 14:30:00'),
  ('System','Compensation','Salary updated for Juan Dela Cruz',TRUE,'2026-02-13 10:00:00'),
  ('System','Claims','Claim CLM202601200002 approved',TRUE,'2026-01-21 11:00:00'),
  ('System','Benefits','Benefits enrollment updated',TRUE,'2026-01-05 08:30:00');

-- ── END OF SAMPLE DATA ──


-- ── THIRTEENTH MONTH PAY (2026) ──
INSERT INTO public.thirteenth_month (employee_id, employee_name, department, monthly_basic, months_worked, computed_amount, year, status) VALUES
('emp-001','Juan Dela Cruz','IT Department',65000.00,12,65000.00,2026,'Approved'),
('emp-002','Maria Santos','HR Department',72000.00,8,48000.00,2026,'Approved'),
('emp-003','Jose Reyes','Finance Department',58000.00,10,48333.33,2026,'Approved'),
('emp-004','Anna Garcia','Marketing',52000.00,12,52000.00,2026,'Pending'),
('emp-005','Miguel Fernandez','Operations',78000.00,6,39000.00,2026,'Paid'),
('emp-006','Liza Tan','IT Department',48000.00,11,44000.00,2026,'Approved'),
('emp-007','Ramon Villanueva','Operations',62000.00,12,62000.00,2026,'Approved'),
('emp-008','Cristina Lopez','HR Department',45000.00,9,33750.00,2026,'Pending')
ON CONFLICT (employee_id, year) DO NOTHING;
