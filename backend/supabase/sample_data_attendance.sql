-- ============================================================
-- Sample attendance data for the Attendance page (Supabase / PostgreSQL)
-- Inserts one row per active employee per working day (Mon-Sat) of the
-- target month, with a deterministic status mix:
--   ~75% Present, ~10% OT, ~5% Holiday, ~5% Absent, ~5% Present+Late.
-- Idempotent: existing (employee_id, log_date) rows are kept untouched.
-- Change the two month bounds below to seed another period.
-- ============================================================

-- 1) Daily time records -> attendance_logs
INSERT INTO attendance_logs (employee_id, log_date, status, actual_hours, ot_hours, nd_hours, holiday_hours, late_minutes)
SELECT
  e.id,
  d.log_date::date,
  CASE m
    WHEN 0 THEN 'OT'
    WHEN 1 THEN 'OT'
    WHEN 2 THEN 'H'
    WHEN 3 THEN 'A'
    WHEN 4 THEN 'P'   -- late arrival
    ELSE 'P'
  END AS status,
  CASE m
    WHEN 0 THEN 10.0 WHEN 1 THEN 9.5
    WHEN 2 THEN 8.0                       -- holiday worked
    WHEN 3 THEN 0.0                       -- absent
    WHEN 4 THEN 7.75                      -- present but late
    ELSE 8.0
  END AS actual_hours,
  CASE m WHEN 0 THEN 2.0 WHEN 1 THEN 1.5 ELSE 0 END AS ot_hours,
  0 AS nd_hours,
  CASE m WHEN 2 THEN 8.0 ELSE 0 END AS holiday_hours,
  CASE m WHEN 3 THEN 0 WHEN 4 THEN 25 + (abs(hashtext(e.id || d.log_date::text)) % 20) ELSE 0 END AS late_minutes
FROM employees e
CROSS JOIN generate_series(DATE '2026-09-01', DATE '2026-09-30', INTERVAL '1 day') AS d(log_date)
CROSS JOIN LATERAL (
  SELECT abs(hashtext(e.id || '|' || d.log_date::text)) % 20 AS m
) mix
WHERE e.status IN ('Active', 'On Leave')
  AND e.is_archived = FALSE
  AND EXTRACT(ISODOW FROM d.log_date) <= 6          -- Mon-Sat working days
ON CONFLICT (employee_id, log_date) DO NOTHING;

-- 2) Shift assignments -> shift_schedules (drives night differential &
--    holiday pay columns on the attendance page). Day shift for most,
--    night shift for one third of employees.
INSERT INTO shift_schedules (employee_id, schedule_date, shift_name, night_diff_hours, holiday_hours)
SELECT
  e.id,
  d.log_date::date,
  CASE abs(hashtext(e.id)) % 3
    WHEN 0 THEN 'Night Shift'
    WHEN 1 THEN 'Day Shift'
    ELSE 'Mid Shift'
  END,
  CASE abs(hashtext(e.id)) % 3 WHEN 0 THEN 4.0 ELSE 0 END,
  CASE WHEN EXTRACT(ISODOW FROM d.log_date) = 6 THEN 8.0 ELSE 0 END
FROM employees e
CROSS JOIN generate_series(DATE '2026-09-01', DATE '2026-09-30', INTERVAL '1 day') AS d(log_date)
WHERE e.status IN ('Active', 'On Leave')
  AND e.is_archived = FALSE
  AND EXTRACT(ISODOW FROM d.log_date) <= 6
ON CONFLICT (employee_id, schedule_date) DO NOTHING;

-- Quick sanity check:
-- SELECT log_date, status, COUNT(*) FROM attendance_logs
--  WHERE log_date BETWEEN '2026-09-01' AND '2026-09-30' GROUP BY 1, 2 ORDER BY 1, 2;
