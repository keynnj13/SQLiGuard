<?php

namespace Tests\Feature\Database;

use App\Demonstrations\Classification\Classification;
use App\Demonstrations\Classification\PayloadClassifier;
use App\Demonstrations\Comparison;
use App\Demonstrations\ComparisonRecorder;
use App\Demonstrations\DemonstrationRunner;
use App\Demonstrations\ModeResult;
use App\Enums\DemonstrationErrorCategory;
use App\Enums\DemonstrationType;
use App\Models\ComparisonRecord;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Verifies `public.comparison_records` on the real PostgreSQL database
 * (requires `php artisan migrate`).
 *
 * Read-only, through the restricted `lab` connection only: the system
 * catalogs are readable by every role, and tests never connect to the
 * application database. Nothing is inserted. To check the CHECK constraints'
 * behavior, PostgreSQL evaluates each installed constraint expression
 * (taken from pg_constraint, never from input) against sample rows whose
 * values are bound as parameters. A row violates a constraint when its
 * expression is false; NULL passes, exactly as in a CHECK constraint.
 */
#[Group('lab-database')]
class ComparisonRecordsSchemaTest extends TestCase
{
    private const array CHECK_NAMES = [
        'comparison_records_classification_category_check',
        'comparison_records_classification_rules_check',
        'comparison_records_classifier_version_check',
        'comparison_records_demonstration_type_check',
        'comparison_records_results_differ_check',
        'comparison_records_secure_duration_check',
        'comparison_records_secure_error_category_check',
        'comparison_records_secure_error_status_check',
        'comparison_records_secure_row_count_range_check',
        'comparison_records_secure_row_count_status_check',
        'comparison_records_secure_status_check',
        'comparison_records_vulnerable_duration_check',
        'comparison_records_vulnerable_duration_status_check',
        'comparison_records_vulnerable_error_category_check',
        'comparison_records_vulnerable_error_status_check',
        'comparison_records_vulnerable_row_count_range_check',
        'comparison_records_vulnerable_row_count_status_check',
        'comparison_records_vulnerable_status_check',
        'comparison_records_vulnerable_truncated_check',
    ];

    /**
     * A valid record: normal input, both modes executed with the same rows.
     */
    private const array VALID_ROW = [
        'id' => 1,
        'user_id' => 1,
        'demonstration_type' => 'union',
        'classification_category' => 'normal',
        'classification_rules' => '{}',
        'classifier_version' => 1,
        'vulnerable_status' => 'executed',
        'vulnerable_row_count' => 2,
        'vulnerable_truncated' => false,
        'vulnerable_error_category' => null,
        'vulnerable_duration_us' => 12_000,
        'secure_status' => 'executed',
        'secure_row_count' => 2,
        'secure_error_category' => null,
        'secure_duration_us' => 1_500,
        'results_differ' => false,
        'created_at' => '2026-11-24 09:00:00+00:00',
    ];

    private function tableOid(): int
    {
        $oid = DB::connection('lab')->selectOne(
            "select c.oid from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relname = 'comparison_records' and c.relkind = 'r'",
        )?->oid;

        $this->assertNotNull($oid, 'public.comparison_records must exist. Run `php artisan migrate`.');

        return (int) $oid;
    }

    /**
     * @return array<string, array{type: string, notNull: bool}>
     */
    private function columns(): array
    {
        $columns = DB::connection('lab')->select(
            'select attname as name, format_type(atttypid, atttypmod) as type, attnotnull as not_null from pg_attribute where attrelid = ?::oid and attnum > 0 and not attisdropped order by attnum',
            [$this->tableOid()],
        );

        return collect($columns)->mapWithKeys(fn (object $column): array => [$column->name => ['type' => $column->type, 'notNull' => (bool) $column->not_null]])->all();
    }

    /**
     * Names of the installed CHECK constraints that the row violates.
     *
     * @param  array<string, mixed>  $row  column => value
     * @return list<string>
     */
    private function violatedChecks(array $row): array
    {
        $lab = DB::connection('lab');
        $checks = $lab->select(
            "select conname as name, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = ?::oid and contype = 'c' order by conname",
            [$this->tableOid()],
        );

        $columns = $this->columns();
        $source = implode(', ', array_map(fn (string $name, array $column): string => "CAST(? AS {$column['type']}) AS {$name}", array_keys($columns), $columns));
        $bindings = array_map(fn (string $name): mixed => is_bool($row[$name] ?? null) ? ($row[$name] ? 'true' : 'false') : ($row[$name] ?? null), array_keys($columns));
        $expressions = implode(', ', array_map(
            fn (object $check, int $index): string => preg_replace('/^CHECK \((.*)\)$/s', '($1)', $check->definition)." AS check_{$index}",
            $checks,
            array_keys($checks),
        ));

        $result = (array) $lab->selectOne("select {$expressions} from (select {$source}) as comparison_records", $bindings);

        $violated = [];
        foreach ($checks as $index => $check) {
            if ($result["check_{$index}"] === false) {
                $violated[] = $check->name;
            }
        }

        return $violated;
    }

    public function test_columns_have_the_designed_types_and_nullability(): void
    {
        $this->assertSame([
            'id' => ['type' => 'bigint', 'notNull' => true],
            'user_id' => ['type' => 'bigint', 'notNull' => true],
            'demonstration_type' => ['type' => 'character varying(20)', 'notNull' => true],
            'classification_category' => ['type' => 'character varying(20)', 'notNull' => true],
            'classification_rules' => ['type' => 'jsonb', 'notNull' => true],
            'classifier_version' => ['type' => 'smallint', 'notNull' => true],
            'vulnerable_status' => ['type' => 'character varying(10)', 'notNull' => true],
            'vulnerable_row_count' => ['type' => 'smallint', 'notNull' => false],
            'vulnerable_truncated' => ['type' => 'boolean', 'notNull' => true],
            'vulnerable_error_category' => ['type' => 'character varying(30)', 'notNull' => false],
            'vulnerable_duration_us' => ['type' => 'integer', 'notNull' => false],
            'secure_status' => ['type' => 'character varying(10)', 'notNull' => true],
            'secure_row_count' => ['type' => 'smallint', 'notNull' => false],
            'secure_error_category' => ['type' => 'character varying(30)', 'notNull' => false],
            'secure_duration_us' => ['type' => 'integer', 'notNull' => true],
            'results_differ' => ['type' => 'boolean', 'notNull' => false],
            'created_at' => ['type' => 'timestamp(0) with time zone', 'notNull' => true],
        ], $this->columns());

        $createdAtDefault = DB::connection('lab')->selectOne(
            "select pg_get_expr(d.adbin, d.adrelid) as expression from pg_attrdef d join pg_attribute a on a.attrelid = d.adrelid and a.attnum = d.adnum where d.adrelid = ?::oid and a.attname = 'created_at'",
            [$this->tableOid()],
        )?->expression;
        $this->assertSame('CURRENT_TIMESTAMP', $createdAtDefault);
    }

    public function test_user_id_references_users_with_on_delete_restrict(): void
    {
        $foreignKeys = DB::connection('lab')->select(
            "select pg_get_constraintdef(con.oid) as definition, con.confdeltype as on_delete, ref.relname as referenced_table, refns.nspname as referenced_schema
             from pg_constraint con join pg_class ref on ref.oid = con.confrelid join pg_namespace refns on refns.oid = ref.relnamespace
             where con.conrelid = ?::oid and con.contype = 'f'",
            [$this->tableOid()],
        );

        $this->assertCount(1, $foreignKeys);
        $this->assertSame('public', $foreignKeys[0]->referenced_schema);
        $this->assertSame('users', $foreignKeys[0]->referenced_table);
        $this->assertSame('r', $foreignKeys[0]->on_delete, 'ON DELETE must be RESTRICT, never CASCADE or SET NULL.');
        $this->assertStringStartsWith('FOREIGN KEY (user_id) REFERENCES ', $foreignKeys[0]->definition);
        $this->assertStringEndsWith('users(id) ON DELETE RESTRICT', $foreignKeys[0]->definition);
    }

    public function test_history_indexes_exist(): void
    {
        $indexes = collect(DB::connection('lab')->select(
            'select i.relname as name, pg_get_indexdef(x.indexrelid) as definition from pg_index x join pg_class i on i.oid = x.indexrelid where x.indrelid = ?::oid',
            [$this->tableOid()],
        ))->pluck('definition', 'name');

        $this->assertSame(['comparison_records_history_index', 'comparison_records_pkey', 'comparison_records_user_history_index'], $indexes->keys()->sort()->values()->all());
        $this->assertStringEndsWith('USING btree (user_id, created_at, id)', $indexes['comparison_records_user_history_index']);
        $this->assertStringEndsWith('USING btree (created_at, id)', $indexes['comparison_records_history_index']);
    }

    public function test_exactly_the_designed_check_constraints_are_installed(): void
    {
        $names = DB::connection('lab')->select(
            "select conname from pg_constraint where conrelid = ?::oid and contype = 'c' order by conname",
            [$this->tableOid()],
        );

        $this->assertSame(self::CHECK_NAMES, array_column($names, 'conname'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function validRows(): array
    {
        return [
            'normal' => [[]],
            'executed with 50 rows and truncated' => [['vulnerable_row_count' => 50, 'vulnerable_truncated' => true, 'results_differ' => true]],
            'failed and not truncated' => [['vulnerable_status' => 'failed', 'vulnerable_row_count' => null, 'vulnerable_truncated' => false, 'vulnerable_error_category' => 'syntax_error', 'results_differ' => true]],
            'refused and not truncated' => [['vulnerable_status' => 'refused', 'vulnerable_row_count' => null, 'vulnerable_truncated' => false, 'vulnerable_duration_us' => null, 'results_differ' => null]],
            'vulnerable failed' => [['vulnerable_status' => 'failed', 'vulnerable_row_count' => null, 'vulnerable_error_category' => 'syntax_error', 'results_differ' => true]],
            'secure failed' => [['secure_status' => 'failed', 'secure_row_count' => null, 'secure_error_category' => 'unexpected', 'results_differ' => null]],
            'both failed' => [['vulnerable_status' => 'failed', 'vulnerable_row_count' => null, 'vulnerable_error_category' => 'permission_denied', 'secure_status' => 'failed', 'secure_row_count' => null, 'secure_error_category' => 'unexpected', 'results_differ' => true]],
            'vulnerable refused' => [['vulnerable_status' => 'refused', 'vulnerable_row_count' => null, 'vulnerable_duration_us' => null, 'results_differ' => null]],
            'rules object' => [['classification_category' => 'comment', 'classification_rules' => '{"team": ["comment.line", "signal.quote"]}', 'results_differ' => true, 'secure_row_count' => 0]],
            'zero durations' => [['vulnerable_duration_us' => 0, 'secure_duration_us' => 0]],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('validRows')]
    public function test_valid_rows_satisfy_every_constraint(array $changes): void
    {
        $this->assertSame([], $this->violatedChecks([...self::VALID_ROW, ...$changes]));
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function invalidRows(): array
    {
        $failedVulnerable = ['vulnerable_status' => 'failed', 'vulnerable_row_count' => null, 'vulnerable_error_category' => 'syntax_error', 'results_differ' => true];
        $failedSecure = ['secure_status' => 'failed', 'secure_row_count' => null, 'secure_error_category' => 'unexpected', 'results_differ' => null];
        $refused = ['vulnerable_status' => 'refused', 'vulnerable_row_count' => null, 'vulnerable_duration_us' => null, 'results_differ' => null];

        return [
            'unknown demonstration type' => [['demonstration_type' => 'stacked'], ['comparison_records_demonstration_type_check']],
            'unknown classification' => [['classification_category' => 'sqli'], ['comparison_records_classification_category_check']],
            'rules as a JSON array' => [['classification_rules' => '[]'], ['comparison_records_classification_rules_check']],
            'rules as a JSON string' => [['classification_rules' => '"comment.line"'], ['comparison_records_classification_rules_check']],
            'classifier version 0' => [['classifier_version' => 0], ['comparison_records_classifier_version_check']],
            'unknown vulnerable status' => [['vulnerable_status' => 'skipped'], ['comparison_records_vulnerable_row_count_status_check', 'comparison_records_vulnerable_status_check']],
            'refused secure status' => [['secure_status' => 'refused'], ['comparison_records_results_differ_check', 'comparison_records_secure_row_count_status_check', 'comparison_records_secure_status_check']],
            'unknown vulnerable error category' => [[...$failedVulnerable, 'vulnerable_error_category' => 'sqlstate_42601'], ['comparison_records_vulnerable_error_category_check']],
            'unknown secure error category' => [[...$failedSecure, 'secure_error_category' => '42P01'], ['comparison_records_secure_error_category_check']],
            'vulnerable row count 51' => [['vulnerable_row_count' => 51], ['comparison_records_vulnerable_row_count_range_check']],
            'negative secure row count' => [['secure_row_count' => -1], ['comparison_records_secure_row_count_range_check']],
            'negative vulnerable duration' => [['vulnerable_duration_us' => -1], ['comparison_records_vulnerable_duration_check']],
            'negative secure duration' => [['secure_duration_us' => -5], ['comparison_records_secure_duration_check']],
            'executed without a row count' => [['vulnerable_row_count' => null], ['comparison_records_vulnerable_row_count_status_check']],
            'failed with a row count' => [[...$failedVulnerable, 'vulnerable_row_count' => 2], ['comparison_records_vulnerable_row_count_status_check']],
            'secure failed with a row count' => [[...$failedSecure, 'secure_row_count' => 0], ['comparison_records_secure_row_count_status_check']],
            'executed with an error category' => [['vulnerable_error_category' => 'syntax_error'], ['comparison_records_vulnerable_error_status_check']],
            'failed without an error category' => [[...$failedVulnerable, 'vulnerable_error_category' => null], ['comparison_records_vulnerable_error_status_check']],
            'secure executed with an error category' => [['secure_error_category' => 'unexpected'], ['comparison_records_secure_error_status_check']],
            'secure failed without an error category' => [[...$failedSecure, 'secure_error_category' => null], ['comparison_records_secure_error_status_check']],
            'truncated below 50 rows' => [['vulnerable_truncated' => true, 'vulnerable_row_count' => 3], ['comparison_records_vulnerable_truncated_check']],
            'executed with 49 rows and truncated' => [['vulnerable_truncated' => true, 'vulnerable_row_count' => 49], ['comparison_records_vulnerable_truncated_check']],
            'failed with no rows but truncated' => [[...$failedVulnerable, 'vulnerable_truncated' => true], ['comparison_records_vulnerable_truncated_check']],
            'refused with no rows but truncated' => [[...$refused, 'vulnerable_truncated' => true], ['comparison_records_vulnerable_truncated_check']],
            'refused with a duration' => [[...$refused, 'vulnerable_duration_us' => 900], ['comparison_records_vulnerable_duration_status_check']],
            'executed without a duration' => [['vulnerable_duration_us' => null], ['comparison_records_vulnerable_duration_status_check']],
            'refused but results differ' => [[...$refused, 'results_differ' => true], ['comparison_records_results_differ_check']],
            'vulnerable failed but results equal' => [[...$failedVulnerable, 'results_differ' => false], ['comparison_records_results_differ_check']],
            'both executed but not compared' => [['results_differ' => null], ['comparison_records_results_differ_check']],
            'secure failed but results differ' => [[...$failedSecure, 'results_differ' => true], ['comparison_records_results_differ_check']],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  list<string>  $expectedViolations
     */
    #[DataProvider('invalidRows')]
    public function test_invalid_rows_are_rejected_by_the_intended_constraint(array $changes, array $expectedViolations): void
    {
        $this->assertSame($expectedViolations, $this->violatedChecks([...self::VALID_ROW, ...$changes]));
    }

    /**
     * Check a row's own attributes against the installed schema: every
     * column except the database-assigned id and created_at must be present
     * (missing values are never filled in from VALID_ROW), NOT NULL columns
     * must have a value, and no installed CHECK constraint may be violated.
     *
     * @param  array<string, mixed>  $attributes  storage values, as Eloquent would insert them
     */
    private function assertRowSatisfiesEveryConstraint(array $attributes, string $context): void
    {
        $columns = $this->columns();

        foreach (array_diff(array_keys($columns), ['id', 'created_at']) as $column) {
            $this->assertArrayHasKey($column, $attributes, "{$context}: [{$column}] is missing.");

            if ($columns[$column]['notNull']) {
                $this->assertNotNull($attributes[$column], "{$context}: [{$column}] is NOT NULL.");
            }
        }

        $this->assertSame([], $this->violatedChecks(['id' => 1, 'created_at' => '2026-11-24 09:00:00+00:00', ...$attributes]), $context);
    }

    /**
     * Check generated factory records against the installed schema using only
     * their own attributes. The baseline supplies just the values the
     * database itself would assign (id, created_at); nothing CHECK-relevant
     * is borrowed from VALID_ROW.
     *
     * @param  list<string>  $states
     */
    private function assertFactoryRecordsSatisfyEveryConstraint(array $states): void
    {
        $label = implode('()->', $states).'()';
        $factory = ComparisonRecord::factory();

        foreach ($states as $state) {
            $factory = $factory->{$state}();
        }

        foreach ($factory->count(8)->make(['user_id' => 1]) as $record) {
            $attributes = $record->getAttributes();
            $context = "{$label} produced ".json_encode($attributes);

            $this->assertRowSatisfiesEveryConstraint($attributes, $context);

            // results_differ follows Comparison::inputAlteredQuery().
            match (true) {
                $attributes['vulnerable_status'] === 'refused' => $this->assertNull($attributes['results_differ'], $context),
                $attributes['vulnerable_status'] === 'failed' => $this->assertTrue($attributes['results_differ'], $context),
                $attributes['secure_status'] !== 'executed' => $this->assertNull($attributes['results_differ'], $context),
                default => $this->assertIsBool($attributes['results_differ'], $context),
            };
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function factoryStates(): array
    {
        return [
            'normal' => ['normal'],
            'attack pattern' => ['attackPattern'],
            'failed vulnerable' => ['failedVulnerable'],
            'failed secure' => ['failedSecure'],
            'refused vulnerable' => ['refusedVulnerable'],
            'truncated vulnerable' => ['truncatedVulnerable'],
            'both failed' => ['bothFailed'],
        ];
    }

    #[DataProvider('factoryStates')]
    public function test_factory_states_satisfy_every_postgresql_constraint(string $state): void
    {
        $this->assertFactoryRecordsSatisfyEveryConstraint([$state]);
    }

    /**
     * Chains of valid states, including the three that previously produced
     * rows PostgreSQL rejects.
     *
     * @return array<string, array{list<string>}>
     */
    public static function factoryStateChains(): array
    {
        $chains = [
            ['failedVulnerable', 'failedSecure'],
            ['refusedVulnerable', 'failedVulnerable'],
            ['failedVulnerable', 'truncatedVulnerable'],
            ['failedSecure', 'failedVulnerable'],
            ['failedSecure', 'refusedVulnerable'],
            ['refusedVulnerable', 'failedSecure'],
            ['truncatedVulnerable', 'failedSecure'],
            ['failedSecure', 'truncatedVulnerable'],
            ['truncatedVulnerable', 'refusedVulnerable'],
            ['attackPattern', 'failedVulnerable'],
            ['failedVulnerable', 'attackPattern'],
            ['failedSecure', 'attackPattern'],
            ['refusedVulnerable', 'attackPattern'],
            ['attackPattern', 'truncatedVulnerable'],
            ['bothFailed', 'refusedVulnerable'],
            ['bothFailed', 'normal'],
            ['bothFailed', 'attackPattern'],
            ['refusedVulnerable', 'bothFailed'],
        ];

        return array_combine(
            array_map(fn (array $chain): string => implode(' then ', $chain), $chains),
            array_map(fn (array $chain): array => [$chain], $chains),
        );
    }

    /**
     * @param  list<string>  $states
     */
    #[DataProvider('factoryStateChains')]
    public function test_chained_factory_states_satisfy_every_postgresql_constraint(array $states): void
    {
        $this->assertFactoryRecordsSatisfyEveryConstraint($states);
    }

    /**
     * Check ComparisonRecorder's actual output against the installed schema.
     *
     * The recorder's attributes go through the model exactly as on create():
     * fill() applies the casts and the classification_rules mutator. user_id
     * is the only value added, as the owner relationship would; id and
     * created_at come from the shared database-assigned baseline.
     */
    private function assertRecorderOutputSatisfiesEveryConstraint(Comparison $comparison, Classification $classification, string $label): void
    {
        $recorded = (new ComparisonRecorder)->attributesFor($comparison, $classification);
        $record = (new ComparisonRecord)->fill($recorded)->forceFill(['user_id' => 1]);
        $attributes = $record->getAttributes();

        $this->assertSame([], array_diff(array_keys($recorded), array_keys($attributes)), "{$label}: fill() dropped a recorder attribute.");
        $this->assertSame($comparison->inputAlteredQuery(), $attributes['results_differ'], $label);

        $this->assertRowSatisfiesEveryConstraint($attributes, "Recorder output for [{$label}]: ".json_encode($attributes));
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     */
    private static function executedResult(string $mode, array $rows, int $duration, bool $truncated = false): ModeResult
    {
        return new ModeResult(mode: $mode, status: ModeResult::EXECUTED, sql: 'SELECT 1', rows: $rows, truncated: $truncated, summary: count($rows).' row(s).', durationMicroseconds: $duration);
    }

    private static function failedResult(string $mode, DemonstrationErrorCategory $category, int $duration): ModeResult
    {
        return new ModeResult(mode: $mode, status: ModeResult::FAILED, sql: 'SELECT 1', summary: 'The database rejected the query.', message: $category->safeMessage(), errorCategory: $category, durationMicroseconds: $duration);
    }

    /**
     * @return list<array<string, string|null>>
     */
    private static function documentRows(int $count, string $visibility = 'public'): array
    {
        return array_fill(0, $count, ['id' => '1', 'title' => 'Fake document', 'owner_team' => 'team-alpha', 'visibility' => $visibility]);
    }

    /**
     * Recorder outcomes built from hand-made mode results, so that outcomes
     * the lab cannot produce on demand (Secure Mode failing) are covered too.
     *
     * @return array<string, array{Comparison, Classification}>
     */
    public static function recorderOutcomes(): array
    {
        $classifier = new PayloadClassifier;
        $comment = DemonstrationType::Comment;
        $union = DemonstrationType::Union;
        $normal = $classifier->classify($comment, ['team' => 'team-alpha']);
        $attack = $classifier->classify($comment, ['team' => "team-alpha' --"]);
        $quoteOnly = $classifier->classify($union, ['category' => "O'Brien"]);
        $mixed = $classifier->classify($union, ['category' => "none' UNION SELECT id, codename, classification, budget FROM lab.demo_internal_projects --"]);
        $refusedResult = new ModeResult(mode: ModeResult::VULNERABLE, status: ModeResult::REFUSED, sql: 'SELECT 1', summary: 'Not executed.', message: 'Refused.');

        $outcome = fn (DemonstrationType $type, ModeResult $vulnerable, ModeResult $secure, Classification $classification): array => [
            new Comparison($type, $type->demonstration(), [], $vulnerable, $secure),
            $classification,
        ];

        return [
            'normal, rows returned' => $outcome($comment, self::executedResult(ModeResult::VULNERABLE, self::documentRows(2), 14_000), self::executedResult(ModeResult::SECURE, self::documentRows(2), 1_200), $normal),
            'normal, zero rows' => $outcome($comment, self::executedResult(ModeResult::VULNERABLE, [], 9_000), self::executedResult(ModeResult::SECURE, [], 800), $normal),
            'attack, results differ' => $outcome($comment, self::executedResult(ModeResult::VULNERABLE, self::documentRows(3, 'private'), 15_000), self::executedResult(ModeResult::SECURE, [], 900), $attack),
            'mixed attack, populated rules' => $outcome($union, self::executedResult(ModeResult::VULNERABLE, self::documentRows(3), 16_000), self::executedResult(ModeResult::SECURE, [], 700), $mixed),
            'vulnerable truncated at 50 rows' => $outcome($comment, self::executedResult(ModeResult::VULNERABLE, self::documentRows(50, 'private'), 30_000, truncated: true), self::executedResult(ModeResult::SECURE, [], 900), $attack),
            'vulnerable failed' => $outcome($union, self::failedResult(ModeResult::VULNERABLE, DemonstrationErrorCategory::SyntaxError, 7_000), self::executedResult(ModeResult::SECURE, [], 600), $quoteOnly),
            'vulnerable refused' => $outcome($comment, $refusedResult, self::executedResult(ModeResult::SECURE, [], 700), $attack),
            'secure failed' => $outcome($comment, self::executedResult(ModeResult::VULNERABLE, self::documentRows(1), 12_000), self::failedResult(ModeResult::SECURE, DemonstrationErrorCategory::Unexpected, 400), $normal),
            'both failed' => $outcome($union, self::failedResult(ModeResult::VULNERABLE, DemonstrationErrorCategory::PermissionDenied, 8_000), self::failedResult(ModeResult::SECURE, DemonstrationErrorCategory::Unexpected, 300), $mixed),
        ];
    }

    #[DataProvider('recorderOutcomes')]
    public function test_recorder_output_satisfies_every_postgresql_constraint(Comparison $comparison, Classification $classification): void
    {
        $this->assertRecorderOutputSatisfiesEveryConstraint($comparison, $classification, $this->dataName());
    }

    /**
     * Real comparisons from DemonstrationRunner on the read-only `lab`
     * connection, as the controller would record them.
     *
     * @return array<string, array{DemonstrationType, array<string, string>, string}>
     */
    public static function realComparisons(): array
    {
        return [
            'normal input' => [DemonstrationType::Union, ['category' => 'stationery'], 'local'],
            'tautology attack' => [DemonstrationType::Tautology, ['username' => "' OR '1'='1", 'password' => 'anything'], 'local'],
            'comment attack' => [DemonstrationType::Comment, ['team' => "team-alpha' --"], 'local'],
            'permission denied' => [DemonstrationType::Union, ['category' => "none' UNION SELECT id, name, email, 0 FROM public.users WHERE 'a'='a"], 'local'],
            'syntax error' => [DemonstrationType::Union, ['category' => "x' AND ("], 'local'],
            'vulnerable refused outside local' => [DemonstrationType::Comment, ['team' => "team-alpha' --"], 'production'],
        ];
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('realComparisons')]
    public function test_recorder_output_from_real_comparisons_satisfies_every_postgresql_constraint(DemonstrationType $type, array $input, string $environment): void
    {
        $this->app->detectEnvironment(fn (): string => $environment);

        $comparison = $this->app->make(DemonstrationRunner::class)->compare($type, $input);
        $classification = (new PayloadClassifier)->classify($type, $input);

        $this->assertRecorderOutputSatisfiesEveryConstraint($comparison, $classification, $this->dataName());
    }
}
