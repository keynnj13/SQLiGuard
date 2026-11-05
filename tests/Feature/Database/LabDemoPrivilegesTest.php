<?php

namespace Tests\Feature\Database;

use Database\Seeders\LabDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Permission boundary of the Phase 3 demonstration objects in the `lab`
 * schema, checked through the restricted `lab` connection (read-only checks
 * plus write attempts that the database must refuse).
 */
#[Group('lab-database')]
class LabDemoPrivilegesTest extends TestCase
{
    private const array LAB_READABLE_TABLES = ['demo_accounts', 'demo_products', 'demo_internal_projects', 'demo_documents'];

    /**
     * @return array<string, array{string}>
     */
    public static function readableTables(): array
    {
        return array_combine(self::LAB_READABLE_TABLES, array_map(fn (string $table): array => [$table], self::LAB_READABLE_TABLES));
    }

    private function hasTablePrivilege(string $role, string $table, string $privilege): bool
    {
        return (bool) DB::connection('lab')->selectOne(
            'select has_table_privilege(?, ?, ?) as granted',
            [$role, 'lab.'.$table, $privilege],
        )->granted;
    }

    public function test_connections_use_their_designated_accounts(): void
    {
        $this->assertSame('sqliguard_lab', DB::connection('lab')->selectOne('select current_user as name')->name);
        $this->assertSame('sqliguard_app', config('database.connections.pgsql.username'));
    }

    #[DataProvider('readableTables')]
    public function test_lab_account_can_read_granted_demo_table(string $table): void
    {
        $rows = DB::connection('lab')->table('lab.'.$table)->count();

        $this->assertGreaterThan(0, $rows, "lab.{$table} must be readable and seeded (php artisan db:seed --class=LabDemoSeeder).");
    }

    #[DataProvider('readableTables')]
    public function test_lab_account_cannot_write_to_demo_table(string $table): void
    {
        $granted = array_values(array_filter(
            ['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'],
            fn (string $privilege): bool => $this->hasTablePrivilege('sqliguard_lab', $table, $privilege),
        ));

        $this->assertSame([], $granted);
    }

    public function test_insert_into_a_demo_table_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('permission denied');

        DB::connection('lab')->table('lab.demo_products')->insert([
            'id' => 999, 'name' => 'Injected', 'category' => 'x', 'price' => 1, 'is_published' => true,
        ]);
    }

    public function test_lab_account_can_read_exactly_the_explicitly_granted_tables(): void
    {
        $readable = array_column(DB::connection('lab')->select(
            "select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = 'lab' and c.relkind in ('r', 'v', 'm', 'p')
               and has_table_privilege(current_user, c.oid, 'SELECT')
             order by c.relname",
        ), 'relname');

        $this->assertSame(['demo_accounts', 'demo_documents', 'demo_internal_projects', 'demo_products'], $readable);
    }

    public function test_lab_account_cannot_read_the_fake_credentials(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('permission denied');

        DB::connection('lab')->table('lab.demo_account_credentials')->get();
    }

    public function test_application_account_owns_and_manages_every_demo_table(): void
    {
        foreach ([...self::LAB_READABLE_TABLES, 'demo_account_credentials'] as $table) {
            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
                $this->assertTrue($this->hasTablePrivilege('sqliguard_app', $table, $privilege), "sqliguard_app lacks {$privilege} on lab.{$table}");
            }
        }
    }

    public function test_password_check_function_is_narrowly_executable_and_hardened(): void
    {
        $function = DB::connection('lab')->selectOne(
            "select p.prosecdef, p.proconfig::text as config, pg_get_userbyid(p.proowner) as owner,
                    has_function_privilege('sqliguard_lab', p.oid, 'EXECUTE') as lab_can_execute,
                    coalesce(p.proacl::text, '') as acl
             from pg_proc p join pg_namespace n on n.oid = p.pronamespace
             where n.nspname = 'lab' and p.proname = 'demo_password_matches'",
        );

        $this->assertSame('sqliguard_app', $function->owner);
        $this->assertTrue((bool) $function->prosecdef);
        $this->assertSame('{"search_path=pg_catalog, pg_temp"}', $function->config);
        $this->assertTrue((bool) $function->lab_can_execute);
        $this->assertStringNotContainsString('=X/', str_replace(['sqliguard_app=X/', 'sqliguard_lab=X/'], '', $function->acl), 'PUBLIC must not have EXECUTE.');
    }

    public function test_password_check_function_returns_only_a_boolean(): void
    {
        $lab = DB::connection('lab');

        $this->assertTrue((bool) $lab->selectOne('select lab.demo_password_matches(2, ?) as ok', ['Analyst-Lab-2026'])->ok);
        $this->assertFalse((bool) $lab->selectOne('select lab.demo_password_matches(2, ?) as ok', ['wrong'])->ok);
    }

    public function test_password_check_function_returns_a_native_php_boolean_without_casting(): void
    {
        $lab = DB::connection('lab');

        $match = $lab->selectOne('select lab.demo_password_matches(2, ?) as ok', [LabDemoSeeder::PUBLISHED_SAMPLE_PASSWORD])->ok;
        $mismatch = $lab->selectOne('select lab.demo_password_matches(2, ?) as ok', ['wrong'])->ok;
        $unknownAccount = $lab->selectOne('select lab.demo_password_matches(999, ?) as ok', [LabDemoSeeder::PUBLISHED_SAMPLE_PASSWORD])->ok;

        $this->assertIsBool($match);
        $this->assertTrue($match);
        $this->assertIsBool($mismatch);
        $this->assertFalse($mismatch);
        $this->assertIsBool($unknownAccount);
        $this->assertFalse($unknownAccount);
    }

    public function test_password_check_function_has_the_exact_declared_definition(): void
    {
        $function = DB::connection('lab')->selectOne(
            "select n.nspname || '.' || p.proname || '(' || oidvectortypes(p.proargtypes) || ')' as signature,
                    pg_get_function_identity_arguments(p.oid) as arguments,
                    pg_get_function_result(p.oid) as result,
                    p.prorettype::regtype::text as return_type,
                    p.proretset as returns_set,
                    p.prokind as kind,
                    l.lanname as language,
                    pg_get_userbyid(p.proowner) as owner,
                    p.prosecdef as security_definer,
                    p.provolatile as volatility,
                    p.proisstrict as strict,
                    p.proconfig::text as config
             from pg_proc p
             join pg_namespace n on n.oid = p.pronamespace
             join pg_language l on l.oid = p.prolang
             where n.nspname = 'lab' and p.proname = 'demo_password_matches'",
        );

        $this->assertSame('lab.demo_password_matches(bigint, text)', $function->signature);
        $this->assertSame('p_account_id bigint, p_password text', $function->arguments);
        $this->assertSame('boolean', $function->result);
        $this->assertSame('boolean', $function->return_type);
        $this->assertFalse($function->returns_set);
        $this->assertSame('f', $function->kind);
        $this->assertSame('sql', $function->language);
        $this->assertSame('sqliguard_app', $function->owner);
        $this->assertTrue($function->security_definer);
        $this->assertSame('s', $function->volatility, 'The function must be STABLE.');
        $this->assertTrue($function->strict);
        $this->assertSame('{"search_path=pg_catalog, pg_temp"}', $function->config);
    }

    public function test_password_check_function_execute_privileges_are_exact(): void
    {
        $grants = DB::connection('lab')->select(
            "select coalesce(nullif(a.grantee, 0)::regrole::text, 'PUBLIC') as grantee,
                    a.privilege_type, a.is_grantable
             from pg_proc p
             join pg_namespace n on n.oid = p.pronamespace,
             aclexplode(p.proacl) a
             where n.nspname = 'lab' and p.proname = 'demo_password_matches'
             order by 1",
        );

        $this->assertSame([
            ['grantee' => 'sqliguard_app', 'privilege_type' => 'EXECUTE', 'is_grantable' => false],
            ['grantee' => 'sqliguard_lab', 'privilege_type' => 'EXECUTE', 'is_grantable' => false],
        ], array_map(fn (object $grant): array => (array) $grant, $grants));
    }

    public function test_lab_account_can_execute_only_the_intended_function_in_the_lab_schema(): void
    {
        $executable = DB::connection('lab')->select(
            "select n.nspname || '.' || p.proname || '(' || oidvectortypes(p.proargtypes) || ')' as signature
             from pg_proc p join pg_namespace n on n.oid = p.pronamespace
             where n.nspname = 'lab' and has_function_privilege(current_user, p.oid, 'EXECUTE')",
        );

        $this->assertSame(['lab.demo_password_matches(bigint, text)'], array_column($executable, 'signature'));
    }

    public function test_lab_schema_contains_no_other_functions_or_procedures(): void
    {
        $routines = DB::connection('lab')->select(
            "select n.nspname || '.' || p.proname || '(' || oidvectortypes(p.proargtypes) || ')' as signature
             from pg_proc p join pg_namespace n on n.oid = p.pronamespace
             where n.nspname = 'lab'
             order by 1",
        );

        $this->assertSame(['lab.demo_password_matches(bigint, text)'], array_column($routines, 'signature'));
    }

    public function test_fake_accounts_other_than_the_published_sample_do_not_use_guessable_passwords(): void
    {
        $lab = DB::connection('lab');
        $guessable = [
            LabDemoSeeder::PUBLISHED_SAMPLE_PASSWORD,
            'Admin-Lab-2026!', 'Admin-Lab-2026', 'Student-Lab-2026', 'Guest-Lab-2026',
            'password', '',
        ];

        $accepted = [];
        foreach ([1, 3, 4] as $accountId) {
            foreach ($guessable as $guess) {
                if ($lab->selectOne('select lab.demo_password_matches(?, ?) as ok', [$accountId, $guess])->ok === true) {
                    $accepted[] = "account {$accountId}";
                }
            }
        }

        $this->assertSame([], $accepted, 'A fake account other than the published sample accepted a guessable password. Re-run LabDemoSeeder.');
    }

    public function test_no_default_privileges_would_expose_future_tables(): void
    {
        $this->assertSame([], DB::connection('lab')->select('select 1 from pg_default_acl'));
    }

    public function test_lab_account_cannot_create_a_table_in_the_lab_schema(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('permission denied for schema lab');

        DB::connection('lab')->statement('create table lab.phase3_probe (id int)');
    }
}
