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
--
-- Read-only verification only (skips every GRANT / REVOKE / ALTER below and runs
-- just the checks at the end, inside a READ ONLY transaction):
--
--   psql -U postgres -h 127.0.0.1 -d sqliguardDB -v verify_only=1 -f database/provisioning/lab-hardening.sql
--
-- The checks read only system catalogs and privilege-inquiry functions, so any
-- account that can connect to sqliguardDB (postgres or sqliguard_app) can run
-- them. A failed check stops psql with a nonzero exit status (ON_ERROR_STOP).
-- =============================================================================

\set ON_ERROR_STOP on

\if :{?verify_only}
\echo 'verify_only is set: skipping provisioning, running the read-only checks only.'
\else
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
\endif

-- -----------------------------------------------------------------------------
-- Read-only verification
-- -----------------------------------------------------------------------------
-- One SELECT that lists every check as PASS or FAIL, then a SUMMARY row. If any
-- check fails, the SUMMARY expression raises an error naming every failed
-- check ("VERIFICATION FAILED: …"), so psql stops with a nonzero exit status.
-- Nothing is created or changed: only catalogs and has_*_privilege() are read,
-- inside a READ ONLY transaction that is rolled back.
--
-- Boundary checked (docs/architecture.md, sections 4.1 and 10):
--   * database-, schema- and role-level limits of sqliguard_lab;
--   * the application history table public.comparison_records exists, is owned
--     by sqliguard_app and is invisible to sqliguard_lab (table and sequence);
--   * sqliguard_lab holds no table, column or sequence privilege on anything
--     in schema public, and no object there is granted to PUBLIC;
--   * sqliguard_lab's explicit table grants are exactly SELECT on the four
--     lab.demo_* tables (nothing on lab.demo_account_credentials, no writes).
-- -----------------------------------------------------------------------------
BEGIN TRANSACTION READ ONLY;

WITH
lab_role AS (
    SELECT oid FROM pg_roles WHERE rolname = 'sqliguard_lab'
),
public_relations AS (
    SELECT c.oid, c.relkind
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'f', 'S')
),
lab_relation_grants AS (
    SELECT n.nspname || '.' || c.relname || ':' || a.privilege_type AS grant_text
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    CROSS JOIN LATERAL aclexplode(c.relacl) a
    WHERE a.grantee = (SELECT oid FROM lab_role)
),
history AS (
    SELECT c.oid, c.relkind, pg_get_userbyid(c.relowner) AS owner
    FROM pg_class c
    WHERE c.oid = to_regclass('public.comparison_records')
),
history_sequence AS (
    SELECT to_regclass('public.comparison_records_id_seq') AS oid
),
checks (ord, name, ok) AS (
    VALUES
    -- Database and roles
    (1,  'connected to sqliguardDB',                                    current_database() = 'sqliguardDB'),
    (2,  'sqliguard_lab exists',                                        EXISTS (SELECT 1 FROM lab_role)),
    (3,  'sqliguard_lab can CONNECT',                                   has_database_privilege('sqliguard_lab', current_database(), 'CONNECT')),
    (4,  'sqliguard_lab has no TEMP',                                   NOT has_database_privilege('sqliguard_lab', current_database(), 'TEMP')),
    (5,  'sqliguard_lab has no elevated role attributes',               NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'sqliguard_lab' AND (rolsuper OR rolcreatedb OR rolcreaterole OR rolbypassrls OR rolreplication))),
    (6,  'sqliguard_lab is not a member of any role',                   NOT EXISTS (SELECT 1 FROM pg_auth_members WHERE member = (SELECT oid FROM lab_role))),
    (7,  'sqliguard_lab statement_timeout is 5s',                       EXISTS (SELECT 1 FROM pg_db_role_setting WHERE setrole = (SELECT oid FROM lab_role) AND setdatabase = 0 AND 'statement_timeout=5s' = ANY (setconfig))),
    -- Schemas
    (8,  'sqliguard_lab has no USAGE on public',                        NOT has_schema_privilege('sqliguard_lab', 'public', 'USAGE')),
    (9,  'sqliguard_lab has no CREATE on public',                       NOT has_schema_privilege('sqliguard_lab', 'public', 'CREATE')),
    (10, 'sqliguard_lab has USAGE on lab',                              has_schema_privilege('sqliguard_lab', 'lab', 'USAGE')),
    (11, 'sqliguard_lab has no CREATE on lab',                          NOT has_schema_privilege('sqliguard_lab', 'lab', 'CREATE')),
    (12, 'sqliguard_app has CREATE on public',                          has_schema_privilege('sqliguard_app', 'public', 'CREATE')),
    (13, 'sqliguard_app has CREATE on lab',                             has_schema_privilege('sqliguard_app', 'lab', 'CREATE')),
    -- Application history table
    (14, 'public.comparison_records exists as a table',                 EXISTS (SELECT 1 FROM history WHERE relkind = 'r')),
    (15, 'public.comparison_records is owned by sqliguard_app',         EXISTS (SELECT 1 FROM history WHERE owner = 'sqliguard_app')),
    (16, 'sqliguard_lab has no privilege on comparison_records',        COALESCE((SELECT NOT has_table_privilege('sqliguard_lab', oid, 'SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER') AND NOT has_any_column_privilege('sqliguard_lab', oid, 'SELECT, INSERT, UPDATE, REFERENCES') FROM history), false)),
    (17, 'comparison_records_id_seq exists',                            (SELECT oid IS NOT NULL FROM history_sequence)),
    (18, 'sqliguard_lab has no privilege on comparison_records_id_seq', COALESCE((SELECT NOT has_sequence_privilege('sqliguard_lab', oid, 'USAGE, SELECT, UPDATE') FROM history_sequence WHERE oid IS NOT NULL), false)),
    -- Everything else in public
    -- CASE guarantees each inquiry function only sees its own kind of relation
    -- (AND gives no evaluation-order guarantee in PostgreSQL).
    (19, 'sqliguard_lab has no table privilege on any public relation', NOT EXISTS (SELECT 1 FROM public_relations WHERE CASE WHEN relkind <> 'S' THEN has_table_privilege('sqliguard_lab', oid, 'SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER') ELSE false END)),
    (20, 'sqliguard_lab has no column privilege on any public relation', NOT EXISTS (SELECT 1 FROM public_relations WHERE CASE WHEN relkind <> 'S' THEN has_any_column_privilege('sqliguard_lab', oid, 'SELECT, INSERT, UPDATE, REFERENCES') ELSE false END)),
    (21, 'sqliguard_lab has no privilege on any public sequence',       NOT EXISTS (SELECT 1 FROM public_relations WHERE CASE WHEN relkind = 'S' THEN has_sequence_privilege('sqliguard_lab', oid, 'USAGE, SELECT, UPDATE') ELSE false END)),
    (22, 'no relation in public or lab is granted to PUBLIC',                   NOT EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace CROSS JOIN LATERAL aclexplode(c.relacl) a WHERE n.nspname IN ('public', 'lab') AND a.grantee = 0)),
    -- Demonstration tables
    (23, 'sqliguard_lab table grants are exactly SELECT on the four lab.demo_* tables',
         (SELECT coalesce(string_agg(grant_text, ', ' ORDER BY grant_text), '') FROM lab_relation_grants)
         = 'lab.demo_accounts:SELECT, lab.demo_documents:SELECT, lab.demo_internal_projects:SELECT, lab.demo_products:SELECT'),
    (24, 'sqliguard_lab can SELECT every lab.demo_* table',             has_table_privilege('sqliguard_lab', 'lab.demo_accounts', 'SELECT') AND has_table_privilege('sqliguard_lab', 'lab.demo_products', 'SELECT') AND has_table_privilege('sqliguard_lab', 'lab.demo_internal_projects', 'SELECT') AND has_table_privilege('sqliguard_lab', 'lab.demo_documents', 'SELECT')),
    (25, 'sqliguard_lab cannot write any lab.demo_* table',             NOT (has_table_privilege('sqliguard_lab', 'lab.demo_accounts', 'INSERT, UPDATE, DELETE, TRUNCATE') OR has_table_privilege('sqliguard_lab', 'lab.demo_products', 'INSERT, UPDATE, DELETE, TRUNCATE') OR has_table_privilege('sqliguard_lab', 'lab.demo_internal_projects', 'INSERT, UPDATE, DELETE, TRUNCATE') OR has_table_privilege('sqliguard_lab', 'lab.demo_documents', 'INSERT, UPDATE, DELETE, TRUNCATE'))),
    (26, 'sqliguard_lab has no privilege on lab.demo_account_credentials', NOT has_table_privilege('sqliguard_lab', 'lab.demo_account_credentials', 'SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER') AND NOT has_any_column_privilege('sqliguard_lab', 'lab.demo_account_credentials', 'SELECT, INSERT, UPDATE, REFERENCES')),
    (27, 'sqliguard_lab can EXECUTE lab.demo_password_matches(bigint, text)', has_function_privilege('sqliguard_lab', 'lab.demo_password_matches(bigint, text)', 'EXECUTE'))
),
summary AS (
    SELECT count(*) AS total,
           count(*) FILTER (WHERE ok IS DISTINCT FROM true) AS failed,
           string_agg(name, '; ' ORDER BY ord) FILTER (WHERE ok IS DISTINCT FROM true) AS failed_names
    FROM checks
)
SELECT ord, name AS check, CASE WHEN ok THEN 'PASS' ELSE 'FAIL' END AS result
FROM checks
UNION ALL
SELECT 99, 'SUMMARY',
       CASE WHEN failed = 0
            THEN 'ALL ' || total || ' CHECKS PASSED'
            -- Deliberate error: the text cannot be cast to integer, so the
            -- statement fails and the message names every failed check.
            ELSE ('VERIFICATION FAILED: ' || failed_names)::integer::text
       END
FROM summary
ORDER BY ord;

ROLLBACK;
