<?php

namespace Tests\Feature\Demonstrations;

use App\Demonstrations\UnionDemonstration;
use App\Demonstrations\VulnerableQuery;
use App\Demonstrations\VulnerableQueryExecutor;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Isolation and safety guarantees of the vulnerable executor, tested against
 * the real `lab` schema (seeded) through the restricted sqliguard_lab account.
 */
#[Group('lab-database')]
class VulnerableQueryExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(fn (): string => 'local');
    }

    /**
     * Run an injected category through the UNION template.
     *
     * @return array{rows: list<array<string, string|null>>, truncated: bool, executedSql: string, connectionId: int}
     */
    private function runUnion(string $category): array
    {
        $demonstration = new UnionDemonstration;

        return $this->app->make(VulnerableQueryExecutor::class)
            ->execute($demonstration->vulnerableQuery(['category' => $category]), $demonstration->columns());
    }

    public function test_two_complete_statements_are_rejected_by_the_server_side_prepare(): void
    {
        // Closes the wrapper's parenthesis so BOTH statements are valid SQL on
        // their own. Only the native prepare (one statement per prepare) stops it.
        $stacked = "x') AS demo_result(id, name, category, price); SELECT * FROM (SELECT 1 AS id, 2, 3, 4 WHERE 'a'='a";

        try {
            $this->runUnion($stacked);
            $this->fail('A stacked statement was executed.');
        } catch (PDOException $exception) {
            $this->assertSame('42601', $exception->errorInfo[0]);
            $this->assertStringContainsString('cannot insert multiple commands into a prepared statement', $exception->getMessage());
        }
    }

    public function test_stacked_drop_table_payload_fails_and_nothing_is_dropped(): void
    {
        try {
            $this->runUnion("x'; DROP TABLE lab.demo_products; --");
            $this->fail('A stacked statement was executed.');
        } catch (PDOException $exception) {
            $this->assertSame('42601', $exception->errorInfo[0]);
        }

        $this->assertSame(6, DB::connection('lab')->table('lab.demo_products')->count());
    }

    public function test_every_execution_uses_a_new_connection_that_secure_mode_never_shares(): void
    {
        $securePid = (int) DB::connection('lab')->selectOne('SELECT pg_backend_pid() AS pid')->pid;

        $first = $this->runUnion('stationery')['connectionId'];
        $second = $this->runUnion('stationery')['connectionId'];

        $this->assertNotSame($first, $second);
        $this->assertNotContains($securePid, [$first, $second]);
        $this->assertSame($securePid, (int) DB::connection('lab')->selectOne('SELECT pg_backend_pid() AS pid')->pid);
    }

    public function test_session_settings_changed_by_an_injection_do_not_reach_the_next_execution(): void
    {
        $this->runUnion("x' OR set_config('statement_timeout', '0', false) IS NOT NULL OR 'a'='a");

        $probe = $this->runUnion("none' UNION SELECT 0, current_setting('statement_timeout'), current_setting('transaction_read_only'), 0 WHERE 'a'='a");

        $this->assertSame([['id' => '0', 'name' => '5s', 'category' => 'on', 'price' => '0']], $probe['rows']);
    }

    public function test_results_are_capped_at_the_row_limit(): void
    {
        $result = $this->runUnion("none' UNION SELECT g, 'row', 'x', 0 FROM generate_series(1, 100000) AS g WHERE 'a'='a");

        $this->assertCount(50, $result['rows']);
        $this->assertTrue($result['truncated']);
    }

    public function test_long_values_are_truncated_by_the_database_before_transfer(): void
    {
        $result = $this->runUnion("none' UNION SELECT 1, repeat('x', 100000), 'x', 0 WHERE 'a'='a");

        $this->assertSame(200, strlen($result['rows'][0]['name']));
    }

    public function test_execution_is_refused_outside_the_local_environment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        $this->app->make(VulnerableQueryExecutor::class)->execute(
            VulnerableQuery::fromTemplate(UnionDemonstration::TEMPLATE, ['category' => 'stationery']),
            (new UnionDemonstration)->columns(),
        );
    }
}
