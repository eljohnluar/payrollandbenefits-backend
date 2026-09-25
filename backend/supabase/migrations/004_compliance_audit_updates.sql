-- =====================================================
-- 004 — Compliance & audit policy updates
--   * Audit log request metadata (IP / user name)
--   * Attendance: late tracking + payroll attendance deduction
--   * Finance approval timestamp (3-day payout lead time)
--   * Claims finance-disbursement audit trail
--   * Soft-delete archive for main records
--   * Payslip email outbox
--   * Mandatory Service Incentive Leave balances
-- =====================================================

-- ── Audit log: capture where the action came from ──
ALTER TABLE public.audit_log ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45);
ALTER TABLE public.audit_log ADD COLUMN IF NOT EXISTS user_name  VARCHAR(255);
UPDATE public.audit_log a SET user_name = u.name
  FROM public.app_users u WHERE a.user_id = u.id AND a.user_name IS NULL;
CREATE INDEX IF NOT EXISTS idx_audit_log_created ON public.audit_log (created_at DESC);

-- ── Attendance: lateness feeds the auto-deduction ──
ALTER TABLE public.attendance_logs ADD COLUMN IF NOT EXISTS late_minutes NUMERIC(6,1) NOT NULL DEFAULT 0;
ALTER TABLE public.attendance_logs DROP CONSTRAINT IF EXISTS attendance_logs_status_check;
ALTER TABLE public.attendance_logs ADD CONSTRAINT attendance_logs_status_check
  CHECK (status IN ('P','H','A','OT','L'));

-- ── Payroll: explicit late deduction line + finance approval time ──
ALTER TABLE public.payroll_items ADD COLUMN IF NOT EXISTS attendance_deduction NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE public.payroll_runs  ADD COLUMN IF NOT EXISTS finance_approved_at TIMESTAMPTZ;
UPDATE public.payroll_runs r SET finance_approved_at = fa.reviewed_at
  FROM public.finance_approvals fa
 WHERE fa.payroll_run_id = r.id AND fa.status = 'Approved' AND r.finance_approved_at IS NULL;

-- ── Claims: finance disbursement audit trail ──
ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS disbursed_by INT REFERENCES public.app_users(id) ON DELETE SET NULL;
ALTER TABLE public.claims ADD COLUMN IF NOT EXISTS disbursed_at TIMESTAMPTZ;

-- ── Archive: soft-delete flags so history and FKs survive a delete ──
ALTER TABLE public.employees           ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE public.claims              ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE public.benefit_enrollments ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE public.payroll_items       ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS public.archive_records (
  id          INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_key  VARCHAR(40) NOT NULL,
  label       VARCHAR(255),
  snapshot    JSONB NOT NULL,
  archived_by INT REFERENCES public.app_users(id) ON DELETE SET NULL,
  archived_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  restored_at TIMESTAMPTZ,
  UNIQUE (entity_type, entity_key)
);

-- ── Payslip email outbox (password-gated release emails land here) ──
CREATE TABLE IF NOT EXISTS public.email_outbox (
  id              INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  to_email        VARCHAR(255) NOT NULL,
  subject         VARCHAR(255) NOT NULL,
  message         TEXT,
  attachment_name VARCHAR(255),
  status          TEXT NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending','Sent','Failed')),
  channel         VARCHAR(40),
  detail          TEXT,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ── Mandatory Service Incentive Leave: 15 days per active employee ──
INSERT INTO public.leave_balances (employee_id, leave_type, accrued, used, balance, year)
SELECT e.id, 'Service Incentive Leave', 15, 0, 15, EXTRACT(YEAR FROM CURRENT_DATE)::int
  FROM public.employees e
 WHERE e.status IN ('Active','On Leave') AND e.is_archived = FALSE
   AND NOT EXISTS (
     SELECT 1 FROM public.leave_balances lb
      WHERE lb.employee_id = e.id AND lb.leave_type = 'Service Incentive Leave'
        AND lb.year = EXTRACT(YEAR FROM CURRENT_DATE)::int
   );
