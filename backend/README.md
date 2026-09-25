# Payroll & Benefits — React + PHP + Supabase

This folder pair is the re-platformed stack described in the implementation plan,
built as **one vertical slice (Payroll Run)** alongside the existing PHP app. The
legacy app in `../pages`, `../api` and MySQL is untouched and still runs.

```
backend/    pure PHP JSON API  ->  Supabase PostgreSQL (Session pooler, port 5432)
frontend/   React 18 + Vite    ->  backend API + Supabase Realtime
```

## Prerequisite already handled here

`pdo_pgsql` was disabled in this XAMPP install. It is now enabled in
`C:\xampp\php\php.ini` (lines 947 and 949); the original was saved as
`C:\xampp\php\php.ini.bak-*`. **Restart Apache** for the module to load.

## 1. Database

Apply `backend/supabase/migrations/001_init.sql` to the Supabase project
(SQLEditor, or `supabase db push`). It creates the Postgres tables, the
`set_updated_at`-style triggers, RLS policies, the `payslips` and `receipts`
storage buckets, and adds the payroll tables to the realtime publication.

## 2. Backend

```bash
cp backend/.env.example backend/.env    # then fill in the Supabase values
php -S localhost:8080 -t backend/public
curl http://localhost:8080/api/health
```

`/api/health` reports whether the database and each Supabase capability are
reachable, so you can confirm wiring before signing in.

Layout:

| Path | Role |
|---|---|
| `backend/public/index.php` | front controller; every route is one line |
| `backend/src/` | Config, Database (PDO), Router, Auth (JWT), Http, bootstrap |
| `backend/services/` | PayrollService, TaxService, BenefitsService, ClaimsService, PayslipService, DashboardService, EmployeeService, SettingsService, AuditService |

Auth accepts either a Supabase-issued JWT (verified with `SUPABASE_JWT_SECRET`)
or a token from `POST /api/auth/login` against `app_users`.

## 3. Frontend

```bash
cd frontend
cp .env.example .env
npm install
npm run dev        # http://localhost:5173
```

Vite proxies `/api` to `http://localhost:8080` in dev, so no CORS setup is
needed locally. `npm run build` emits `frontend/dist` for any static host.

Pages shipped: Login, Dashboard, Payroll Run.

## Endpoints

| Method | Path | Notes |
|---|---|---|
| GET | `/api/health` | public |
| POST | `/api/auth/login` | public, returns a JWT |
| GET | `/api/auth/me` | any role |
| GET | `/api/settings` | any role |
| GET | `/api/dashboard/summary` | any role |
| GET | `/api/employees`, `/api/employees/{id}`, `/api/employees/{id}/profile` | any role |
| GET | `/api/payroll/runs`, `/api/payroll/runs/{id}` | any role |
| POST | `/api/payroll/process` | Admin/HR/Payroll — open + compute (+ optional submit) |
| POST | `/api/payroll/runs/{id}/compute` | persist a recompute of a draft run |
| POST | `/api/payroll/runs/{id}/preview` | compute without writing |
| POST | `/api/payroll/runs/{id}/submit-to-finance` | Admin/HR/Payroll |
| POST | `/api/payroll/runs/{id}/decision` | Admin/Finance — Approved / Rejected / On Hold |
| POST | `/api/payroll/items/{id}/include` | toggle a line in or out of the run |
| POST | `/api/payslips/generate/{itemId}` | render a payslip PDF, upload to Storage |
| GET/POST | `/api/claims...` | list, get, categories, submit, decision |
| GET/POST | `/api/benefit-plans`, `/api/benefits/enrollments`, `/api/benefits/enroll`, `/api/benefits/enrollments/{id}/cancel` | |

## Payroll computation

`PayrollService::computeLine()` reproduces the legacy formulae exactly — same
attendance day weighting, same 1.25x OT, 10% night differential, 2x holiday,
same SSS / PhilHealth / Pag-IBIG tiers and Tier-1 withholding — but every rate
comes from `system_settings`, so figures reconcile against the old app line for
line. `days_worked` defaults to a full month when no attendance rows exist.

## Known gaps in this slice

- `PayslipService` writes a plain-text PDF and uploads it via the Storage REST
  API. Swap in Dompdf when the layout needs branding and tables.
- No e-wallet disbursement or general-ledger posting endpoints yet.
- `app_users` starts empty; seed it from `auth.users` or insert a bcrypt hash
  before testing `POST /api/auth/login`.
- Reports, attendance and employee CRUD pages are not migrated — the legacy PHP
  screens still serve them.
