# SQLiGuard Architecture

**SQLiGuard: A Web Input Security Demonstrator Comparing Vulnerable and Parameterized Queries Against SQL Injection in a Controlled Lab Environment**

This document records the approved architecture, database boundaries and feature scope. Update it when an approved decision changes. Status: Phase 2 (authentication and role management), 2026-11-05.

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
| `app/Console/Commands` | Artisan commands (`sqliguard:create-admin`) |
| `app/Enums` | `Role`, the single source of role identifiers |
| `app/Http/Controllers` | Thin controllers (`Auth/LoginController`, `DashboardController`, `Admin/UserController`) |
| `app/Http/Middleware` | `EnsureUserHasRole` (`role:` alias) and `EnsureAccountIsActive` |
| `app/Http/Requests` | Form Requests for every application input |
| `app/Policies` | Authorization for administrative operations (`UserPolicy`) |
| `app/Models` | Application models on the `pgsql` connection |
| `database/migrations` | All schema changes, run on `pgsql`. Existing migrations are never edited |
| `database/provisioning/lab-hardening.sql` | Superuser-only database setup (section 4.3) |
| `database/seeders` | Fictitious demonstration data. Never user accounts, which would have known passwords |
| `resources/views/components/layouts/app.blade.php` | Base Bootstrap layout and authenticated navigation |
| `resources/views/components/lab-warning.blade.php` | Vulnerable Mode warning banner |
| `resources/views/auth`, `admin`, `student`, `errors` | Login, role landing pages, account management, 403 page |
| `tests/Feature` | Feature tests, mirroring the path of the class under test |
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

### 5.1 Role identifiers and account fields

Roles are defined once in `App\Enums\Role` and stored in `public.users.role`:

| Enum case | Stored value | Label | Landing page |
| --- | --- | --- | --- |
| `Role::Administrator` | `administrator` | Administrator | `/admin/dashboard` (`admin.dashboard`) |
| `Role::Student` | `student` | Student/Tester | `/student/dashboard` (`student.dashboard`) |

Columns added by migration `2026_11_05_132824_add_role_and_is_active_to_users_table` (the original users migration is untouched):

| Column | Type | Default | Notes |
| --- | --- | --- | --- |
| `role` | `varchar` with `CHECK (role IN ('administrator','student'))` | `student` | Least-privileged default. The database rejects any other value |
| `is_active` | `boolean` | `true` | Inactive accounts cannot log in |
| index `(role, is_active)` | | | Supports the active-administrator check |

`role` and `is_active` are not mass assignable on `User`. Only the account-management actions and the create-admin command set them. `password` uses the `hashed` cast, which hashes through Laravel's `Hash` facade (bcrypt, `BCRYPT_ROUNDS`). Password and remember token are `#[Hidden]` from serialization and never rendered in views.

### 5.2 Authentication flow

Laravel's built-in session guard (`web`) with the Eloquent `users` provider. No starter kit is installed: the available kits are built on Tailwind, Livewire or Inertia, which the approved stack excludes.

1. `GET /login` (`guest` middleware) shows the Bootstrap login form with `@csrf`. Logged-in users are redirected to `/dashboard`.
2. `POST /login` → `LoginRequest`:
   - validates `email` and `password`, and lowercases the email
   - checks the rate limiter: 5 failed attempts per email + IP, then a 60-second lockout and a `Lockout` event
   - calls `Auth::attempt()` with `is_active = true` as an extra credential, so inactive accounts get the same generic "These credentials do not match our records." as a wrong password, and the response reveals neither whether an account exists nor its status
   - Laravel's 200 ms timebox around `attempt()` evens out response times
3. On success the session ID is regenerated and the user is redirected to the "intended" URL or `/dashboard`.
   - The intended URL is only ever set by Laravel's `auth` middleware, from a GET request to this application.
   - No `redirect`/`next` parameter is read, so there is no open redirect.
4. `/dashboard` redirects to the role's landing page (`Role::homeRoute()`).
5. `POST /logout` logs out, invalidates the session, regenerates the CSRF token, and redirects to `/login`. Logout is POST-only.

Every authenticated route also runs:

- `auth.session` (Laravel `AuthenticateSession`): when an administrator sets a new password for an account, that account's other sessions are logged out on their next request.
- `EnsureAccountIsActive` (appended to the `web` group): when an administrator deactivates an account, its open sessions are ended on their next request.

There is **no public registration and no password-reset route** (`/register` and `/password/reset` return 404). Administrators set new passwords from the edit form.

Sessions, cache (including the login rate limiter) and queue use the `database` drivers on the `pgsql` connection (`SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` in `.env`). No new environment variables are required.

### 5.3 Authorization

| Layer | What it checks | Where |
| --- | --- | --- |
| `auth` | Logged in, otherwise redirect to `/login` | All routes except `/`, `/login`, `/up` |
| `role:administrator` / `role:student` | The user's role, otherwise 403 | Route groups `admin.*` and `student.*` (`EnsureUserHasRole`) |
| `UserPolicy` | `viewAny`, `create`, `update` allowed only for administrators | Every `Admin\UserController` action, through `Gate::authorize()` or the Form Request's `authorize()` |
| Controller integrity checks | No self role change, no self deactivation, at least one active administrator | Inside a database transaction (section 5.4) |

Role checks are centralized in `User::hasRole()` / `User::isAdministrator()`, which the middleware, policy and views all use. Hidden navigation links are a convenience only. Every route is checked server-side. Unauthorized requests get the Bootstrap 403 page (`resources/views/errors/403.blade.php`).

### 5.4 Account management (administrators only)

| Route | Action |
| --- | --- |
| `GET /admin/users` | Paginated account list (name, email, role, status) |
| `GET /admin/users/create`, `POST /admin/users` | Create an account: name, email, role, password + confirmation. Created active |
| `GET /admin/users/{user}/edit`, `PUT/PATCH /admin/users/{user}` | Edit name, email, role (not for one's own account) and optionally set a new password |
| `PATCH /admin/users/{user}/activate` | Allow the account to log in |
| `PATCH /admin/users/{user}/deactivate` | Block the account. There is no delete, so test history stays attributable |

Integrity rules:

- **Email:** unique and stored lowercase, so uniqueness is case-insensitive. The `users_email_unique` index is the final guard.
- **Password policy** (`Password::defaults()` in `AppServiceProvider`): 12–72 characters with upper- and lowercase letters, a number and a symbol. The 72-character cap avoids bcrypt silently ignoring longer input. There is no breach-list check, because it calls an external API and the lab may be offline.
- **No self role change:** the role field is not rendered on one's own edit form, and the controller rejects a submitted change.
- **No self deactivation:** rejected even when other administrators exist, so an administrator cannot lock themselves out.
- **At least one active administrator must remain.** Demoting or deactivating the last active administrator is refused. The check locks every active-administrator row with `SELECT … FOR UPDATE` in a fixed order inside the same transaction as the change, so two administrators cannot remove each other concurrently.

### 5.5 Creating the first administrator

Accounts cannot self-register, so the first administrator is created from the command line on the lab machine:

```sh
php artisan sqliguard:create-admin
```

The command:

- prompts for the name, the email, and the password twice. Password input is hidden.
- validates the password against the same policy as the web forms
- refuses an email that already has an account
- asks for confirmation if an active administrator already exists
- stores only the password hash
- prints only the created email, never the password, and logs nothing
- refuses to run with `--no-interaction`

No default or seeded administrator exists, and `DatabaseSeeder` creates no accounts. Create further accounts from **Accounts** in the application.

## 6. Security Rules

| Rule | How it is applied |
| --- | --- |
| Vulnerable Mode restricted to local development | Available only when `app()->environment('local')` **and** enabled by an administrator |
| Visible warning when Vulnerable Mode is available | `<x-lab-warning />` in the base layout, shown in the `local` environment |
| No real credentials or user information in demos | Demo tables contain only fictitious data. The lab account cannot reach the `public` schema |
| Fake demonstration datasets | Seeded through `pgsql` into the `lab` schema |
| Laravel authentication, hashed passwords | Built-in session guard. `hashed` cast on `User::password`. Password hashes are hidden and never rendered (section 5) |
| No public registration | No registration or password-reset routes. First administrator via `sqliguard:create-admin` |
| Login abuse | 5 failed attempts per email + IP, then a 60 s lockout. Generic failure message. Session regenerated on login, invalidated on logout |
| Inactive accounts | Refused at login. Open sessions are ended by `EnsureAccountIsActive` |
| CSRF | `@csrf` on every form. State changes use POST/PUT/PATCH only |
| Form Request validation | Every application input goes through a Form Request |
| Secure Mode uses Query Builder or parameterized queries | Bound parameters on the `lab` connection |
| Raw PDO only for intentionally vulnerable demos | Isolated in one clearly marked class on the `lab` connection |
| Escape user-generated content | Blade `{{ }}` everywhere. `{!! !!}` is never used for input, queries or results |
| No credentials, stack traces or server errors exposed | Catch `QueryException` from demonstrations and show a sanitized summary. Its raw message includes host and database names. See also 6.1 |
| Authorization on administrative operations | `role:administrator` route middleware plus `UserPolicy` on every account-management action |
| Separate application and lab connections | `pgsql` vs `lab`, with different accounts and schemas |
| No weakening of database permissions | Permission changes are documented here and approved first |

### 6.1 `APP_DEBUG` and live demonstrations

`APP_DEBUG=true` stays enabled for normal local development. With debug on, any uncaught exception renders Laravel's detailed error page: stack trace, file paths, and SQL with connection details.

**Before every live demonstration, set `APP_DEBUG=false` in `.env` and run `php artisan config:clear`.** Restore it afterwards. `APP_ENV` stays `local`, so Vulnerable Mode remains available while errors render as a generic error page.

## 7. Testing

- `phpunit.xml` points the default connection at in-memory SQLite, so tests never modify `sqliguardDB`. `tests/TestCase.php` refuses to boot if the default connection is anything else, for example when a cached configuration (`php artisan config:cache`) overrides `phpunit.xml`. Run `php artisan config:clear` if that happens. Tests use `LazilyRefreshDatabase`.
- Authentication and account management (Phase 2):

  | Test | Covers |
  | --- | --- |
  | `tests/Feature/Http/Controllers/Auth/LoginControllerTest.php` | Login form, valid/invalid/inactive login, case-insensitive email, session regeneration, rate limiting, guest-only login page, intended redirect, ignored external redirect, logout session invalidation and CSRF rotation, POST-only logout |
  | `tests/Feature/Http/Middleware/EnsureUserHasRoleTest.php` | Administrator/Student/guest access to every protected page |
  | `tests/Feature/Http/Middleware/EnsureAccountIsActiveTest.php` | Deactivated account's open session is ended |
  | `tests/Feature/Http/Controllers/DashboardControllerTest.php` | Role-specific landing redirect |
  | `tests/Feature/Http/Controllers/Admin/UserControllerTest.php` | Create/edit/activate/deactivate, role assignment, hashing, no hash in views, output escaping, duplicate email, password policy, self-protection and last-administrator rules, Student and guest refusal |
  | `tests/Feature/Policies/UserPolicyTest.php` | Full `UserPolicy` ability matrix per role |
  | `tests/Feature/Console/Commands/CreateAdministratorTest.php` | Create-admin command: success, hashing, duplicates, weak or mismatched passwords, confirmation, non-interactive refusal, password never echoed |

  Run one area with, for example, `php artisan test --compact tests/Feature/Http/Controllers/Admin`.
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
| 2: Authentication and role management | Complete (pending review) | Session login/logout with rate limiting, `Role` enum and `role`/`is_active` columns, role middleware and `UserPolicy`, administrator account management, `sqliguard:create-admin`, role landing pages |
