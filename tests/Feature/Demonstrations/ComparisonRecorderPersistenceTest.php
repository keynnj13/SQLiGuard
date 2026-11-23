<?php

namespace Tests\Feature\Demonstrations;

use App\Demonstrations\Classification\Classification;
use App\Demonstrations\Classification\PayloadClassifier;
use App\Demonstrations\Comparison;
use App\Demonstrations\ComparisonRecorder;
use App\Demonstrations\ModeResult;
use App\Enums\DemonstrationErrorCategory;
use App\Enums\DemonstrationType;
use App\Enums\PayloadCategory;
use App\Models\ComparisonRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * ComparisonRecorder against the in-memory SQLite test database.
 * SQLite has no CHECK constraints; the stored values are checked against
 * the PostgreSQL constraints by mapping (unit test) and real runs.
 */
class ComparisonRecorderPersistenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string MARKER = 'ZQX-PERSIST-MARKER-5519';

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Capture every log entry without writing to storage/logs.
        Log::setDefaultDriver('null');
        Event::listen(MessageLogged::class, function (MessageLogged $entry): void {
            $this->logged[] = $entry;
        });
    }

    private function comparison(?int $secureDuration = 700): Comparison
    {
        $privateRow = ['id' => '2', 'title' => self::MARKER.'-ROW', 'owner_team' => 'team-alpha', 'visibility' => 'private'];

        return new Comparison(
            DemonstrationType::Comment,
            DemonstrationType::Comment->demonstration(),
            ['team' => self::MARKER."' --"],
            new ModeResult(mode: ModeResult::VULNERABLE, status: ModeResult::EXECUTED, sql: "SELECT '".self::MARKER."'", rows: [$privateRow], summary: self::MARKER.'-SUMMARY', durationMicroseconds: 15_000),
            new ModeResult(mode: ModeResult::SECURE, status: ModeResult::EXECUTED, sql: 'select ?', bindings: [self::MARKER.'-BINDING'], rows: [], summary: 'No documents returned.', durationMicroseconds: $secureDuration),
        );
    }

    private function classification(): Classification
    {
        return (new PayloadClassifier)->classify(DemonstrationType::Comment, ['team' => self::MARKER."' --"]);
    }

    public function test_every_stored_attribute_is_fillable_so_nothing_is_silently_dropped(): void
    {
        $attributes = (new ComparisonRecorder)->attributesFor($this->comparison(), $this->classification());

        $this->assertEqualsCanonicalizing((new ComparisonRecord)->getFillable(), array_keys($attributes));
    }

    public function test_one_completed_comparison_creates_exactly_one_record_owned_by_the_user(): void
    {
        $user = User::factory()->create();
        $comparison = $this->comparison();

        $record = (new ComparisonRecorder)->record($user, $comparison, $this->classification());

        $this->assertInstanceOf(ComparisonRecord::class, $record);
        $this->assertSame(1, ComparisonRecord::query()->count());
        $this->assertTrue($record->user->is($user));

        $stored = $record->fresh();
        $this->assertSame(DemonstrationType::Comment, $stored->demonstration_type);
        $this->assertSame(PayloadCategory::Comment, $stored->classification_category);
        $this->assertSame(['team' => ['comment.line', 'signal.quote']], $stored->classification_rules);
        $this->assertSame(PayloadClassifier::VERSION, $stored->classifier_version);
        $this->assertSame([ModeResult::EXECUTED, 1, false, null, 15_000], [$stored->vulnerable_status, $stored->vulnerable_row_count, $stored->vulnerable_truncated, $stored->vulnerable_error_category, $stored->vulnerable_duration_us]);
        $this->assertSame([ModeResult::EXECUTED, 0, null, 700], [$stored->secure_status, $stored->secure_row_count, $stored->secure_error_category, $stored->secure_duration_us]);
        $this->assertSame($comparison->inputAlteredQuery(), $stored->results_differ);
        $this->assertNotNull($stored->created_at);
        $this->assertSame([], $this->logged);
    }

    public function test_two_completed_comparisons_create_two_records(): void
    {
        $user = User::factory()->create();
        $recorder = new ComparisonRecorder;

        $first = $recorder->record($user, $this->comparison(), $this->classification());
        $second = $recorder->record($user, $this->comparison(), $this->classification());

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $user->comparisonRecords()->count());
    }

    public function test_failed_and_refused_outcomes_are_stored_with_their_nulls(): void
    {
        $user = User::factory()->create();
        $bothFailed = new Comparison(
            DemonstrationType::Union,
            DemonstrationType::Union->demonstration(),
            ['category' => "x'"],
            new ModeResult(mode: ModeResult::VULNERABLE, status: ModeResult::FAILED, sql: 'x', errorCategory: DemonstrationErrorCategory::SyntaxError, durationMicroseconds: 800),
            new ModeResult(mode: ModeResult::SECURE, status: ModeResult::FAILED, sql: 'x', errorCategory: DemonstrationErrorCategory::Unexpected, durationMicroseconds: 90),
        );
        $refused = new Comparison(
            DemonstrationType::Union,
            DemonstrationType::Union->demonstration(),
            ['category' => 'stationery'],
            new ModeResult(mode: ModeResult::VULNERABLE, status: ModeResult::REFUSED, sql: 'x'),
            new ModeResult(mode: ModeResult::SECURE, status: ModeResult::EXECUTED, sql: 'x', rows: [], durationMicroseconds: 90),
        );
        $classification = new Classification(PayloadCategory::Normal, [], [], PayloadClassifier::VERSION);

        $failedRecord = (new ComparisonRecorder)->record($user, $bothFailed, $classification)->fresh();
        $refusedRecord = (new ComparisonRecorder)->record($user, $refused, $classification)->fresh();

        $this->assertSame([ModeResult::FAILED, null, false, DemonstrationErrorCategory::SyntaxError, 800], [$failedRecord->vulnerable_status, $failedRecord->vulnerable_row_count, $failedRecord->vulnerable_truncated, $failedRecord->vulnerable_error_category, $failedRecord->vulnerable_duration_us]);
        $this->assertSame([ModeResult::FAILED, null, DemonstrationErrorCategory::Unexpected, 90], [$failedRecord->secure_status, $failedRecord->secure_row_count, $failedRecord->secure_error_category, $failedRecord->secure_duration_us]);
        $this->assertTrue($failedRecord->results_differ);

        $this->assertSame([ModeResult::REFUSED, null, false, null, null], [$refusedRecord->vulnerable_status, $refusedRecord->vulnerable_row_count, $refusedRecord->vulnerable_truncated, $refusedRecord->vulnerable_error_category, $refusedRecord->vulnerable_duration_us]);
        $this->assertSame(0, $refusedRecord->secure_row_count);
        $this->assertNull($refusedRecord->results_differ);
        $this->assertSame('{}', DB::table('comparison_records')->where('id', $refusedRecord->id)->value('classification_rules'), 'Normal input is stored as an empty JSON object.');
    }

    public function test_stored_records_contain_no_input_sql_parameters_rows_or_summaries(): void
    {
        $user = User::factory()->create();

        (new ComparisonRecorder)->record($user, $this->comparison(), $this->classification());

        $stored = json_encode(DB::table('comparison_records')->get());
        foreach ([self::MARKER, 'SELECT', 'select', 'BINDING', 'SUMMARY', 'No documents returned', 'owner_team'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $stored);
        }
    }

    public function test_a_failed_insert_returns_null_logs_only_approved_metadata_and_leaves_no_record(): void
    {
        $user = User::factory()->create();
        // Secure duration is NOT NULL, so this insert fails in the database.
        $comparison = $this->comparison(secureDuration: null);
        $classification = $this->classification();
        $before = serialize([$comparison, $classification]);

        $record = (new ComparisonRecorder)->record($user, $comparison, $classification);

        $this->assertNull($record);
        $this->assertSame(0, ComparisonRecord::query()->count(), 'No partial record may remain.');
        $this->assertSame($before, serialize([$comparison, $classification]), 'The comparison and classification are unchanged.');

        $this->assertCount(1, $this->logged);
        $this->assertSame('warning', $this->logged[0]->level);
        $this->assertSame('Comparison history could not be saved.', $this->logged[0]->message);
        $this->assertSame(['demonstration', 'user_id', 'sqlstate'], array_keys($this->logged[0]->context));
        $this->assertSame('comment', $this->logged[0]->context['demonstration']);
        $this->assertSame($user->id, $this->logged[0]->context['user_id']);
        $this->assertIsString($this->logged[0]->context['sqlstate']);

        $logged = json_encode(array_map(fn (MessageLogged $entry): array => [$entry->message, $entry->context], $this->logged));
        foreach ([self::MARKER, 'insert', 'SELECT', 'comparison_records'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $logged);
        }
    }
}
