<?php

namespace Tests\Feature\Http\Controllers;

use App\Demonstrations\Classification\PayloadClassifier;
use App\Enums\DemonstrationErrorCategory;
use App\Enums\DemonstrationType;
use App\Models\ComparisonRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Personal and administrator history lists and the detail page.
 * Records are created on the in-memory SQLite test database.
 */
class HistoryControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string PRIVACY = 'Submitted inputs, generated SQL, query parameters, and returned database records are never stored in comparison history.';

    private const string DURATION_CAVEAT = 'Execution times are informational, not a direct performance comparison of parameterized and vulnerable queries. Vulnerable Mode includes the cost of opening a new database connection.';

    private const string STRUCTURE_CAVEAT = 'This reflects the observed comparison outcome. It does not independently prove whether the SQL query structure changed.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return list<int>
     */
    private function listedIds(string $url, User $viewer): array
    {
        return $this->actingAs($viewer)->get($url)->assertOk()->viewData('records')->pluck('id')->all();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function historyUrls(): array
    {
        return [
            'personal list' => ['/history'],
            'administrator list' => ['/admin/history'],
            'detail' => ['/history/1'],
        ];
    }

    #[DataProvider('historyUrls')]
    public function test_guests_are_redirected_to_the_login_page(string $url): void
    {
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_a_student_sees_only_their_own_records_newest_first(): void
    {
        $student = User::factory()->create();
        $older = ComparisonRecord::factory()->for($student)->create(['created_at' => '2026-11-20 10:00:00']);
        $newer = ComparisonRecord::factory()->for($student)->create(['created_at' => '2026-11-21 10:00:00']);
        ComparisonRecord::factory()->for(User::factory())->create();

        $response = $this->actingAs($student)->get(route('history.index'));

        $response->assertOk()
            ->assertSee('<h1 class="h3 mb-0">My Comparison History</h1>', false)
            ->assertSee(self::PRIVACY)
            ->assertDontSee('<th scope="col">User</th>', false)
            ->assertDontSee('id="filter-user"', false);
        $this->assertSame([$newer->id, $older->id], $response->viewData('records')->pluck('id')->all());
    }

    public function test_an_administrators_personal_list_contains_only_their_own_records(): void
    {
        $administrator = User::factory()->administrator()->create();
        $own = ComparisonRecord::factory()->for($administrator)->create();
        ComparisonRecord::factory()->for(User::factory())->count(2)->create();

        $this->assertSame([$own->id], $this->listedIds('/history', $administrator));
    }

    public function test_an_administrator_sees_every_users_records_with_their_owners(): void
    {
        $administrator = User::factory()->administrator()->create();
        $student = User::factory()->inactive()->create(['name' => 'Fictional Student', 'email' => 'fictional.student@example.test']);
        $first = ComparisonRecord::factory()->for($student)->create(['created_at' => '2026-11-20 10:00:00']);
        $second = ComparisonRecord::factory()->for($administrator)->create(['created_at' => '2026-11-21 10:00:00']);

        $response = $this->actingAs($administrator)->get(route('admin.history.index'));

        $response->assertOk()
            ->assertSee('<h1 class="h3 mb-0">All Comparison History</h1>', false)
            ->assertSee(self::PRIVACY)
            ->assertSee('<th scope="col">User</th>', false)
            ->assertSee('id="filter-user"', false)
            ->assertSeeInOrder([$administrator->email, 'Fictional Student', 'Inactive', 'fictional.student@example.test']);
        $this->assertSame([$second->id, $first->id], $response->viewData('records')->pluck('id')->all());
    }

    public function test_a_student_cannot_open_the_administrator_list(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.history.index'))->assertForbidden();
    }

    public function test_a_student_opening_another_users_record_gets_404(): void
    {
        $student = User::factory()->create();
        $othersRecord = ComparisonRecord::factory()->for(User::factory())->create();

        $this->actingAs($student)->get(route('history.show', $othersRecord))->assertNotFound();
    }

    public function test_a_student_opens_their_own_record_without_owner_details(): void
    {
        $student = User::factory()->create(['email' => 'own.student@example.test']);
        $record = ComparisonRecord::factory()->for($student)->create();

        $response = $this->actingAs($student)->get(route('history.show', $record));

        $response->assertOk()
            ->assertSee(self::PRIVACY)
            ->assertSee('Back to My Comparison History')
            ->assertDontSee('Back to All Comparison History')
            ->assertDontSee('data-owner', false)
            ->assertDontSee('own.student@example.test');
    }

    public function test_an_administrator_opens_any_record_and_sees_its_owner(): void
    {
        $administrator = User::factory()->administrator()->create();
        $student = User::factory()->create(['name' => 'Owner Person', 'email' => 'owner.person@example.test']);
        $record = ComparisonRecord::factory()->for($student)->create();

        $this->actingAs($administrator)->get(route('history.show', $record))
            ->assertOk()
            ->assertSee('Run by Owner Person &lt;owner.person@example.test&gt;', false)
            ->assertSee('Back to All Comparison History');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function missingRecordUrls(): array
    {
        return [
            'unknown id' => ['/history/999999'],
            'not a number' => ['/history/abc'],
            'exponent' => ['/history/1e3'],
            'negative' => ['/history/-1'],
            'beyond bigint' => ['/history/'.str_repeat('9', 19)],
        ];
    }

    #[DataProvider('missingRecordUrls')]
    public function test_missing_or_malformed_record_ids_return_404(string $url): void
    {
        $this->actingAs(User::factory()->administrator()->create())->get($url)->assertNotFound();
    }

    public function test_history_urls_accept_no_write_methods_and_the_record_is_unchanged(): void
    {
        $administrator = User::factory()->administrator()->create();
        $record = ComparisonRecord::factory()->for($administrator)->create();
        $before = (array) DB::table('comparison_records')->find($record->id);

        foreach (['put', 'patch', 'delete', 'post'] as $method) {
            foreach ([route('history.show', $record), route('history.index'), route('admin.history.index')] as $url) {
                $status = $this->actingAs($administrator)->{$method}($url, ['classifier_version' => 2])->getStatusCode();
                $this->assertContains($status, [404, 405], strtoupper($method)." {$url} returned {$status}.");
            }
        }

        $this->assertSame($before, (array) DB::table('comparison_records')->find($record->id));
        $this->assertSame(1, ComparisonRecord::query()->count());
    }

    public function test_navigation_shows_history_to_both_roles_and_all_history_to_administrators(): void
    {
        $this->actingAs(User::factory()->create())->get(route('history.index'))
            ->assertSee('>History</a>', false)
            ->assertDontSee('>All History</a>', false);

        $this->actingAs(User::factory()->administrator()->create())->get(route('history.index'))
            ->assertSee('>History</a>', false)
            ->assertSee('>All History</a>', false);
    }

    public function test_lists_show_fifteen_records_per_page_newest_first_with_id_as_tiebreaker(): void
    {
        $student = User::factory()->create();
        $start = CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC');
        foreach (range(1, 15) as $day) {
            ComparisonRecord::factory()->for($student)->create(['created_at' => $start->addDays($day)]);
        }
        $tieA = ComparisonRecord::factory()->for($student)->create(['created_at' => $start->addDays(30)]);
        $tieB = ComparisonRecord::factory()->for($student)->create(['created_at' => $start->addDays(30)]);

        $firstPage = $this->actingAs($student)->get(route('history.index'))->viewData('records');
        $secondPage = $this->actingAs($student)->get(route('history.index', ['page' => 2]))->viewData('records');

        $this->assertCount(15, $firstPage->items());
        $this->assertSame(17, $firstPage->total());
        $this->assertSame([$tieB->id, $tieA->id], array_slice($firstPage->pluck('id')->all(), 0, 2), 'Equal timestamps are ordered by descending ID.');
        $this->assertCount(2, $secondPage->items());
        $this->assertSame($start->addDays(1)->toIso8601String(), $secondPage->last()->created_at->utc()->toIso8601String(), 'The oldest record is last.');
    }

    public function test_active_filters_are_kept_when_moving_between_pages(): void
    {
        $administrator = User::factory()->administrator()->create();
        ComparisonRecord::factory()->for($administrator)->count(16)->normal()->create(['demonstration_type' => DemonstrationType::Comment]);
        ComparisonRecord::factory()->for($administrator)->count(3)->normal()->create(['demonstration_type' => DemonstrationType::Union]);

        foreach (['history.index', 'admin.history.index'] as $route) {
            $response = $this->actingAs($administrator)->get(route($route, ['type' => 'comment', 'altered' => 'no']));
            $records = $response->viewData('records');

            $this->assertSame(16, $records->total());
            $this->assertStringContainsString('type=comment', $records->nextPageUrl());
            $this->assertStringContainsString('altered=no', $records->nextPageUrl());
            $response->assertSee(e($records->nextPageUrl()), false);

            $secondPage = $this->actingAs($administrator)->get($records->nextPageUrl())->viewData('records');
            $this->assertCount(1, $secondPage->items());
        }
    }

    public function test_empty_states_distinguish_no_history_from_no_matches(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)->get(route('history.index'))->assertSee('No comparisons have been recorded yet.');

        ComparisonRecord::factory()->for($student)->normal()->create(['demonstration_type' => DemonstrationType::Comment]);

        $this->actingAs($student)->get(route('history.index', ['type' => 'union']))
            ->assertSee('No comparisons match these filters.')
            ->assertDontSee('No comparisons have been recorded yet.');
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function modeOutcomes(): array
    {
        return [
            'both executed' => ['normal', ['Executed', 'Same results', 'Both modes returned the same records for the same input.']],
            'vulnerable failed' => ['failedVulnerable', ['Failed', 'Syntax error', DemonstrationErrorCategory::SyntaxError->safeMessage(), 'Not available (the query did not complete)', 'Different results']],
            'vulnerable refused' => ['refusedVulnerable', ['Not run (refused)', 'Not available (not run)', 'Not measured (not run)', 'Not compared', 'The two modes could not be compared']],
            'secure failed' => ['failedSecure', ['Failed', 'Unexpected', DemonstrationErrorCategory::Unexpected->safeMessage(), 'Not compared']],
            'both failed' => ['bothFailed', ['Permission denied', DemonstrationErrorCategory::PermissionDenied->safeMessage(), 'Unexpected', 'Different results']],
            'vulnerable truncated' => ['truncatedVulnerable', ['50 rows', 'Only the first 50 were kept for display, so this is not the total number of matching records.', 'Different results']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('modeOutcomes')]
    public function test_the_detail_page_describes_each_mode_outcome(string $state, array $expected): void
    {
        $student = User::factory()->create();
        $record = ComparisonRecord::factory()->for($student)->{$state}()->create();

        $response = $this->actingAs($student)->get(route('history.show', $record));

        $response->assertOk()
            ->assertSee('Vulnerable Mode')
            ->assertSee('Secure Mode')
            ->assertSee(self::DURATION_CAVEAT)
            ->assertSee(self::STRUCTURE_CAVEAT)
            ->assertSee(number_format($record->secure_duration_us).' µs')
            ->assertDontSee('SQLSTATE');
        foreach ($expected as $text) {
            $response->assertSee($text);
        }
    }

    public function test_zero_row_results_show_a_count_of_zero(): void
    {
        $student = User::factory()->create();
        $record = ComparisonRecord::factory()->for($student)->normal()->create(['vulnerable_row_count' => 0, 'secure_row_count' => 0]);

        $this->actingAs($student)->get(route('history.show', $record))->assertOk()->assertSee('0 rows');
        $this->actingAs($student)->get(route('history.index'))->assertOk()->assertSee('0 rows');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function outcomes(): array
    {
        return [
            'different' => ['attackPattern', 'Different results'],
            'same' => ['normal', 'Same results'],
            'not compared' => ['refusedVulnerable', 'Not compared'],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_outcome_labels_follow_results_differ_in_lists_and_detail(string $state, string $label): void
    {
        $administrator = User::factory()->administrator()->create();
        $record = ComparisonRecord::factory()->for($administrator)->{$state}()->create();

        foreach ([route('history.index'), route('admin.history.index'), route('history.show', $record)] as $url) {
            $this->actingAs($administrator)->get($url)->assertOk()->assertSee('">'.$label.'</span>', false);
        }
    }

    public function test_a_different_classifier_version_is_noted_and_the_current_one_is_not(): void
    {
        $student = User::factory()->create();
        $current = ComparisonRecord::factory()->for($student)->create(['classifier_version' => PayloadClassifier::VERSION]);
        $other = ComparisonRecord::factory()->for($student)->create(['classifier_version' => PayloadClassifier::VERSION + 1]);

        $this->actingAs($student)->get(route('history.show', $current))
            ->assertOk()
            ->assertDontSee('data-classifier-version-note', false)
            ->assertSee('Classifier version '.PayloadClassifier::VERSION);

        $this->actingAs($student)->get(route('history.show', $other))
            ->assertOk()
            ->assertSee('data-classifier-version-note', false)
            ->assertSee('classified by classifier version '.(PayloadClassifier::VERSION + 1).'; the current version is '.PayloadClassifier::VERSION);
    }

    public function test_the_detail_page_shows_stored_rules_with_allowlisted_explanations_and_the_disclaimer(): void
    {
        $student = User::factory()->create();
        $record = ComparisonRecord::factory()->for($student)->create([
            'demonstration_type' => DemonstrationType::Tautology,
            'classification_category' => 'tautology',
            'classification_rules' => ['username' => ['tautology.equal_literals', 'signal.quote'], 'password' => ['comment.line']],
        ]);

        $this->actingAs($student)->get(route('history.show', $record))
            ->assertOk()
            ->assertSee('data-classification="tautology"', false)
            ->assertSeeInOrder(['Username', 'tautology.equal_literals', 'signal.quote', 'Password', 'comment.line'])
            ->assertSee(PayloadClassifier::EXPLANATIONS['tautology.equal_literals'])
            ->assertSee('Payload classification is an educational, rule-based aid.')
            ->assertSee(route('demonstrations.show', DemonstrationType::Tautology));
    }

    /**
     * Stored values that the application never writes, set directly in the
     * test database to prove the detail page cannot be broken or abused.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function malformedRules(): array
    {
        return [
            'rules as a string' => ['{"team": "comment.line"}', []],
            'forged and duplicate entries' => ['{"team": [1, {"x": "<script>FORGED-1</script>"}, "not.a.rule", "comment.line", "comment.line", null, ["signal.quote"]], "ghost": ["union.select"]}', ['comment.line']],
            'top-level list' => ['["comment.line"]', []],
            'object of rules' => ['{"team": {"0": "signal.quote"}}', ['signal.quote']],
            'not JSON at all' => ['<script>FORGED-2</script>', []],
            'JSON string' => ['"comment.line"', []],
        ];
    }

    /**
     * @param  list<string>  $expectedRules
     */
    #[DataProvider('malformedRules')]
    public function test_malformed_or_forged_stored_rules_render_safely(string $storedRules, array $expectedRules): void
    {
        $student = User::factory()->create();
        $record = ComparisonRecord::factory()->for($student)->create(['demonstration_type' => DemonstrationType::Comment]);
        DB::table('comparison_records')->where('id', $record->id)->update(['classification_rules' => $storedRules]);

        $response = $this->actingAs($student)->get(route('history.show', $record));

        $response->assertOk()->assertDontSee('FORGED')->assertDontSee('<script', false)->assertDontSee('not.a.rule')->assertDontSee('ghost');
        foreach (array_keys(PayloadClassifier::EXPLANATIONS) as $ruleId) {
            $occurrences = substr_count($response->getContent(), '<code>'.$ruleId.'</code>');
            $this->assertSame(in_array($ruleId, $expectedRules, true) ? 1 : 0, $occurrences, "Rule [{$ruleId}] shown {$occurrences} time(s).");
        }
        if ($expectedRules === []) {
            $response->assertSee('No classification rule matched this input.');
        }
    }

    public function test_names_and_emails_are_escaped(): void
    {
        $administrator = User::factory()->administrator()->create();
        $student = User::factory()->create(['name' => '<b>Mallory</b> & "Eve"', 'email' => 'eve+<i>x</i>@example.test']);
        $record = ComparisonRecord::factory()->for($student)->create();

        foreach ([route('admin.history.index', ['user' => '<b>']), route('admin.history.index'), route('history.show', $record)] as $url) {
            $this->actingAs($administrator)->get($url)
                ->assertOk()
                ->assertSee('&lt;b&gt;Mallory&lt;/b&gt; &amp; &quot;Eve&quot;', false)
                ->assertSee('eve+&lt;i&gt;x&lt;/i&gt;@example.test', false)
                ->assertDontSee('<b>Mallory</b>', false)
                ->assertDontSee('<i>x</i>', false);
        }
    }

    public function test_the_administrator_list_eager_loads_owners_without_n_plus_one_queries(): void
    {
        $administrator = User::factory()->administrator()->create();
        $queriesFor = function () use ($administrator): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $records = $this->actingAs($administrator)->get(route('admin.history.index'))->assertOk()->viewData('records');
            DB::disableQueryLog();

            $this->assertTrue($records->getCollection()->every(fn (ComparisonRecord $record): bool => $record->relationLoaded('user')));

            return count(DB::getQueryLog());
        };

        ComparisonRecord::factory()->count(2)->create();
        $withTwo = $queriesFor();

        ComparisonRecord::factory()->count(13)->create();
        $withFifteen = $queriesFor();

        $this->assertSame($withTwo, $withFifteen, 'Listing 15 owners must not need more queries than listing 2.');
    }

    public function test_dates_are_shown_in_the_application_time_zone(): void
    {
        config(['app.timezone' => 'Asia/Kuala_Lumpur']);
        $student = User::factory()->create();
        $record = ComparisonRecord::factory()->for($student)->create(['created_at' => CarbonImmutable::parse('2026-11-23 16:30:00', 'UTC')]);

        $this->actingAs($student)->get(route('history.index'))->assertSee('2026-11-24 00:30');
        $this->actingAs($student)->get(route('history.show', $record))->assertSee('2026-11-24 00:30:00 (Asia/Kuala_Lumpur)');
    }

    /**
     * A real comparison recorded by T5-C, then viewed through every history page.
     */
    #[Group('lab-database')]
    public function test_history_pages_built_from_a_real_run_never_show_input_sql_parameters_or_rows(): void
    {
        $marker = 'ZQX-T6-MARKER-3307';
        $student = User::factory()->create();
        $administrator = User::factory()->administrator()->create();
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->actingAs($student)
            ->withSession(['_token' => 'phase4-t6-token'])
            ->post(route('demonstrations.run', DemonstrationType::Tautology), [
                'username' => "' OR '1'='1' -- {$marker}",
                'password' => 'Analyst-Lab-2026',
                '_token' => 'phase4-t6-token',
            ])
            ->assertOk()
            ->assertSee('4 accounts matched.');
        $record = ComparisonRecord::query()->sole();

        $pages = [
            [$student, route('history.index')],
            [$student, route('history.show', $record)],
            [$administrator, route('admin.history.index')],
            [$administrator, route('history.show', $record)],
        ];

        foreach ($pages as [$viewer, $url]) {
            $response = $this->actingAs($viewer)->get($url)->assertOk();
            foreach ([$marker, 'Analyst-Lab-2026', "'1'='1", 'demo_accounts', 'demo_password_matches', 'lab.demo', 'SELECT ', 'ORDER BY', 'demo.admin', 'demo.analyst', 'demo.guest', 'demo.student', 'accounts matched', 'SQLSTATE', 'Bound parameters'] as $forbidden) {
                $response->assertDontSee($forbidden, false);
            }
        }

        $this->actingAs($student)->get(route('history.show', $record))
            ->assertSee('Different results')
            ->assertSee('4 rows')
            ->assertSee(PayloadClassifier::EXPLANATIONS['tautology.equal_literals']);
    }
}
