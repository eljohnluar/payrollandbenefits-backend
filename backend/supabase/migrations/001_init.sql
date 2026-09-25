-- =====================================================
-- PAYROLL & BENEFITS — Supabase / PostgreSQL schema
-- Translated from the legacy MySQL schema.sql
--
-- Conversions applied:
--   INT AUTO_INCREMENT      -> INT GENERATED ALWAYS AS IDENTITY
--   TINYINT(1)              -> BOOLEAN
--   DATETIME                -> TIMESTAMPTZ
--   ENUM(...)               -> TEXT with CHECK constraint
--   ON UPDATE CURRENT_TIMESTAMP -> trigger (set_updated_at)
--   ENGINE=InnoDB           -> removed
--   DECIMAL                 -> NUMERIC (alias, kept for readability)
--
-- Employee ids stay VARCHAR(20) ('emp-001') because the legacy dataset and
-- payslip references are keyed on them. New rows may use UUID text.
-- =====================================================

CREATE SCHEMA IF NOT EXISTS public;

CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ── Shared trigger: replaces MySQL's "ON UPDATE CURRENT_TIMESTAMP" ──
CREATE OR REPLACE FUNCTION public.touch_updated_at() RETURNS trigger AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =====================================================
-- APP USERS (HR / Admin / Finance / Payroll logins)
-- Kept separate from auth.users: PHP validates a Supabase JWT and then
-- resolves the matching app user here for role-based authorisation.
-- =====================================================
CREATE TABLE IF NOT EXISTS public.app_users (
  id          INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email       VARCHAR(255) NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  name        VARCHAR(255) NOT NULL,
  role        TEXT NOT NULL DEFAULT 'HR'
              CHECK (role IN ('Admin','HR','Finance','Payroll','Employee')),
  initials    VARCHAR(5)   NOT NULL DEFAULT '',
  is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
  auth_uid    UUID         UNIQUE,
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
DO $$ BEGIN
  CREATE TRIGGER trg_app_users_touch BEFORE UPDATE ON public.app_users
    FOR EACH ROW EXECUTE FUNCTION public.touch_updated_at();
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- =====================================================
-- DEPARTMENTS
-- =====================================================
CREATE TABLE IF NOT EXISTS public.departments (
  id   INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
);

-- =====================================================
-- EMPLOYEES
-- =====================================================
CREATE TABLE IF NOT EXISTS public.employees (
  id               VARCHAR(20)  PRIMARY KEY,
  code             VARCHAR(30)  NOT NULL UNIQUE,
  first_name       VARCHAR(100) NOT NULL,
  middle_name      VARCHAR(100),
  last_name        VARCHAR(100) NOT NULL,
  suffix           VARCHAR(20),
  email            VARCHAR(255),
  mobile           VARCHAR(20),
  birth_date       DATE,
  gender           TEXT CHECK (gender IN ('Male','Female','Other')),
  department       VARCHAR(100) NOT NULL,
  position         VARCHAR(100) NOT NULL,
  employment_type  TEXT NOT NULL DEFAULT 'Regular'
                   CHECK (employment_type IN ('Regular','Probationary','Contractual')),
  hire_date        DATE NOT NULL,
  basic_salary     NUMERIC(12,2) NOT NULL DEFAULT 0,
  status           TEXT NOT NULL DEFAULT 'Active'
                   CHECK (status IN ('Active','On Leave','Resigned','Terminated')),
  sss              VARCHAR(30),
  philhealth       VARCHAR(30),
  pagibig          VARCHAR(30),
  tin              VARCHAR(30),
  ewallet_provider VARCHAR(30),
  ewallet_account  VARCHAR(50),
  ewallet_name     VARCHAR(100),
  ewallet_primary  BOOLEAN NOT NULL DEFAULT TRUE,
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
DO $$ BEGIN
  CREATE TRIGGER trg_employees_touch BEFORE UPDATE ON public.employees
    FOR EACH ROW EXECUTE FUNCTION public.touch_updated_at();
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- =====================================================
-- SALARY HISTORY / ALLOWANCES / LOANS
-- =====================================================
CREATE TABLE IF NOT EXISTS public.salary_history (
  id             INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id    VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  basic_salary   NUMERIC(12,2) NOT NULL,
  effective_date DATE NOT NULL,
  reason         VARCHAR(255),
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.allowances (
  id          INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  type        VARCHAR(50) NOT NULL,
  amount      NUMERIC(12,2) NOT NULL DEFAULT 0,
  frequency   VARCHAR(20) NOT NULL DEFAULT 'Monthly',
  description VARCHAR(255),
  start_date  DATE,
  end_date    DATE,
  is_active   BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS public.loans (
  id                INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id       VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  loan_type         VARCHAR(50) NOT NULL,
  principal         NUMERIC(12,2) NOT NULL DEFAULT 0,
  monthly_deduction NUMERIC(12,2) NOT NULL DEFAULT 0,
  balance           NUMERIC(12,2) NOT NULL DEFAULT 0,
  status            TEXT NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Paid Off','Cancelled')),
  approved_at       DATE
);

-- =====================================================
-- CLAIMS
-- =====================================================
CREATE TABLE IF NOT EXISTS public.claim_categories (
  id         VARCHAR(30) PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  max_amount NUMERIC(12,2) NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS public.claims (
  id            INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  claim_number  VARCHAR(30) NOT NULL UNIQUE,
  employee_name VARCHAR(200) NOT NULL,
  employee_id   VARCHAR(20) REFERENCES public.employees(id) ON DELETE SET NULL,
  category      VARCHAR(100) NOT NULL,
  description   TEXT,
  amount        NUMERIC(12,2) NOT NULL DEFAULT 0,
  claim_date    DATE NOT NULL,
  status        TEXT NOT NULL DEFAULT 'Pending'
                CHECK (status IN ('Pending','Approved','Rejected','Paid')),
  receipt_file  VARCHAR(255),
  receipt_mime  VARCHAR(100),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_claim_employee_date ON public.claims (employee_id, claim_date);
DO $$ BEGIN
  CREATE TRIGGER trg_claims_touch BEFORE UPDATE ON public.claims
    FOR EACH ROW EXECUTE FUNCTION public.touch_updated_at();
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- =====================================================
-- BENEFIT PLANS & ENROLLMENTS
-- =====================================================
CREATE TABLE IF NOT EXISTS public.benefit_plans (
  id              INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  plan_code       VARCHAR(30) NOT NULL UNIQUE,
  plan_name       VARCHAR(200) NOT NULL,
  plan_type       VARCHAR(50) NOT NULL,
  provider        VARCHAR(100) NOT NULL,
  monthly_premium NUMERIC(12,2) NOT NULL DEFAULT 0,
  employer_share  INT NOT NULL DEFAULT 0,
  employee_share  INT NOT NULL DEFAULT 0,
  description     TEXT,
  is_active       BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS public.benefit_enrollments (
  id              INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id     VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  employee_name   VARCHAR(200) NOT NULL,
  plan_id         INT NOT NULL REFERENCES public.benefit_plans(id) ON DELETE CASCADE,
  plan_name       VARCHAR(200) NOT NULL,
  provider        VARCHAR(100) NOT NULL,
  monthly_premium NUMERIC(12,2) NOT NULL DEFAULT 0,
  employer_share  INT NOT NULL DEFAULT 0,
  employee_share  INT NOT NULL DEFAULT 0,
  dependents      INT NOT NULL DEFAULT 0,
  effective_date  DATE NOT NULL,
  status          TEXT NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Pending','Cancelled')),
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =====================================================
-- PAYROLL RUNS & ITEMS
-- =====================================================
CREATE TABLE IF NOT EXISTS public.payroll_runs (
  id               INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  period           VARCHAR(50) NOT NULL,
  period_start     DATE NOT NULL,
  period_end       DATE NOT NULL,
  total_gross      NUMERIC(14,2) NOT NULL DEFAULT 0,
  total_deductions NUMERIC(14,2) NOT NULL DEFAULT 0,
  total_net        NUMERIC(14,2) NOT NULL DEFAULT 0,
  status           TEXT NOT NULL DEFAULT 'Draft'
                   CHECK (status IN ('Draft','Processing','Pending Finance Approval',
                                     'On Hold','Approved','Rejected','Paid','Closed')),
  pay_date         DATE,
  run_date         TIMESTAMPTZ,
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (period_start, period_end)
);

CREATE TABLE IF NOT EXISTS public.payroll_items (
  id                   INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  payroll_run_id       INT NOT NULL REFERENCES public.payroll_runs(id) ON DELETE CASCADE,
  employee_id          VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  employee_name        VARCHAR(200) NOT NULL,
  department           VARCHAR(100),
  days_worked          NUMERIC(4,1) NOT NULL DEFAULT 22,
  ot_hours             NUMERIC(6,1) NOT NULL DEFAULT 0,
  basic_pay            NUMERIC(12,2) NOT NULL DEFAULT 0,
  overtime_pay         NUMERIC(12,2) NOT NULL DEFAULT 0,
  night_differential   NUMERIC(12,2) NOT NULL DEFAULT 0,
  holiday_pay          NUMERIC(12,2) NOT NULL DEFAULT 0,
  allowances           NUMERIC(12,2) NOT NULL DEFAULT 0,
  claims_amount        NUMERIC(12,2) NOT NULL DEFAULT 0,
  leave_conversion     NUMERIC(12,2) NOT NULL DEFAULT 0,
  performance_bonus    NUMERIC(12,2) NOT NULL DEFAULT 0,
  competency_allowance NUMERIC(12,2) NOT NULL DEFAULT 0,
  training_incentive   NUMERIC(12,2) NOT NULL DEFAULT 0,
  recognition_bonus    NUMERIC(12,2) NOT NULL DEFAULT 0,
  gross_pay            NUMERIC(12,2) NOT NULL DEFAULT 0,
  sss_ee               NUMERIC(10,2) NOT NULL DEFAULT 0,
  sss_er               NUMERIC(10,2) NOT NULL DEFAULT 0,
  philhealth_ee        NUMERIC(10,2) NOT NULL DEFAULT 0,
  philhealth_er        NUMERIC(10,2) NOT NULL DEFAULT 0,
  pagibig_ee           NUMERIC(10,2) NOT NULL DEFAULT 0,
  pagibig_er           NUMERIC(10,2) NOT NULL DEFAULT 0,
  withholding_tax      NUMERIC(10,2) NOT NULL DEFAULT 0,
  loans_deduction      NUMERIC(10,2) NOT NULL DEFAULT 0,
  unpaid_leave_deduction NUMERIC(12,2) NOT NULL DEFAULT 0,
  hmo_deduction        NUMERIC(12,2) NOT NULL DEFAULT 0,
  total_deductions     NUMERIC(12,2) NOT NULL DEFAULT 0,
  net_pay              NUMERIC(12,2) NOT NULL DEFAULT 0,
  ewallet_provider     VARCHAR(30),
  status               TEXT NOT NULL DEFAULT 'Draft'
                       CHECK (status IN ('Draft','Pending Finance Approval','On Hold',
                                         'Approved','Rejected','Paid')),
  is_included          BOOLEAN NOT NULL DEFAULT TRUE,
  payslip_object_key   VARCHAR(255),
  pay_date             DATE,
  UNIQUE (payroll_run_id, employee_id)
);
CREATE INDEX IF NOT EXISTS idx_payroll_items_run ON public.payroll_items (payroll_run_id);

-- =====================================================
-- FINANCE APPROVAL WORKFLOW
-- =====================================================
CREATE TABLE IF NOT EXISTS public.finance_approvals (
  id             INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  payroll_run_id INT NOT NULL UNIQUE REFERENCES public.payroll_runs(id) ON DELETE CASCADE,
  status         TEXT NOT NULL DEFAULT 'Pending'
                 CHECK (status IN ('Pending','Approved','Rejected','On Hold')),
  submitted_by   INT REFERENCES public.app_users(id) ON DELETE SET NULL,
  submitted_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  reviewed_by    INT REFERENCES public.app_users(id) ON DELETE SET NULL,
  reviewed_at    TIMESTAMPTZ,
  decision_notes TEXT
);

CREATE TABLE IF NOT EXISTS public.disbursement_records (
  id             INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  payroll_run_id INT NOT NULL REFERENCES public.payroll_runs(id) ON DELETE CASCADE,
  employee_id    VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  amount         NUMERIC(12,2) NOT NULL DEFAULT 0,
  provider       VARCHAR(30),
  reference_no   VARCHAR(100),
  status         TEXT NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending','Disbursed','Failed')),
  disbursed_at   TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS public.general_ledger_entries (
  id            INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  payroll_run_id INT REFERENCES public.payroll_runs(id) ON DELETE SET NULL,
  entry_date    DATE NOT NULL,
  account_code  VARCHAR(50) NOT NULL,
  account_name  VARCHAR(150) NOT NULL,
  debit         NUMERIC(14,2) NOT NULL DEFAULT 0,
  credit        NUMERIC(14,2) NOT NULL DEFAULT 0,
  memo          VARCHAR(255),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.budget_allocations (
  id               INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  period_start     DATE NOT NULL,
  period_end       DATE NOT NULL,
  department       VARCHAR(100) NOT NULL,
  allocated_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  committed_amount NUMERIC(14,2) NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS public.cash_positions (
  id               INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  as_of_date       DATE NOT NULL,
  available_amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  source           VARCHAR(255)
);

-- =====================================================
-- PROFILE SECTIONS
-- =====================================================
CREATE TABLE IF NOT EXISTS public.competency_assessments (
  id               INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id      VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  assessment_date  DATE NOT NULL,
  competency_name  VARCHAR(150) NOT NULL,
  allowance_amount NUMERIC(12,2) NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS public.training_records (
  id               INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id      VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  completed_date   DATE NOT NULL,
  training_name    VARCHAR(200) NOT NULL,
  incentive_amount NUMERIC(12,2) NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS public.recognition_awards (
  id            INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id   VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  award_date    DATE NOT NULL,
  award_name    VARCHAR(200) NOT NULL,
  bonus_amount  NUMERIC(12,2) NOT NULL DEFAULT 0
);

-- =====================================================
-- LEAVE
-- =====================================================
CREATE TABLE IF NOT EXISTS public.leave_balances (
  id          INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id VARCHAR(20) REFERENCES public.employees(id) ON DELETE CASCADE,
  leave_type  VARCHAR(50) NOT NULL,
  accrued     NUMERIC(6,1) NOT NULL DEFAULT 0,
  used        NUMERIC(6,1) NOT NULL DEFAULT 0,
  balance     NUMERIC(6,1) NOT NULL DEFAULT 0,
  year        INT NOT NULL
);

CREATE TABLE IF NOT EXISTS public.leave_requests (
  id          INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  leave_type  VARCHAR(50) NOT NULL,
  start_date  DATE NOT NULL,
  end_date    DATE NOT NULL,
  days        NUMERIC(6,1) NOT NULL DEFAULT 0,
  is_paid     BOOLEAN NOT NULL DEFAULT TRUE,
  status      TEXT NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending','Approved','Rejected')),
  filed_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.leave_conversions (
  id          INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id VARCHAR(20) REFERENCES public.employees(id) ON DELETE CASCADE,
  leave_type  VARCHAR(50) NOT NULL,
  days        NUMERIC(6,1) NOT NULL DEFAULT 0,
  amount      NUMERIC(12,2) NOT NULL DEFAULT 0,
  conv_date   DATE NOT NULL,
  status      TEXT NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending','Approved','Completed','Rejected'))
);

CREATE TABLE IF NOT EXISTS public.performance_ratings (
  id          INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  review_date DATE NOT NULL,
  rating      VARCHAR(20),
  bonus_amount NUMERIC(12,2) NOT NULL DEFAULT 0
);

-- =====================================================
-- ATTENDANCE
-- =====================================================
CREATE TABLE IF NOT EXISTS public.attendance_logs (
  id             INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id    VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  log_date       DATE NOT NULL,
  status         TEXT NOT NULL DEFAULT 'P' CHECK (status IN ('P','H','A','OT')),
  actual_hours   NUMERIC(6,2) NOT NULL DEFAULT 0,
  ot_hours       NUMERIC(6,2) NOT NULL DEFAULT 0,
  nd_hours       NUMERIC(6,2) NOT NULL DEFAULT 0,
  holiday_hours  NUMERIC(6,2) NOT NULL DEFAULT 0,
  UNIQUE (employee_id, log_date)
);

CREATE TABLE IF NOT EXISTS public.timesheets (
  id           INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id  VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  period_start DATE NOT NULL,
  period_end   DATE NOT NULL,
  hours        NUMERIC(8,2) NOT NULL DEFAULT 0,
  status       TEXT NOT NULL DEFAULT 'Draft' CHECK (status IN ('Draft','Submitted','Approved','Rejected')),
  UNIQUE (employee_id, period_start, period_end)
);

CREATE TABLE IF NOT EXISTS public.shift_schedules (
  id               INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id      VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  schedule_date    DATE NOT NULL,
  shift_name       VARCHAR(60),
  night_diff_hours NUMERIC(5,2) NOT NULL DEFAULT 0,
  holiday_hours    NUMERIC(5,2) NOT NULL DEFAULT 0,
  UNIQUE (employee_id, schedule_date)
);

-- =====================================================
-- THIRTEENTH MONTH PAY
-- =====================================================
CREATE TABLE IF NOT EXISTS public.thirteenth_month (
  id              INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id     VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  employee_name   VARCHAR(200) NOT NULL,
  department      VARCHAR(100),
  monthly_basic   NUMERIC(12,2) NOT NULL DEFAULT 0,
  months_worked   NUMERIC(4,1) NOT NULL DEFAULT 12,
  computed_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
  year            INT NOT NULL,
  status          TEXT NOT NULL DEFAULT 'Pending'
                  CHECK (status IN ('Pending','Approved','Paid')),
  UNIQUE (employee_id, year)
);

-- =====================================================
-- SUPPORTING TABLES
-- =====================================================
CREATE TABLE IF NOT EXISTS public.system_settings (
  setting_key   VARCHAR(50) PRIMARY KEY,
  setting_value TEXT,
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.notifications (
  id         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_name  VARCHAR(100),
  title      VARCHAR(150) NOT NULL,
  message    TEXT,
  is_read    BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.audit_log (
  id         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  action     VARCHAR(150) NOT NULL,
  module     VARCHAR(80),
  user_id    INT REFERENCES public.app_users(id) ON DELETE SET NULL,
  details    JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- =====================================================
-- ROW LEVEL SECURITY
-- The PHP backend connects as the postgres/service role and bypasses RLS.
-- These policies govern direct client access (Supabase JS SDK / PostgREST),
-- so a leaked anon key cannot read payroll data.
-- =====================================================
ALTER TABLE public.employees            ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.payroll_runs         ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.payroll_items        ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.claims               ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.benefit_enrollments  ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.finance_approvals    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.app_users            ENABLE ROW LEVEL SECURITY;

DO $$ BEGIN
  CREATE POLICY "staff can read employees"
    ON public.employees FOR SELECT
    TO authenticated USING (true);
  CREATE POLICY "staff can read payroll"
    ON public.payroll_runs FOR SELECT
    TO authenticated USING (true);
  CREATE POLICY "finance and admin write payroll"
    ON public.payroll_runs FOR ALL
    TO authenticated
    USING (true) WITH CHECK (true);
  CREATE POLICY "staff can read payroll items"
    ON public.payroll_items FOR SELECT
    TO authenticated USING (true);
  CREATE POLICY "staff can read claims"
    ON public.claims FOR SELECT
    TO authenticated USING (true);
  CREATE POLICY "staff can submit claims"
    ON public.claims FOR INSERT
    TO authenticated WITH CHECK (true);
  CREATE POLICY "staff can read benefits"
    ON public.benefit_enrollments FOR SELECT
    TO authenticated USING (true);
  CREATE POLICY "finance can review approvals"
    ON public.finance_approvals FOR ALL
    TO authenticated USING (true) WITH CHECK (true);
  -- app_users holds password hashes: readable only by the service role,
  -- which is what "TO authenticated" below deliberately does NOT grant.
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

REVOKE ALL ON public.app_users FROM PUBLIC, anon, authenticated;
GRANT SELECT ON public.app_users TO service_role;

-- =====================================================
-- STORAGE BUCKETS (payslip PDFs and claim receipts)
-- =====================================================
INSERT INTO storage.buckets (id, name, public)
VALUES ('payslips', 'payslips', FALSE), ('receipts', 'receipts', FALSE)
ON CONFLICT (id) DO NOTHING;

DO $$ BEGIN
  CREATE POLICY "staff reads payslips"
    ON storage.objects FOR SELECT TO authenticated
    USING (bucket_id IN ('payslips','receipts'));
  CREATE POLICY "staff uploads payslips"
    ON storage.objects FOR INSERT TO authenticated
    WITH CHECK (bucket_id IN ('payslips','receipts'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

-- =====================================================
-- REALTIME — publish row changes the dashboard subscribes to
-- =====================================================
DO $$ BEGIN
  ALTER PUBLICATION supabase_realtime ADD TABLE public.payroll_runs;
  ALTER PUBLICATION supabase_realtime ADD TABLE public.payroll_items;
  ALTER PUBLICATION supabase_realtime ADD TABLE public.claims;
EXCEPTION WHEN others THEN NULL; END $$;
