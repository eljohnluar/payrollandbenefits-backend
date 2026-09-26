-- 006_incentives.sql — Incentives module (Compensation)
-- Structures define what an employee can earn; earnings are computed (or
-- metric-recorded) per period and flow into payroll gross as a line item.

CREATE TABLE IF NOT EXISTS public.incentive_structures (
  id             INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id    VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  name           VARCHAR(150) NOT NULL,
  type           TEXT NOT NULL CHECK (type IN ('Performance','Sales','Attendance','Productivity','Referral','Retention','Spot','Team')),
  rate_type      TEXT NOT NULL DEFAULT 'Fixed' CHECK (rate_type IN ('Fixed','Percentage')),
  rate           NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (rate >= 0),
  frequency      TEXT NOT NULL DEFAULT 'Monthly' CHECK (frequency IN ('Monthly','Quarterly','Annual','One-Time')),
  target         NUMERIC(12,2),
  eligibility    VARCHAR(200),
  effective_date DATE NOT NULL DEFAULT CURRENT_DATE,
  end_date       DATE,
  is_active      BOOLEAN NOT NULL DEFAULT TRUE,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.incentive_earnings (
  id                   INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  employee_id          VARCHAR(20) NOT NULL REFERENCES public.employees(id) ON DELETE CASCADE,
  incentive_structure_id INT REFERENCES public.incentive_structures(id) ON DELETE SET NULL,
  period               VARCHAR(7) NOT NULL,
  type                 TEXT NOT NULL,
  basis                TEXT,
  amount               NUMERIC(12,2) NOT NULL DEFAULT 0,
  status               TEXT NOT NULL DEFAULT 'Earned' CHECK (status IN ('Earned','Paid')),
  payroll_run_id       INT REFERENCES public.payroll_runs(id) ON DELETE SET NULL,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (incentive_structure_id, period)
);

ALTER TABLE public.payroll_items ADD COLUMN IF NOT EXISTS incentives NUMERIC(12,2) NOT NULL DEFAULT 0;
