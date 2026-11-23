<?php

namespace Tests\Feature\Http\Controllers;

use App\Demonstrations\Classification\Classification;
use App\Demonstrations\Classification\PayloadClassifier;
use App\Demonstrations\Comparison;
use App\Demonstrations\ComparisonRecorder;
use App\Demonstrations\DemonstrationRunner;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Each completed comparison saves one metadata-only history record.
 *
 * History is written to the default connection, which in tests is the
 * in-memory SQLite database; tests marked lab-database run the real
 * comparison on the PostgreSQL `lab` connection (read-only).
 */
class DemonstrationHistoryRecordingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string NOTICE = 'Comparison completed, but its history could not be saved. The results are still available on this page.';

    private const string MARKER = 'ZQX-HISTORY-MARKER-8842';

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Capture every log entry without writing to storage/logs.
        Log::setDefaultDriver('null');
        Event::listen(MessageLogged::class, function (MessageLogged $entry): void {
            $this->logged[] = $entry;
        });
    }

    /**
     * POST a real comparison with a valid CSRF token.
     *
     * @param  array<string, string|int>  $input
     */
    private function runAs(User $user, DemonstrationType $type, array $input, string $environment = 'local'): TestResponse
    {
        $this->app->detectEnvironment(fn (): string => $environment);

        return $this->actingAs($user)
            ->withSession(['_token' => 'phase4-t5c-token'])
            ->post(route('demonstrations.run', $type), [...$input, '_token' => 'phase4-t5c-token']);
    }

    private function onlyRecord(): ComparisonRecord
    {
        $this->assertSame(1, ComparisonRecord::query()->count());

        return ComparisonRecord::query()->sole();
    }

    public function test_the_form_page_shows_no_history_notice(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('demonstrations.show', DemonstrationType::Comment))
            ->assertOk()
            ->assertDontSee(self::NOTICE);
    }

    public function test_the_notice_is_shown_only_when_saving_the_history_failed(): void
    {
        $type = DemonstrationType::Comment;
        $comparison = new Comparison($type, $type->demonstration(), ['team' => 'team-alpha'],
            new ModeResult(mode: ModeResult::VULNERABLE, status: ModeResult::EXECUTED, sql: 'SELECT 1', rows: []),
            new ModeResult(mode: ModeResult::SECURE, status: ModeResult::EXECUTED, sql: 'SELECT 1', rows: []),
        );
        $view = fn (?bool $historyRecorded) => $this->withViewErrors([])->view('demonstrations.show', [
            'type' => $type,
            'types' => DemonstrationType::cases(),
            'demonstration' => $type->demonstration(),
            'values' => $comparison->input,
            'comparison' => $comparison,
            'classification' => new Classification(PayloadCategory::Normal, [], [], PayloadClassifier::VERSION),
            'classificationRules' => [],
            'historyRecorded' => $historyRecorded,
            'vulnerableModeAvailable' => true,
        ]);

        $view(false)->assertSee(self::NOTICE)->assertSee('Same behavior in both modes');
        $view(true)->assertDontSee(self::NOTICE);
        $view(null)->assertDontSee(self::NOTICE);
    }

    #[Group('lab-database')]
    public function test_a_run_creates_one_record_owned_by_the_authenticated_user_even_with_a_forged_user_id(): void
    {
        $student = User::factory()->create();
        $otherUser = User::factory()->create();

        $response = $this->runAs($student, DemonstrationType::Tautology, [
            'username' => "' OR '1'='1",
            'password' => 'anything',
            'user_id' => $otherUser->id,
            'id' => 999,
        ]);

        $response->assertOk()->assertDontSee(self::NOTICE);
        $this->assertTrue($response->viewData('historyRecorded'));
        $record = $this->onlyRecord();
        $this->assertSame($student->id, $record->user_id);
        $this->assertNotSame(999, $record->id);
        $this->assertSame(0, $otherUser->comparisonRecords()->count());
    }

    #[Group('lab-database')]
    public function test_two_runs_create_two_records(): void
    {
        $student = User::factory()->create();

        $this->runAs($student, DemonstrationType::Comment, ['team' => 'team-alpha'])->assertOk();
        $this->runAs($student, DemonstrationType::Comment, ['team' => "team-alpha' --"])->assertOk();

        $this->assertSame(2, $student->comparisonRecords()->count());
    }

    #[Group('lab-database')]
    public function test_an_attack_run_stores_each_mode_and_the_classification_exactly_as_displayed(): void
    {
        $student = User::factory()->create();

        $response = $this->runAs($student, DemonstrationType::Tautology, ['username' => "' OR '1'='1", 'password' => 'anything']);

        $response->assertOk()
            ->assertSee('Input altered the vulnerable query')
            ->assertSee('4 accounts matched.')
            ->assertSee('No matching account: the fake login is refused.');

        $record = $this->onlyRecord();
        $this->assertSame(DemonstrationType::Tautology, $record->demonstration_type);
        $this->assertSame(PayloadCategory::Tautology, $record->classification_category);
        $this->assertSame(['username' => ['tautology.equal_literals', 'signal.quote']], $record->classification_rules);
        $this->assertSame(PayloadClassifier::VERSION, $record->classifier_version);
        $this->assertSame([ModeResult::EXECUTED, 4, false, null], [$record->vulnerable_status, $record->vulnerable_row_count, $record->vulnerable_truncated, $record->vulnerable_error_category]);
        $this->assertSame([ModeResult::EXECUTED, 0, null], [$record->secure_status, $record->secure_row_count, $record->secure_error_category]);
        $this->assertIsInt($record->vulnerable_duration_us);
        $this->assertIsInt($record->secure_duration_us);
        $this->assertTrue($record->results_differ);

        // The record is exactly the recorder's mapping of what the page displayed.
        $expected = (new ComparisonRecorder)->attributesFor($response->viewData('comparison'), $response->viewData('classification'));
        $this->assertSame($response->viewData('comparison')->inputAlteredQuery(), $record->results_differ);
        $this->assertSame($expected['vulnerable_duration_us'], $record->vulnerable_duration_us);
        $this->assertSame($expected['secure_duration_us'], $record->secure_duration_us);
    }

    #[Group('lab-database')]
    public function test_a_failed_vulnerable_query_is_stored_with_its_error_category(): void
    {
        $this->runAs(User::factory()->create(), DemonstrationType::Union, [
            'category' => "none' UNION SELECT id, name, email, 0 FROM public.users WHERE 'a'='a",
        ])->assertOk();

        $record = $this->onlyRecord();
        $this->assertSame([ModeResult::FAILED, null, false, DemonstrationErrorCategory::PermissionDenied], [$record->vulnerable_status, $record->vulnerable_row_count, $record->vulnerable_truncated, $record->vulnerable_error_category]);
        $this->assertIsInt($record->vulnerable_duration_us);
        $this->assertSame([ModeResult::EXECUTED, 0, null], [$record->secure_status, $record->secure_row_count, $record->secure_error_category]);
        $this->assertTrue($record->results_differ);
        $this->assertSame(PayloadCategory::Union, $record->classification_category);
    }

    #[Group('lab-database')]
    public function test_a_refused_vulnerable_mode_is_stored_with_null_count_duration_and_result(): void
    {
        $this->runAs(User::factory()->administrator()->create(), DemonstrationType::Comment, ['team' => "team-alpha' --"], environment: 'production')->assertOk();

        $record = $this->onlyRecord();
        $this->assertSame([ModeResult::REFUSED, null, false, null, null], [$record->vulnerable_status, $record->vulnerable_row_count, $record->vulnerable_truncated, $record->vulnerable_error_category, $record->vulnerable_duration_us]);
        $this->assertSame(ModeResult::EXECUTED, $record->secure_status);
        $this->assertIsInt($record->secure_duration_us);
        $this->assertNull($record->results_differ);
    }

    #[Group('lab-database')]
    public function test_normal_input_stores_an_empty_rules_object(): void
    {
        $this->runAs(User::factory()->create(), DemonstrationType::Comment, ['team' => 'team-alpha'])->assertOk();

        $record = $this->onlyRecord();
        $this->assertSame(PayloadCategory::Normal, $record->classification_category);
        $this->assertSame([], $record->classification_rules);
        $this->assertSame('{}', DB::table('comparison_records')->value('classification_rules'));
        $this->assertFalse($record->results_differ);
    }

    #[Group('lab-database')]
    public function test_neither_the_history_nor_the_log_contains_input_password_sql_or_rows(): void
    {
        $response = $this->runAs(User::factory()->create(), DemonstrationType::Tautology, [
            'username' => "demo.analyst' -- ".self::MARKER,
            'password' => 'Analyst-Lab-2026',
        ]);

        $response->assertOk()->assertSee(self::MARKER);
        $stored = json_encode(DB::table('comparison_records')->get());
        $logged = json_encode(array_map(fn (MessageLogged $entry): array => [$entry->message, $entry->context], $this->logged));

        $this->assertSame(1, ComparisonRecord::query()->count());
        foreach ([self::MARKER, 'Analyst-Lab-2026', 'analyst', 'select', 'demo_accounts', 'demo_password_matches', 'matched', 'account_type'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $stored, "History contains [{$forbidden}].");
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $logged, "Log contains [{$forbidden}].");
        }
    }

    #[Group('lab-database')]
    public function test_when_saving_fails_the_completed_comparison_is_still_shown_with_a_notice(): void
    {
        $student = User::factory()->create();
        // A real database failure: the insert hits a missing table. The test
        // transaction restores the table afterwards.
        Schema::drop('comparison_records');

        $response = $this->runAs($student, DemonstrationType::Tautology, ['username' => "' OR '1'='1' -- ".self::MARKER, 'password' => 'Analyst-Lab-2026']);

        $response->assertOk()
            ->assertSee(self::NOTICE)
            ->assertSee('Input altered the vulnerable query')
            ->assertSee('4 accounts matched.')
            ->assertSee('Payload classification');
        $this->assertFalse($response->viewData('historyRecorded'));
        $this->assertFalse(Schema::hasTable('comparison_records'));

        $this->assertCount(1, $this->logged);
        $this->assertSame('Comparison history could not be saved.', $this->logged[0]->message);
        $this->assertSame(['demonstration' => 'tautology', 'user_id' => $student->id], array_intersect_key($this->logged[0]->context, ['demonstration' => true, 'user_id' => true]));
        $this->assertSame(['demonstration', 'user_id', 'sqlstate'], array_keys($this->logged[0]->context));
        $logged = json_encode([$this->logged[0]->message, $this->logged[0]->context]);
        foreach ([self::MARKER, 'Analyst-Lab-2026', 'insert', 'select', 'comparison_records'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $logged);
        }
    }

    #[Group('lab-database')]
    public function test_recording_does_not_change_the_comparison_results(): void
    {
        $input = ['team' => "team-alpha' --"];
        $this->app->detectEnvironment(fn (): string => 'local');
        $direct = $this->app->make(DemonstrationRunner::class)->compare(DemonstrationType::Comment, $input);

        $response = $this->runAs(User::factory()->create(), DemonstrationType::Comment, $input);
        $displayed = $response->viewData('comparison');

        $this->assertSame($direct->vulnerable->rows, $displayed->vulnerable->rows);
        $this->assertSame($direct->secure->rows, $displayed->secure->rows);
        $this->assertSame($direct->inputAlteredQuery(), $displayed->inputAlteredQuery());
        $this->assertSame($direct->vulnerable->sql, $displayed->vulnerable->sql);
        $this->assertSame($direct->secure->bindings, $displayed->secure->bindings);
        $this->assertSame(1, ComparisonRecord::query()->count());
        $this->assertSame(count($displayed->vulnerable->rows), $this->onlyRecord()->vulnerable_row_count);
    }
}
