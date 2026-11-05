-- =============================================================================
-- SQLiGuard: lab account hardening and dedicated `lab` schema
-- =============================================================================
-- Run once as the PostgreSQL superuser (the owner of sqliguardDB), e.g.:
--
--   psql -U postgres -h 127.0.0.1 -d sqliguardDB -f database/provisioning/lab-hardening.sql
--
-- These statements need superuser / database-owner rights, which the application
-- account (sqliguard_app) intentionally does not have, so they cannot run as a
-- Laravel migration. The script is idempotent and runs in a single transaction.
-- See docs/architecture.md, section 4, for the reasoning behind each step.
-- =============================================================================

\set ON_ERROR_STOP on

BEGIN;

-- 1. Remove temporary-table creation from every role that relied on the PUBLIC
--    default. Laravel does not use temporary tables, so sqliguard_app does not
--    need it either (least privilege).
REVOKE TEMPORARY ON DATABASE "sqliguardDB" FROM PUBLIC;
REVOKE TEMPORARY ON DATABASE "sqliguardDB" FROM sqliguard_lab;  -- in case it was ever granted directly

-- 2. Bound the run time of every statement executed by the lab account, which
--    limits pg_sleep() and expensive injected queries. Applies to new sessions.
ALTER ROLE sqliguard_lab SET statement_timeout = '5s';

-- 3. Dedicated schema for demonstration tables, owned by the application account
--    so Laravel migrations (on the pgsql connection) can create, alter and drop
--    demo tables in it and grant SELECT on them to the lab account.
CREATE SCHEMA IF NOT EXISTS lab AUTHORIZATION sqliguard_app;
ALTER SCHEMA lab OWNER TO sqliguard_app;
REVOKE ALL ON SCHEMA lab FROM PUBLIC;
REVOKE ALL ON SCHEMA lab FROM sqliguard_lab;     -- start from nothing, then grant USAGE only
GRANT USAGE ON SCHEMA lab TO sqliguard_lab;      -- USAGE only, never CREATE
-- No ALTER DEFAULT PRIVILEGES: every demo table must be granted explicitly.

-- 4. Keep the lab account out of `public`, where the application tables live.
--    sqliguard_app keeps its explicit USAGE and CREATE on public.
REVOKE USAGE ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM sqliguard_lab;  -- in case anything was ever granted directly
GRANT USAGE, CREATE ON SCHEMA public TO sqliguard_app;

COMMIT;

-- -----------------------------------------------------------------------------
-- Read-only verification (expected values in comments)
-- -----------------------------------------------------------------------------
SELECT
    has_database_privilege('sqliguard_lab', 'sqliguardDB', 'TEMP')    AS lab_temp,          -- f
    has_database_privilege('sqliguard_lab', 'sqliguardDB', 'CONNECT') AS lab_connect,       -- t
    has_schema_privilege('sqliguard_lab', 'public', 'USAGE')          AS lab_public_usage,  -- f
    has_schema_privilege('sqliguard_lab', 'public', 'CREATE')         AS lab_public_create, -- f
    has_schema_privilege('sqliguard_lab', 'lab', 'USAGE')             AS lab_lab_usage,     -- t
    has_schema_privilege('sqliguard_lab', 'lab', 'CREATE')            AS lab_lab_create,    -- f
    has_schema_privilege('sqliguard_app', 'public', 'CREATE')         AS app_public_create, -- t
    has_schema_privilege('sqliguard_app', 'lab', 'CREATE')            AS app_lab_create;    -- t

SELECT r.rolname, s.setconfig                                                               -- {statement_timeout=5s}
FROM pg_db_role_setting s
JOIN pg_roles r ON r.oid = s.setrole
WHERE r.rolname = 'sqliguard_lab';
