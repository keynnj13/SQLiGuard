# SQLiGuard Architecture

**SQLiGuard: A Web Input Security Demonstrator Comparing Vulnerable and Parameterized Queries Against SQL Injection in a Controlled Lab Environment**

This document records the approved architecture, database boundaries and feature scope. Update it when an approved decision changes. Status: Phase 1 (architecture and base setup), 2026-11-05.

## 1. Technology Stack

| Layer | Technology | Notes |
| --- | --- | --- |
| Language | PHP 8.3+ | Development machine runs PHP 8.5 (XAMPP CLI) |
| Framework | Laravel 13 | Standard Laravel directory structure and conventions |
| Database | PostgreSQL 18.4 | One database, `sqliguardDB`, with two connections and two schemas |
| Views | Blade | Shared layout: `<x-layouts.app>` |
| UI | Bootstrap 5.3 | Bundled from npm by Vite, with no CDN, so the lab works offline |
| JavaScript | Minimal | Bootstrap's JS (with Popper) only |
| Charts | Chart.js 4 | Installed. Imported only by dashboard views (later phase) |
| Build | Vite 8 + `laravel-vite-plugin` | Entries: `resources/css/app.css`, `resources/js/app.js` |
| Dev tooling | Laravel Boost, Pint, PHPUnit | Boost guidelines live in `CLAUDE.md` / `AGENTS.md` |

No other frontend frameworks or UI libraries are used.

## 2. Project Structure

The project follows the default Laravel 13 layout. New code goes in the conventional locations:

| Location | Purpose |
| --- | --- |
| `app/Http/Controllers` | Thin controllers |
| `app/Http/Requests` | Form Requests for every application input |
| `app/Policies` | Authorization for administrative operations |
| `app/Models` | Application models on the `pgsql` connection |
| `database/migrations` | All schema changes, run on `pgsql`. Existing migrations are never edited |
| `database/provisioning/lab-hardening.sql` | Superuser-only database setup (section 4.3) |
| `database/seeders` | Fictitious demonstration data and initial accounts |
| `resources/views/components/layouts/app.blade.php` | Base Bootstrap layout |
| `resources/views/components/lab-warning.blade.php` | Vulnerable Mode warning banner |
| `tests/Feature` | Feature tests, including the lab permission boundary test |
| `docs/` | Project documentation |

The vulnerable and secure demonstration query code will be kept together in one small, clearly named class that runs on the `lab` connection, so all intentionally unsafe SQL lives in one reviewable place. Its exact location will be agreed in the phase that implements it.

## 3. Database Architecture

One PostgreSQL database (`sqliguardDB`, owned by `postgres`) holds two schemas and is accessed through two Laravel connections, each using a different PostgreSQL account.

```
                          ┌──────────────────── sqliguardDB ────────────────────┐
                          │ schema public (application)  schema lab (demo only) │
 Laravel app ── pgsql ──▶ │ sqliguard_app: owns tables   sqliguard_app: owns    │
   (default)              │   users, sessions, cache,      schema and demo      │
                          │   jobs, migrations, history,   tables (migrations)  │
                          │   logs, settings                                    │
                          │                                                     │
 Demo runner ── lab ────▶ │ sqliguard_lab: no USAGE      sqliguard_lab: USAGE + │
                          │   on public                    SELECT on explicitly │
                          │                                granted tables only  │
                          └─────────────────────────────────────────────────────┘
```

### 3.1 `pgsql`: application connection (default)

- Account: `sqliguard_app`
- Configured by `DB_*` in `.env` and `config/database.php` → `connections.pgsql` (`search_path` = `public`)
- Used for: user accounts, authentication, sessions, cache, queue, application records, test history, activity logs, dashboard data, administrative records, and running every migration, including creating demonstration tables in the `lab` schema.

### 3.2 `lab`: demonstration connection

- Account: `sqliguard_lab`
- Configured by `LAB_DB_*` in `.env` and `config/database.php` → `connections.lab` (`search_path` = `lab`)
- Used **only** for demonstration queries, in both Vulnerable Mode and Secure Mode, against fake demonstration tables in the `lab` schema.
- Application credentials are never used for intentionally vulnerable queries.
- Usage in code: `DB::connection('lab')`.

Real passwords exist only in `.env`, which is git-ignored. `.env.example` lists the variable names with empty passwords.

### 3.3 Why two accounts and two schemas

The restricted account contains the damage: a successful injection in Vulnerable Mode can only reach what `sqliguard_lab` is allowed to read. The separate schema adds a second wall: the lab account has no USAGE on `public`, so it cannot even reference an application table by name.

Neither one stops disclosure from tables the lab account *can* read. Bypassing a `WHERE` clause on a demo table still exposes every row in it. That is why:

1. Demo tables contain only fictitious data, and
2. Secure Mode must use parameterized queries so that input can never change the query structure. The account and schema separation is defense in depth, not the fix.

## 4. Database Permission Boundaries

### 4.1 Permission matrix

| Privilege | `sqliguard_app` | `sqliguard_lab` |
| --- | --- | --- |
| CONNECT on `sqliguardDB` | Yes | Yes |
| TEMPORARY on `sqliguardDB` | No (not needed by Laravel) | **No** |
| Schema `public`: USAGE / CREATE | Yes / Yes | **No / No** |
| Application tables in `public` (`users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`) | Owner | **None** |
| Schema `lab` | Owner (USAGE + CREATE) | USAGE only, **no CREATE** |
| Demo tables in `lab` | Owner | SELECT, only when explicitly granted per table |
| INSERT / UPDATE / DELETE / TRUNCATE anywhere | Own tables | **Never** |
| Superuser / CREATEDB / CREATEROLE / BYPASSRLS / REPLICATION | No | No |
| `statement_timeout` | Server default | **5s** (role setting) |
| Member of other roles | None | None |

### 4.2 Schema and migration approach for demonstration tables

- Demo tables are created by normal Laravel migrations on the default `pgsql` connection, using schema-qualified names. Laravel 13 supports `schema.table` references on PostgreSQL.
- Because `sqliguard_app` owns the `lab` schema and every table it creates, the same migration can grant access.
- **There are no default privileges.** A new table in `lab` is invisible to `sqliguard_lab` until a migration grants it explicitly, and only `SELECT` is ever granted.
- Do not create functions, views or sequences in `lab` for the lab account to use unless that is separately approved. PostgreSQL grants EXECUTE on new functions to PUBLIC by default.
- "Reset demonstration data" re-seeds through `pgsql`, never through `lab`.

Pattern for a future demo-table migration (not created yet):

```php
public function up(): void
{
    Schema::create('lab.<demo_table>', function (Blueprint $table) {
        // fictitious demonstration columns
    });

    DB::statement('GRANT SELECT ON lab.<demo_table> TO sqliguard_lab');
}

public function down(): void
{
    Schema::dropIfExists('lab.<demo_table>'); // drops its grants too
}
```

### 4.3 Superuser provisioning (`database/provisioning/lab-hardening.sql`)

The database is owned by `postgres`, and `sqliguard_app` deliberately has no rights to create schemas, change database ACLs or alter roles. These steps therefore cannot be migrations. They are kept in one idempotent, transactional script that the database administrator runs once:

```sh
psql -U postgres -h 127.0.0.1 -d sqliguardDB -f database/provisioning/lab-hardening.sql
```

| Step | Statement | Reason |
| --- | --- | --- |
| 1 | `REVOKE TEMPORARY ON DATABASE "sqliguardDB" FROM PUBLIC` | Removes the PostgreSQL default that let the lab account create temporary tables. Laravel does not need it, so it is not re-granted to `sqliguard_app` |
| 2 | `ALTER ROLE sqliguard_lab SET statement_timeout = '5s'` | Bounds `pg_sleep()` and expensive injected queries. Applies to new sessions |
| 3 | `CREATE SCHEMA lab AUTHORIZATION sqliguard_app`; `GRANT USAGE ON SCHEMA lab TO sqliguard_lab` | Dedicated demo schema that migrations can manage. The lab account can resolve names in it but cannot create anything |
| 4 | `REVOKE USAGE ON SCHEMA public FROM PUBLIC`; explicit `GRANT USAGE, CREATE ON SCHEMA public TO sqliguard_app` | Keeps the lab account out of the application schema entirely. The app keeps its explicit grants |

The script ends with read-only verification queries. `tests/Feature/LabConnectionBoundaryTest.php` checks the same state on every test run.

### 4.4 Known limits

- PostgreSQL system catalogs (`pg_catalog`, `information_schema`) are readable by every role. An injection can list table and column *names*, but cannot read rows from tables the lab account has no privileges on. This can be used to explain schema enumeration.
- `statement_timeout` is a user-settable parameter. Injected SQL such as `set_config('statement_timeout', '0', false)` could lift it for the rest of that database session. The demonstration code (later phase) must therefore run each vulnerable query on a fresh `lab` connection (for example `DB::purge('lab')` afterwards), and must never reuse that session for Secure Mode.

## 5. Roles and Feature Boundaries

| Feature | Administrator (Instructor) | Student/Tester |
| --- | :---: | :---: |
| Manage application accounts | ✓ | |
| Enable or disable Vulnerable Mode | ✓ | |
| Reset demonstration data | ✓ | |
| View all test records | ✓ | |
| Dashboard summaries (Chart.js) | ✓ | |
| Run SQL injection demonstrations | ✓ | ✓ |
| Use normal or seeded sample inputs | ✓ | ✓ |
| Compare Vulnerable and Secure Mode side by side | ✓ | ✓ |
| View generated queries, results and explanations | ✓ | ✓ |
| View personal test history | ✓ | ✓ |

- The main demonstration interface always runs the **same input** through both modes and shows the results **side by side**. A single mode toggle must not replace this comparison.
- The administrator's Vulnerable Mode switch only turns the vulnerable side on or off. It can never make Vulnerable Mode available outside the `local` environment.
- Administrative operations are protected by authorization checks (policies or gates), not by hiding links.
- Anything not in this table is out of scope unless approved.

## 6. Security Rules

| Rule | How it is applied |
| --- | --- |
| Vulnerable Mode restricted to local development | Available only when `app()->environment('local')` **and** enabled by an administrator |
| Visible warning when Vulnerable Mode is available | `<x-lab-warning />` in the base layout, shown in the `local` environment |
| No real credentials or user information in demos | Demo tables contain only fictitious data. The lab account cannot reach the `public` schema |
| Fake demonstration datasets | Seeded through `pgsql` into the `lab` schema |
| Laravel authentication, hashed passwords | Built-in auth with the `hashed` cast on `User::password` |
| Form Request validation | Every application input goes through a Form Request |
| Secure Mode uses Query Builder or parameterized queries | Bound parameters on the `lab` connection |
| Raw PDO only for intentionally vulnerable demos | Isolated in one clearly marked class on the `lab` connection |
| Escape user-generated content | Blade `{{ }}` everywhere. `{!! !!}` is never used for input, queries or results |
| No credentials, stack traces or server errors exposed | Catch `QueryException` from demonstrations and show a sanitized summary. Its raw message includes host and database names. See also 6.1 |
| Authorization on administrative operations | Policies or gates on every admin route |
| Separate application and lab connections | `pgsql` vs `lab`, with different accounts and schemas |
| No weakening of database permissions | Permission changes are documented here and approved first |

### 6.1 `APP_DEBUG` and live demonstrations

`APP_DEBUG=true` stays enabled for normal local development. With debug on, any uncaught exception renders Laravel's detailed error page: stack trace, file paths, and SQL with connection details.

**Before every live demonstration, set `APP_DEBUG=false` in `.env` and run `php artisan config:clear`.** Restore it afterwards. `APP_ENV` stays `local`, so Vulnerable Mode remains available while errors render as a generic error page.

## 7. Testing

- `phpunit.xml` points the default connection at in-memory SQLite, so tests never modify `sqliguardDB`.
- `tests/Feature/LabConnectionBoundaryTest.php` (group `lab-database`) connects only through `lab` and checks the matrix in section 4.1:
  - no privileges on application tables
  - no TEMP, and no CREATE in either schema
  - USAGE on `lab` only
  - `lab` schema owned by `sqliguard_app`
  - 5s statement timeout
  - no elevated attributes

  It needs the local PostgreSQL server. Exclude it with `php artisan test --exclude-group lab-database` when PostgreSQL is unavailable.
- `tests/Feature/HomePageTest.php` checks the base layout and that the lab warning appears only in the `local` environment.

## 8. Phase Log

| Phase | Status | Summary |
| --- | --- | --- |
| 1: Architecture and base setup | Complete | Bootstrap/Vite setup, base layout and warning, architecture docs, project rules, boundary tests, lab hardening and `lab` schema |
