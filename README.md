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
php artisan sqliguard:create-admin   # first administrator (prompts; password input is hidden)
npm run build               # or `npm run dev` while developing
php artisan serve --host=127.0.0.1
```

Then open `http://127.0.0.1:8000/login`.

## Accounts and Roles

There is no public registration. The first **Administrator** is created with `php artisan sqliguard:create-admin`. Administrators then create, edit, activate and deactivate Administrator and **Student/Tester** accounts under **Accounts** in the application. See [docs/architecture.md, section 5](docs/architecture.md#5-roles-and-feature-boundaries) for the authentication flow and account rules.

## Tests

```sh
php artisan test --compact
php artisan test --compact --exclude-group lab-database   # without PostgreSQL
php artisan test --compact tests/Feature/Http             # authentication, roles, account management
```

Tests run on in-memory SQLite and refuse to start if the configuration is cached. Run `php artisan config:clear` first if you have run `config:cache`.
