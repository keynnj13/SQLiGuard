# SQLiGuard

**A Web Input Security Demonstrator Comparing Vulnerable and Parameterized Queries Against SQL Injection in a Controlled Lab Environment**

SQLiGuard runs the same input through a deliberately vulnerable query and a parameterized query side by side, so students can see how SQL injection changes a query's structure and how parameterization prevents it.

> **Warning:** SQLiGuard intentionally contains vulnerable code. Run it only on a local machine in a controlled lab, and never deploy it to a public or production server.

## Stack

Laravel 13 · PHP 8.3+ · PostgreSQL 18 · Blade · Bootstrap 5 · Chart.js · Vite

## Documentation

- [Architecture, database boundaries and roles](docs/architecture.md)
- [Project rules for contributors and AI agents](CLAUDE.md)

## Local Setup

Prerequisites: PHP 8.3+ with `pdo_pgsql`, Composer, Node.js, and PostgreSQL with the `sqliguardDB` database and the `sqliguard_app` / `sqliguard_lab` accounts already provisioned.

```sh
composer install
npm install
cp .env.example .env        # then fill in DB_PASSWORD and LAB_DB_PASSWORD
php artisan key:generate
# once, as the PostgreSQL superuser: lab hardening and `lab` schema
psql -U postgres -h 127.0.0.1 -d sqliguardDB -f database/provisioning/lab-hardening.sql
php artisan migrate
php artisan db:seed --class=LabDemoSeeder   # fictitious lab data (safe to re-run)
php artisan sqliguard:create-admin   # first administrator (prompts; password input is hidden)
npm run build               # or `npm run dev` while developing
php artisan serve --host=127.0.0.1
```

Then open `http://127.0.0.1:8000/login`.

## Accounts and Roles

There is no public registration. The first **Administrator** is created with `php artisan sqliguard:create-admin`. Administrators then create, edit, activate and deactivate Administrator and **Student/Tester** accounts under **Accounts** in the application. See [docs/architecture.md, section 5](docs/architecture.md#5-roles-and-feature-boundaries) for the authentication flow and account rules.

## SQL Injection Demonstrations

Administrators and Students/Testers open **Demonstrations** to run three comparisons:
- tautology-based injection against a fake login
- UNION-based injection against a fake catalogue
- comment-based injection against fake documents

Each comparison submits one input and shows **Vulnerable Mode** (input concatenated into the SQL) and **Secure Mode** (bound parameters) side by side. The panels show the SQL, parameters, results and an explanation.

- Vulnerable Mode runs only when `APP_ENV=local`. It uses the restricted `sqliguard_lab` account on a fresh, read-only, single-statement connection.
- Only fictitious data in the `lab` schema is ever queried.

See [docs/architecture.md, section 9](docs/architecture.md#9-sql-injection-demonstrations).

## Tests

```sh
php artisan test --compact
php artisan test --compact --exclude-group lab-database   # without PostgreSQL
php artisan test --compact --group lab-database           # PostgreSQL lab integration tests only
php artisan test --compact tests/Feature/Http             # authentication, roles, account management, demonstration HTTP
```

The `lab-database` tests query the real `lab` schema through the restricted lab account. Run `php artisan migrate` and `php artisan db:seed --class=LabDemoSeeder` first.

Tests run on in-memory SQLite and refuse to start if the configuration is cached. Run `php artisan config:clear` first if you have run `config:cache`.
