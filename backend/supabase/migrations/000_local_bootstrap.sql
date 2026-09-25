-- =====================================================
-- LOCAL BOOTSTRAP — run BEFORE 001_init.sql on plain PostgreSQL.
--
-- Recreates the small slice of Supabase scaffolding that 001_init.sql
-- depends on (the anon/authenticated/service_role roles and a minimal
-- storage schema). Not needed when running against a real Supabase project.
-- =====================================================

DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
    CREATE ROLE anon NOLOGIN;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
    CREATE ROLE authenticated NOLOGIN;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'service_role') THEN
    CREATE ROLE service_role NOLOGIN;
  END IF;
END $$;

CREATE SCHEMA IF NOT EXISTS storage;

CREATE TABLE IF NOT EXISTS storage.buckets (
  id     TEXT PRIMARY KEY,
  name   TEXT NOT NULL,
  public BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE TABLE IF NOT EXISTS storage.objects (
  id        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  bucket_id TEXT REFERENCES storage.buckets(id),
  name      TEXT
);
