-- 005_integration.sql — machine-to-machine integration layer
-- Lets Core HR / Workforce Management / Performance push data in, and
-- Financial Management pull journal entries or receive payroll events.

CREATE TABLE IF NOT EXISTS public.integration_keys (
  id           INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name         VARCHAR(100) NOT NULL UNIQUE,
  key_hash     CHAR(64) NOT NULL UNIQUE,
  scopes       TEXT NOT NULL DEFAULT '*',
  is_active    BOOLEAN NOT NULL DEFAULT TRUE,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_used_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS public.webhook_endpoints (
  id         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name       VARCHAR(100) NOT NULL UNIQUE,
  event      VARCHAR(100) NOT NULL,
  url        TEXT NOT NULL,
  secret     VARCHAR(100) NOT NULL DEFAULT '',
  is_active  BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.integration_events_log (
  id         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  event      VARCHAR(100) NOT NULL,
  payload    TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Dev/demo key. The plaintext lives only in this demo seed; real keys are
-- issued by scripts/create-integration-key.php and shown exactly once.
INSERT INTO public.integration_keys (name, key_hash, scopes)
VALUES ('demo-core-hr',
        '05ebc98c2b180dcf703d0e27046276413255ae6c16533d8723bb142b9655756f',
        'core-hr:write,workforce:write,performance:write,finance:read')
ON CONFLICT (name) DO NOTHING;
