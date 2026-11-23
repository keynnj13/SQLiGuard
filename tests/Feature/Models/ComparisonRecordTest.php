<?php

namespace Tests\Feature\Models;

use App\Demonstrations\Classification\PayloadClassifier;
use App\Demonstrations\ModeResult;
use App\Enums\DemonstrationErrorCategory;
use App\Enums\DemonstrationType;
use App\Enums\PayloadCategory;
use App\Models\ComparisonRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Demonstration history table and model on the in-memory SQLite test
 * database. The PostgreSQL-only CHECK constraints are verified in
 * Tests\Feature\Database\ComparisonRecordsSchemaTest.
 */
class ComparisonRecordTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const array COLUMNS = [
        'id', 'user_id', 'demonstration_type', 'classification_category', 'classification_rules', 'classifier_version',
        'vulnerable_status', 'vulnerable_row_count', 'vulnerable_truncated', 'vulnerable_error_category', 'vulnerable_duration_us',
        'secure_status', 'secure_row_count', 'secure_error_category', 'secure_duration_us',
        'results_differ', 'created_at',
    ];

    public function test_the_table_has_exactly_the_metadata_columns_and_nothing_else(): void
    {
        $this->assertSame(self::COLUMNS, Schema::getColumnListing('comparison_records'));

        foreach (['updated_at', 'input', 'payload', 'password', 'sql', 'bindings', 'rows', 'message', 'summary'] as $forbidden) {
            $this->assertNotContains($forbidden, Schema::getColumnListing('comparison_records'));
        }
    }

    public function test_the_history_indexes_and_the_restricting_foreign_key_exist(): void
    {
        $indexes = collect(Schema::getIndexes('comparison_records'))->keyBy('name');

        $this->assertSame(['user_id', 'created_at', 'id'], $indexes['comparison_records_user_history_index']['columns']);
        $this->assertSame(['created_at', 'id'], $indexes['comparison_records_history_index']['columns']);

        $foreignKeys = Schema::getForeignKeys('comparison_records');
        $this->assertCount(1, $foreignKeys);
        $this->assertSame(['user_id'], $foreignKeys[0]['columns']);
        $this->assertSame('users', $foreignKeys[0]['foreign_table']);
        $this->assertSame(['id'], $foreignKeys[0]['foreign_columns']);
        $this->assertSame('restrict', strtolower($foreignKeys[0]['on_delete']));
    }

    /**
     * @return array<string, array{string, string, string, bool|null}>
     */
    public static function factoryStates(): array
    {
        return [
            'normal' => ['normal', ModeResult::EXECUTED, ModeResult::EXECUTED, false],
            'attack pattern' => ['attackPattern', ModeResult::EXECUTED, ModeResult::EXECUTED, true],
            'failed vulnerable' => ['failedVulnerable', ModeResult::FAILED, ModeResult::EXECUTED, true],
            'failed secure' => ['failedSecure', ModeResult::EXECUTED, ModeResult::FAILED, null],
            'refused vulnerable' => ['refusedVulnerable', ModeResult::REFUSED, ModeResult::EXECUTED, null],
            'truncated vulnerable' => ['truncatedVulnerable', ModeResult::EXECUTED, ModeResult::EXECUTED, true],
            'both failed' => ['bothFailed', ModeResult::FAILED, ModeResult::FAILED, true],
        ];
    }

    #[DataProvider('factoryStates')]
    public function test_each_factory_state_creates_a_consistent_record(string $state, string $vulnerableStatus, string $secureStatus, ?bool $resultsDiffer): void
    {
        $record = ComparisonRecord::factory()->{$state}()->create()->fresh();

        $this->assertSame($vulnerableStatus, $record->vulnerable_status);
        $this->assertSame($secureStatus, $record->secure_status);
        $this->assertSame($resultsDiffer, $record->results_differ);
        $this->assertSame(PayloadClassifier::VERSION, $record->classifier_version);

        foreach (['vulnerable', 'secure'] as $mode) {
            $status = $record->{"{$mode}_status"};
            $rowCount = $record->{"{$mode}_row_count"};
            $errorCategory = $record->{"{$mode}_error_category"};

            $this->assertSame($status === ModeResult::EXECUTED, $rowCount !== null, "{$mode} row count must be present only when executed.");
            $this->assertSame($status === ModeResult::FAILED, $errorCategory !== null, "{$mode} error category must be present only when failed.");
        }

        $this->assertSame($vulnerableStatus === ModeResult::REFUSED, $record->vulnerable_duration_us === null);
        $this->assertIsInt($record->secure_duration_us);
        $this->assertTrue(! $record->vulnerable_truncated || $record->vulnerable_row_count === 50);

        foreach ($record->classification_rules as $field => $ruleIds) {
            $this->assertArrayHasKey($field, $record->demonstration_type->demonstration()->fields());
            $this->assertSame([], array_diff($ruleIds, array_keys(PayloadClassifier::EXPLANATIONS)));
        }
    }

    /**
     * SQLite has no CHECK constraints, so this only proves the values round
     * trip; ComparisonRecordsSchemaTest checks them against PostgreSQL.
     */
    public function test_failed_and_refused_modes_keep_nulls_rather_than_zeros(): void
    {
        $bothFailed = ComparisonRecord::factory()->bothFailed(DemonstrationErrorCategory::PermissionDenied, DemonstrationErrorCategory::Unexpected)->create()->fresh();
        $refused = ComparisonRecord::factory()->refusedVulnerable()->create()->fresh();

        $this->assertSame(ModeResult::FAILED, $bothFailed->vulnerable_status);
        $this->assertSame(ModeResult::FAILED, $bothFailed->secure_status);
        $this->assertNull($bothFailed->vulnerable_row_count);
        $this->assertNull($bothFailed->secure_row_count);
        $this->assertFalse($bothFailed->vulnerable_truncated);
        $this->assertSame(DemonstrationErrorCategory::PermissionDenied, $bothFailed->vulnerable_error_category);
        $this->assertSame(DemonstrationErrorCategory::Unexpected, $bothFailed->secure_error_category);
        $this->assertIsInt($bothFailed->vulnerable_duration_us);
        $this->assertIsInt($bothFailed->secure_duration_us);
        $this->assertTrue($bothFailed->results_differ, 'A failed vulnerable query counts as altered, as in Comparison::inputAlteredQuery().');

        $this->assertSame(ModeResult::REFUSED, $refused->vulnerable_status);
        $this->assertNull($refused->vulnerable_row_count);
        $this->assertFalse($refused->vulnerable_truncated);
        $this->assertNull($refused->vulnerable_duration_us);
        $this->assertNull($refused->vulnerable_error_category);
        $this->assertSame(ModeResult::EXECUTED, $refused->secure_status);
        $this->assertIsInt($refused->secure_row_count);
        $this->assertNull($refused->results_differ);
    }

    public function test_enum_and_scalar_casts_round_trip(): void
    {
        $record = ComparisonRecord::factory()->failedVulnerable(DemonstrationErrorCategory::StatementTimeout)->create([
            'demonstration_type' => DemonstrationType::Comment,
            'classification_category' => PayloadCategory::Comment,
        ])->fresh();

        $this->assertSame(DemonstrationType::Comment, $record->demonstration_type);
        $this->assertSame(PayloadCategory::Comment, $record->classification_category);
        $this->assertSame(DemonstrationErrorCategory::StatementTimeout, $record->vulnerable_error_category);
        $this->assertNull($record->secure_error_category);
        $this->assertIsBool($record->vulnerable_truncated);
        $this->assertIsInt($record->secure_row_count);
        $this->assertIsInt($record->secure_duration_us);

        $raw = DB::table('comparison_records')->find($record->id);
        $this->assertSame('comment', $raw->demonstration_type);
        $this->assertSame('statement_timeout', $raw->vulnerable_error_category);
    }

    public function test_classification_rules_are_stored_as_a_json_object_and_read_back_as_an_array(): void
    {
        $rules = ['username' => ['comment.line'], 'password' => ['tautology.equal_literals', 'signal.quote']];

        $withRules = ComparisonRecord::factory()->create(['classification_rules' => $rules])->fresh();
        $withoutRules = ComparisonRecord::factory()->create(['classification_rules' => []])->fresh();

        $this->assertSame($rules, $withRules->classification_rules);
        $this->assertSame([], $withoutRules->classification_rules);
        $this->assertSame('{}', DB::table('comparison_records')->where('id', $withoutRules->id)->value('classification_rules'), 'No rules must be stored as a JSON object, never as [].');
        $this->assertStringStartsWith('{', DB::table('comparison_records')->where('id', $withRules->id)->value('classification_rules'));
    }

    public function test_the_user_relationship_works_in_both_directions(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $record = ComparisonRecord::factory()->for($owner)->create();
        ComparisonRecord::factory()->for($otherUser)->create();

        $this->assertTrue($record->user->is($owner));
        $this->assertSame([$record->id], $owner->comparisonRecords()->pluck('id')->all());

        $created = $owner->comparisonRecords()->create(ComparisonRecord::factory()->raw(['user_id' => $otherUser->id]));
        $this->assertSame($owner->id, $created->user_id, 'The relationship, not the attributes, decides the owner.');
    }

    public function test_user_id_is_not_mass_assignable(): void
    {
        $record = new ComparisonRecord;

        $this->assertFalse($record->isFillable('user_id'));
        $this->assertFalse($record->isFillable('id'));
        $this->assertFalse($record->isFillable('created_at'));
    }

    public function test_an_account_with_history_cannot_be_deleted(): void
    {
        $record = ComparisonRecord::factory()->create();

        try {
            $record->user->delete();
            $this->fail('An account with comparison history was deleted.');
        } catch (QueryException) {
            // Expected: ON DELETE RESTRICT.
        }

        $this->assertModelExists($record->user);
        $this->assertDatabaseHas('comparison_records', ['id' => $record->id]);
    }

    public function test_created_at_is_set_once_as_an_immutable_utc_offset_timestamp(): void
    {
        $this->freezeSecond(function (): void {
            $record = ComparisonRecord::factory()->create();

            $this->assertNull(ComparisonRecord::UPDATED_AT);
            $this->assertArrayNotHasKey('updated_at', $record->getAttributes());
            $this->assertInstanceOf(CarbonImmutable::class, $record->fresh()->created_at);
            $this->assertTrue($record->fresh()->created_at->equalTo(now()));
            $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', DB::table('comparison_records')->where('id', $record->id)->value('created_at'), 'The stored timestamp must carry its offset.');
        });
    }

    public function test_records_cannot_be_updated_or_deleted_through_the_model(): void
    {
        $record = ComparisonRecord::factory()->create(['classifier_version' => 1]);

        try {
            $record->update(['classifier_version' => 2]);
            $this->fail('A comparison record was updated.');
        } catch (LogicException) {
            // Expected.
        }

        try {
            $record->delete();
            $this->fail('A comparison record was deleted.');
        } catch (LogicException) {
            // Expected.
        }

        $this->assertDatabaseHas('comparison_records', ['id' => $record->id, 'classifier_version' => 1]);
    }
}
