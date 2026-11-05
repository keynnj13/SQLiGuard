<?php

namespace Tests\Feature\Demonstrations;

use App\Demonstrations\Comparison;
use App\Demonstrations\DemonstrationRunner;
use App\Demonstrations\ModeResult;
use App\Enums\DemonstrationType;
use Database\Seeders\LabDemoSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Executes the real demonstrations against the seeded `lab` schema.
 *
 * Requires local PostgreSQL with `php artisan migrate` and
 * `php artisan db:seed --class=LabDemoSeeder`. Only the restricted `lab`
 * connection is used; nothing is written.
 */
#[Group('lab-database')]
class DemonstrationRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(fn (): string => 'local');
    }

    /**
     * @param  array<string, string>  $input
     */
    private function compare(DemonstrationType $type, array $input): Comparison
    {
        return $this->app->make(DemonstrationRunner::class)->compare($type, $input);
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     * @return list<string|null>
     */
    private function column(array $rows, string $column): array
    {
        $values = array_column($rows, $column);
        sort($values);

        return $values;
    }

    public function test_tautology_valid_fake_login_matches_one_account_in_both_modes(): void
    {
        $comparison = $this->compare(DemonstrationType::Tautology, ['username' => 'demo.analyst', 'password' => 'Analyst-Lab-2026']);

        $this->assertSame(['demo.analyst'], $this->column($comparison->vulnerable->rows, 'username'));
        $this->assertSame(['demo.analyst'], $this->column($comparison->secure->rows, 'username'));
        $this->assertFalse($comparison->inputAlteredQuery());
    }

    public function test_tautology_wrong_password_matches_nothing_in_both_modes(): void
    {
        $comparison = $this->compare(DemonstrationType::Tautology, ['username' => 'demo.analyst', 'password' => 'not-the-password']);

        $this->assertSame([], $comparison->vulnerable->rows);
        $this->assertSame([], $comparison->secure->rows);
        $this->assertSame('No matching account: the fake login is refused.', $comparison->secure->summary);
    }

    public function test_tautology_in_username_returns_every_account_only_in_vulnerable_mode(): void
    {
        $input = ['username' => "' OR '1'='1", 'password' => 'anything'];

        $comparison = $this->compare(DemonstrationType::Tautology, $input);

        $this->assertSame(['demo.admin', 'demo.analyst', 'demo.guest', 'demo.student'], $this->column($comparison->vulnerable->rows, 'username'));
        $this->assertSame([], $comparison->secure->rows);
        $this->assertTrue($comparison->inputAlteredQuery());
    }

    public function test_tautology_in_password_logs_in_as_the_named_account_without_its_password(): void
    {
        $comparison = $this->compare(DemonstrationType::Tautology, ['username' => 'demo.analyst', 'password' => "x') OR ('1'='1"]);

        $this->assertSame(['demo.analyst'], $this->column($comparison->vulnerable->rows, 'username'));
        $this->assertSame([], $comparison->secure->rows);
    }

    public function test_secure_tautology_query_binds_both_values_and_keeps_its_structure(): void
    {
        $input = ['username' => "' OR '1'='1", 'password' => 'anything'];

        $secure = $this->compare(DemonstrationType::Tautology, $input)->secure;

        $this->assertSame(
            'select "id", "username", "display_name", "account_type" from "lab"."demo_accounts" where lab.demo_password_matches(id, ?) and "username" = ? order by "id" asc limit 50',
            $secure->sql,
        );
        $this->assertSame(['anything', "' OR '1'='1"], $secure->bindings);
        $this->assertStringNotContainsString("'1'='1", $secure->sql);
    }

    public function test_vulnerable_query_contains_the_raw_input_in_the_sql_text(): void
    {
        $vulnerable = $this->compare(DemonstrationType::Tautology, ['username' => "' OR '1'='1", 'password' => 'anything'])->vulnerable;

        $this->assertStringContainsString("AND username = '' OR '1'='1' ORDER BY id", $vulnerable->sql);
        $this->assertSame([], $vulnerable->bindings);
    }

    public function test_no_demonstration_result_contains_a_fake_password_or_digest(): void
    {
        $comparison = $this->compare(DemonstrationType::Tautology, ['username' => "' OR '1'='1", 'password' => 'anything']);

        $values = array_merge(...array_map('array_values', $comparison->vulnerable->rows));
        $this->assertCount(4, $comparison->vulnerable->rows, 'The tautology must return every fake account so all of them are checked.');
        $this->assertNotContains(LabDemoSeeder::PUBLISHED_SAMPLE_PASSWORD, $values);
        $this->assertNotContains(hash('sha256', LabDemoSeeder::PUBLISHED_SAMPLE_PASSWORD), $values);
        // The other accounts' passwords are random and unknown, so check that no
        // value has the shape of a SHA-256 digest (64 hex characters) at all.
        $digestShaped = array_filter($values, fn (?string $value): bool => $value !== null && preg_match('/^[0-9a-f]{64}$/i', $value) === 1);
        $this->assertSame([], array_values($digestShaped), 'A password digest appeared in the demonstration results.');
        $this->assertSame(['id', 'username', 'display_name', 'account_type'], array_keys($comparison->vulnerable->rows[0]));
    }

    public function test_union_attempt_on_the_fake_credentials_table_is_denied(): void
    {
        $comparison = $this->compare(DemonstrationType::Union, [
            'category' => "none' UNION SELECT account_id, password_digest, 'x', 0 FROM lab.demo_account_credentials WHERE 'a'='a",
        ]);

        $this->assertSame(ModeResult::FAILED, $comparison->vulnerable->status);
        $this->assertSame([], $comparison->vulnerable->rows);
        $this->assertStringStartsWith('Permission denied', $comparison->vulnerable->message);
    }

    public function test_union_normal_category_returns_only_published_products_in_both_modes(): void
    {
        $comparison = $this->compare(DemonstrationType::Union, ['category' => 'stationery']);

        $expected = ['Demo Desk Organizer', 'Demo Gel Pen Set', 'Demo Notebook A5'];
        $this->assertSame($expected, $this->column($comparison->vulnerable->rows, 'name'));
        $this->assertSame($expected, $this->column($comparison->secure->rows, 'name'));
        $this->assertFalse($comparison->inputAlteredQuery());
    }

    public function test_union_payload_adds_fake_internal_projects_only_in_vulnerable_mode(): void
    {
        $input = ['category' => "none' UNION SELECT id, codename, classification, budget FROM lab.demo_internal_projects WHERE 'a'='a"];

        $comparison = $this->compare(DemonstrationType::Union, $input);

        $this->assertSame(
            ['FAKE Project Kestrel', 'FAKE Project Lantern', 'FAKE Project Meridian'],
            $this->column($comparison->vulnerable->rows, 'name'),
        );
        $this->assertSame([], $comparison->secure->rows);
        $this->assertSame([true, $input['category']], $comparison->secure->bindings);
        $this->assertStringNotContainsString('UNION', $comparison->secure->sql);
    }

    public function test_union_against_application_users_is_refused_by_the_database(): void
    {
        $comparison = $this->compare(DemonstrationType::Union, [
            'category' => "none' UNION SELECT id, name, email, 0 FROM public.users WHERE 'a'='a",
        ]);

        $this->assertSame(ModeResult::FAILED, $comparison->vulnerable->status);
        $this->assertSame([], $comparison->vulnerable->rows);
        $this->assertSame(
            'Permission denied: the restricted lab account is not allowed to read that object. Least privilege stopped this query.',
            $comparison->vulnerable->message,
        );
        $this->assertSame([], $comparison->secure->rows);
    }

    public function test_comment_normal_team_returns_only_public_documents_in_both_modes(): void
    {
        $comparison = $this->compare(DemonstrationType::Comment, ['team' => 'team-alpha']);

        $this->assertSame(['public'], $this->column($comparison->vulnerable->rows, 'visibility'));
        $this->assertSame(['public'], $this->column($comparison->secure->rows, 'visibility'));
    }

    public function test_comment_payload_reveals_private_documents_only_in_vulnerable_mode(): void
    {
        $comparison = $this->compare(DemonstrationType::Comment, ['team' => "team-alpha' --"]);

        $this->assertSame(['1', '2', '3'], $this->column($comparison->vulnerable->rows, 'id'));
        $this->assertSame(['private', 'private', 'public'], $this->column($comparison->vulnerable->rows, 'visibility'));
        $this->assertSame([], $comparison->secure->rows);
        $this->assertTrue($comparison->inputAlteredQuery());
    }

    public function test_secure_comment_query_keeps_the_visibility_condition(): void
    {
        $secure = $this->compare(DemonstrationType::Comment, ['team' => "team-alpha' --"])->secure;

        $this->assertSame(
            'select "id", "title", "owner_team", "visibility" from "lab"."demo_documents" where "owner_team" = ? and "visibility" = ? order by "id" asc limit 50',
            $secure->sql,
        );
        $this->assertSame(["team-alpha' --", 'public'], $secure->bindings);
    }

    /**
     * @return array<string, array{DemonstrationType, array<string, string>}>
     */
    public static function attackInputs(): array
    {
        return [
            'tautology' => [DemonstrationType::Tautology, ['username' => "' OR '1'='1", 'password' => 'x']],
            'union' => [DemonstrationType::Union, ['category' => "none' UNION SELECT id, codename, classification, budget FROM lab.demo_internal_projects WHERE 'a'='a"]],
            'comment' => [DemonstrationType::Comment, ['team' => "team-alpha' --"]],
        ];
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('attackInputs')]
    public function test_vulnerable_mode_is_refused_outside_the_local_environment_while_secure_mode_runs(DemonstrationType $type, array $input): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $comparison = $this->compare($type, $input);

        $this->assertSame(ModeResult::REFUSED, $comparison->vulnerable->status);
        $this->assertSame([], $comparison->vulnerable->rows);
        $this->assertNull($comparison->vulnerable->connectionId);
        $this->assertSame(ModeResult::EXECUTED, $comparison->secure->status);
        $this->assertNull($comparison->inputAlteredQuery());
    }

    public function test_database_error_messages_never_contain_internal_details(): void
    {
        $comparison = $this->compare(DemonstrationType::Union, ['category' => "x' AND no_such_column = 1 AND 'a'='a"]);

        $message = $comparison->vulnerable->message;
        $this->assertSame(ModeResult::FAILED, $comparison->vulnerable->status);
        foreach (['SQLSTATE', 'sqliguard', 'Host', '127.0.0.1', '5432', 'no_such_column'] as $internal) {
            $this->assertStringNotContainsString($internal, $message);
        }
    }
}
