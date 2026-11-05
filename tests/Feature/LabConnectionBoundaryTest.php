<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Verifies the PostgreSQL permission boundary of the restricted `lab` account.
 *
 * These tests connect to the real database through the `lab` connection only.
 * They run read-only checks, plus one temporary-table creation that is expected
 * to be refused. They never use the application connection, so application
 * tables are never modified. Exclude them with `--exclude-group lab-database`
 * when PostgreSQL is not available. Expected state: database/provisioning/lab-hardening.sql.
 */
#[Group('lab-database')]
class LabConnectionBoundaryTest extends TestCase
{
    private const array FORBIDDEN_TABLE_PRIVILEGES = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER',
    ];

    public function test_lab_connection_uses_a_separate_restricted_account(): void
    {
        $labUser = DB::connection('lab')->selectOne('select current_user as name')->name;

        $this->assertSame(config('database.connections.lab.username'), $labUser);
        $this->assertNotSame(config('database.connections.pgsql.username'), $labUser);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function applicationTables(): array
    {
        $tables = [
            'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
            'jobs', 'job_batches', 'failed_jobs', 'migrations',
        ];

        return array_combine($tables, array_map(fn (string $table): array => [$table], $tables));
    }

    #[DataProvider('applicationTables')]
    public function test_lab_account_has_no_privileges_on_application_table(string $table): void
    {
        $lab = DB::connection('lab');

        // Look the table up by OID: the lab account has no USAGE on `public`,
        // so it cannot resolve the name `public.<table>` directly.
        $oid = $lab->selectOne(
            "select c.oid from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relname = ?",
            [$table],
        )?->oid;

        $this->assertNotNull($oid, "Application table [{$table}] must exist.");

        $granted = array_values(array_filter(
            self::FORBIDDEN_TABLE_PRIVILEGES,
            fn (string $privilege): bool => (bool) $lab->selectOne(
                'select has_table_privilege(current_user, ?::oid, ?) as granted',
                [$oid, $privilege],
            )->granted,
        ));

        $this->assertSame([], $granted, "The lab account must not hold privileges on [{$table}].");
    }

    public function test_lab_account_is_denied_when_reading_user_accounts(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('permission denied');

        DB::connection('lab')->select('select * from public.users limit 1');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function schemas(): array
    {
        return [
            'public' => ['public'],
            'lab' => ['lab'],
        ];
    }

    #[DataProvider('schemas')]
    public function test_lab_account_cannot_create_objects_in_schema(string $schema): void
    {
        $canCreate = DB::connection('lab')->selectOne(
            'select has_schema_privilege(current_user, ?, ?) as granted',
            [$schema, 'CREATE'],
        )->granted;

        $this->assertFalse((bool) $canCreate, "The lab account must not create objects in [{$schema}].");
    }

    public function test_lab_account_can_use_the_lab_schema_but_not_the_public_schema(): void
    {
        $usage = DB::connection('lab')->selectOne(
            "select has_schema_privilege(current_user, 'lab', 'USAGE') as lab, has_schema_privilege(current_user, 'public', 'USAGE') as public",
        );

        $this->assertTrue((bool) $usage->lab);
        $this->assertFalse((bool) $usage->public);
    }

    public function test_lab_schema_is_owned_by_the_application_account(): void
    {
        $schema = DB::connection('lab')->selectOne(
            "select nspowner::regrole::text as owner, has_schema_privilege(nspowner, oid, 'CREATE') as owner_can_create from pg_namespace where nspname = 'lab'",
        );

        $this->assertNotNull($schema, 'The [lab] schema must exist.');
        $this->assertSame(config('database.connections.pgsql.username'), $schema->owner);
        $this->assertTrue((bool) $schema->owner_can_create);
    }

    public function test_lab_connection_searches_only_the_lab_schema(): void
    {
        $searchPath = DB::connection('lab')->selectOne('show search_path')->search_path;

        $this->assertSame('lab', trim($searchPath, '"'));
    }

    public function test_lab_account_cannot_create_temporary_tables(): void
    {
        $lab = DB::connection('lab');

        $this->assertFalse((bool) $lab->selectOne(
            "select has_database_privilege(current_user, current_database(), 'TEMP') as granted",
        )->granted);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('permission denied');

        $lab->statement('create temporary table sqliguard_temp_probe (id integer)');
    }

    public function test_lab_account_statements_time_out_after_five_seconds(): void
    {
        $timeout = DB::connection('lab')->selectOne('show statement_timeout')->statement_timeout;

        $this->assertSame('5s', $timeout);
    }

    public function test_lab_account_has_no_elevated_role_attributes_or_memberships(): void
    {
        $lab = DB::connection('lab');

        $role = $lab->selectOne(
            'select rolsuper, rolcreatedb, rolcreaterole, rolbypassrls, rolreplication from pg_roles where rolname = current_user',
        );

        $this->assertFalse((bool) $role->rolsuper);
        $this->assertFalse((bool) $role->rolcreatedb);
        $this->assertFalse((bool) $role->rolcreaterole);
        $this->assertFalse((bool) $role->rolbypassrls);
        $this->assertFalse((bool) $role->rolreplication);

        $memberships = $lab->select(
            'select roleid::regrole::text as role from pg_auth_members where member = (select oid from pg_roles where rolname = current_user)',
        );

        $this->assertSame([], $memberships, 'The lab account must not inherit privileges from other roles.');
    }
}
