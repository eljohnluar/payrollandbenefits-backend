-- =====================================================
-- DEV SEED — payroll tables + one HR login
--
-- NOT production data. The seeded password is the literal string
-- 'password123', matching the existing demo app's dev login. Replace or
-- delete this row before going anywhere real.
-- =====================================================

INSERT INTO public.app_users (email, password, name, role, initials, is_active)
VALUES
  ('admin@company.com', '$2y$10$YKOTzBTyNdMt1av.bK4zq.iw3NtkqILccqqYg7Xa0BQALIxtpACbG', 'Admin User', 'Admin', 'AU', TRUE),
  ('hr@company.com',    '$2y$10$YKOTzBTyNdMt1av.bK4zq.iw3NtkqILccqqYg7Xa0BQALIxtpACbG', 'HR User',    'HR',    'HU', TRUE),
  ('finance@company.com', '$2y$10$YKOTzBTyNdMt1av.bK4zq.iw3NtkqILccqqYg7Xa0BQALIxtpACbG', 'Finance Officer', 'Finance', 'FO', TRUE)
ON CONFLICT (email) DO NOTHING;

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
  ('pagibig_er', '100')
ON CONFLICT (setting_key) DO NOTHING;

INSERT INTO public.claim_categories (id, name, max_amount) VALUES
  ('cat-transport', 'Transportation',  1000.00),
  ('cat-meal',      'Meal Allowance',   500.00),
  ('cat-medical',   'Medical',         5000.00),
  ('cat-supplies',  'Office Supplies', 2000.00),
  ('cat-training',  'Training',       10000.00),
  ('cat-ot',        'Overtime',         300.00)
ON CONFLICT (id) DO NOTHING;

-- Copy the 20-employee demo roster from the legacy MySQL database.
-- Export it first with:
--   mysql -u root payroll_benefits_db -e "SELECT * FROM employees" --tab=...
-- then load into Postgres, or paste the VALUES list produced by
--   php backend/tools/export_employees_for_postgres.php
-- (that helper is not part of this slice).
