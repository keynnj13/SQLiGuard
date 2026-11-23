<?php

namespace Tests\Feature\Http\Requests;

use App\Enums\DemonstrationErrorCategory;
use App\Enums\DemonstrationType;
use App\Enums\PayloadCategory;
use App\Models\ComparisonRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Validation and behavior of the history filters on both lists.
 */
class ComparisonHistoryRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Five records with distinct, constraint-consistent outcomes, one per day
     * (UTC), all owned by $owner.
     *
     * @return array<string, ComparisonRecord>
     */
    private function dataset(User $owner): array
    {
        $at = fn (string $day): CarbonImmutable => CarbonImmutable::parse("2026-11-{$day} 10:00:00", 'UTC');

        return [
            // Normal comment run, both executed, same results.
            'A' => ComparisonRecord::factory()->for($owner)->normal()->create(['demonstration_type' => DemonstrationType::Comment, 'created_at' => $at('20')]),
            // UNION run whose vulnerable query failed: results differ.
            'B' => ComparisonRecord::factory()->for($owner)->failedVulnerable(DemonstrationErrorCategory::SyntaxError)->create(['created_at' => $at('21')]),
            // Tautology run refused outside local: not compared.
            'C' => ComparisonRecord::factory()->for($owner)->refusedVulnerable()->create(['demonstration_type' => DemonstrationType::Tautology, 'classification_category' => PayloadCategory::Tautology, 'classification_rules' => ['username' => ['tautology.equal_literals']], 'created_at' => $at('22')]),
            // Comment run whose secure query failed: not compared.
            'D' => ComparisonRecord::factory()->for($owner)->failedSecure()->create(['demonstration_type' => DemonstrationType::Comment, 'classification_category' => PayloadCategory::Comment, 'classification_rules' => ['team' => ['comment.line']], 'created_at' => $at('23')]),
            // UNION run, vulnerable truncated: results differ.
            'E' => ComparisonRecord::factory()->for($owner)->truncatedVulnerable()->create(['demonstration_type' => DemonstrationType::Union, 'classification_category' => PayloadCategory::Union, 'classification_rules' => ['category' => ['union.select']], 'created_at' => $at('24')]),
        ];
    }

    /**
     * @param  array<string, string>  $query
     * @return list<int>
     */
    private function idsFor(User $viewer, string $route, array $query): array
    {
        return $this->actingAs($viewer)->get(route($route, $query))->assertOk()->viewData('records')->pluck('id')->sort()->values()->all();
    }

    /**
     * @return array<string, array{array<string, string>, list<string>}>
     */
    public static function filters(): array
    {
        return [
            'no filters' => [[], ['A', 'B', 'C', 'D', 'E']],
            'type comment' => [['type' => 'comment'], ['A', 'D']],
            'type union' => [['type' => 'union'], ['B', 'E']],
            'type tautology' => [['type' => 'tautology'], ['C']],
            'category normal' => [['category' => 'normal'], ['A']],
            'category unknown' => [['category' => 'unknown'], ['B']],
            'category union' => [['category' => 'union'], ['E']],
            'vulnerable executed' => [['vulnerable_status' => 'executed'], ['A', 'D', 'E']],
            'vulnerable failed' => [['vulnerable_status' => 'failed'], ['B']],
            'vulnerable refused' => [['vulnerable_status' => 'refused'], ['C']],
            'secure executed' => [['secure_status' => 'executed'], ['A', 'B', 'C', 'E']],
            'secure failed' => [['secure_status' => 'failed'], ['D']],
            'altered yes' => [['altered' => 'yes'], ['B', 'E']],
            'altered no' => [['altered' => 'no'], ['A']],
            'altered not compared' => [['altered' => 'not_compared'], ['C', 'D']],
            'from' => [['from' => '2026-11-22'], ['C', 'D', 'E']],
            'to' => [['to' => '2026-11-21'], ['A', 'B']],
            'single day' => [['from' => '2026-11-22', 'to' => '2026-11-22'], ['C']],
            'type and outcome' => [['type' => 'comment', 'altered' => 'not_compared'], ['D']],
            'type, status and date' => [['type' => 'union', 'vulnerable_status' => 'executed', 'from' => '2026-11-24'], ['E']],
            'no match' => [['category' => 'union', 'altered' => 'no'], []],
            'empty values are ignored' => [['type' => '', 'altered' => ''], ['A', 'B', 'C', 'D', 'E']],
        ];
    }

    /**
     * @param  array<string, string>  $query
     * @param  list<string>  $expected
     */
    #[DataProvider('filters')]
    public function test_each_filter_alone_and_combined_on_both_lists(array $query, array $expected): void
    {
        $administrator = User::factory()->administrator()->create();
        $records = $this->dataset($administrator);
        $expectedIds = collect($expected)->map(fn (string $key): int => $records[$key]->id)->sort()->values()->all();

        $this->assertSame($expectedIds, $this->idsFor($administrator, 'history.index', $query));
        $this->assertSame($expectedIds, $this->idsFor($administrator, 'admin.history.index', $query));
    }

    public function test_filters_never_widen_a_students_own_history(): void
    {
        $student = User::factory()->create();
        $own = $this->dataset($student);
        $this->dataset(User::factory()->create());

        $this->assertSame(collect($own)->pluck('id')->sort()->values()->all(), $this->idsFor($student, 'history.index', []));
        $this->assertSame([$own['B']->id, $own['E']->id], $this->idsFor($student, 'history.index', ['altered' => 'yes']));
    }

    public function test_dates_are_whole_calendar_days_in_the_application_time_zone(): void
    {
        config(['app.timezone' => 'Asia/Kuala_Lumpur']);
        $student = User::factory()->create();
        $at = fn (string $utc): ComparisonRecord => ComparisonRecord::factory()->for($student)->create(['created_at' => CarbonImmutable::parse($utc, 'UTC')]);

        $at('2026-11-23 15:59:59');              // 23:59:59 on the 23rd in Kuala Lumpur
        $startOfDay = $at('2026-11-23 16:00:00'); // 00:00:00 on the 24th
        $endOfDay = $at('2026-11-24 15:59:59');   // 23:59:59 on the 24th
        $at('2026-11-24 16:00:00');               // 00:00:00 on the 25th

        $this->assertSame([$startOfDay->id, $endOfDay->id], $this->idsFor($student, 'history.index', ['from' => '2026-11-24', 'to' => '2026-11-24']));
    }

    public function test_the_end_date_includes_the_whole_day(): void
    {
        $student = User::factory()->create();
        $lastSecond = ComparisonRecord::factory()->for($student)->create(['created_at' => CarbonImmutable::parse('2026-11-24 23:59:59', 'UTC')]);
        ComparisonRecord::factory()->for($student)->create(['created_at' => CarbonImmutable::parse('2026-11-25 00:00:00', 'UTC')]);

        $this->assertSame([$lastSecond->id], $this->idsFor($student, 'history.index', ['to' => '2026-11-24']));
    }

    public function test_the_user_search_is_a_case_insensitive_literal_match_on_name_or_email(): void
    {
        $administrator = User::factory()->administrator()->create(['name' => 'Admin Person', 'email' => 'admin.person@example.test']);
        $users = [
            'alice' => User::factory()->create(['name' => 'Alice Example', 'email' => 'alice@example.test']),
            'percent' => User::factory()->create(['name' => 'Bob 100% Real', 'email' => 'bob_real@example.test']),
            'wildcardTrap' => User::factory()->create(['name' => 'Dave 1000', 'email' => 'barbara@example.test']),
            'backslash' => User::factory()->create(['name' => 'Eve \\ Back', 'email' => 'eve@example.test']),
        ];
        $ids = collect($users)->map(fn (User $user): int => ComparisonRecord::factory()->for($user)->create()->id);

        $search = fn (string $term): array => $this->idsFor($administrator, 'admin.history.index', ['user' => $term]);

        $this->assertSame([$ids['alice']], $search('ALICE'));
        $this->assertSame([$ids['alice']], $search('alice@EXAMPLE'));
        $this->assertSame([$ids['percent']], $search('100%'), '% is literal, so "Dave 1000" does not match.');
        $this->assertSame([$ids['percent']], $search('%'), 'A lone % matches only a literal percent sign.');
        $this->assertSame([$ids['percent']], $search('b_r'), '_ is literal, so "barbara" does not match.');
        $this->assertSame([$ids['backslash']], $search('\\'), 'The escape character itself is literal.');
        $this->assertSame([], $search('nobody'));
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function invalidFilters(): array
    {
        return [
            'unknown type' => ['history.index', ['type' => 'stacked']],
            'type as array' => ['history.index', ['type' => ['comment']]],
            'unknown category' => ['history.index', ['category' => 'sqli']],
            'unknown vulnerable status' => ['history.index', ['vulnerable_status' => 'skipped']],
            'refused secure status' => ['history.index', ['secure_status' => 'refused']],
            'unknown outcome' => ['history.index', ['altered' => 'maybe']],
            'impossible date' => ['history.index', ['from' => '2026-13-01']],
            'wrong date format' => ['history.index', ['from' => '24-11-2026']],
            'relative date' => ['history.index', ['to' => 'yesterday']],
            'reversed range' => ['history.index', ['from' => '2026-11-24', 'to' => '2026-11-23']],
            'user search on the personal list' => ['history.index', ['user' => 'alice']],
            'user search too long' => ['admin.history.index', ['user' => str_repeat('a', 101)]],
            'user search as array' => ['admin.history.index', ['user' => ['alice']]],
            'reversed range on the administrator list' => ['admin.history.index', ['from' => '2026-11-24', 'to' => '2026-11-01']],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_422_without_flashing_input(string $route, array $query): void
    {
        $administrator = User::factory()->administrator()->create();

        $response = $this->actingAs($administrator)->get(route($route, $query));

        $response->assertStatus(422)
            ->assertSee('Invalid history filters')
            ->assertSee('href="'.route($route).'"', false);
        $response->assertSessionMissing('_old_input');
    }

    public function test_a_student_cannot_supply_a_user_search(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)->get(route('history.index', ['user' => 'alice']))
            ->assertStatus(422)
            ->assertSee('The user field is prohibited.');
        $this->actingAs($student)->get(route('admin.history.index', ['user' => 'alice']))->assertForbidden();
    }

    public function test_json_requests_receive_validation_errors_as_json(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('history.index', ['type' => 'stacked']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_unsupported_query_parameters_are_ignored(): void
    {
        $student = User::factory()->create();
        $own = ComparisonRecord::factory()->for($student)->create();
        ComparisonRecord::factory()->create();

        $this->assertSame([$own->id], $this->idsFor($student, 'history.index', ['user_id' => '1', 'sort' => 'id', 'id' => '2']));
    }

    public function test_the_form_keeps_the_applied_filters(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)
            ->get(route('admin.history.index', ['type' => 'union', 'altered' => 'not_compared', 'from' => '2026-11-01', 'user' => 'a&b']))
            ->assertOk()
            ->assertSee('<option value="union" selected>', false)
            ->assertSee('<option value="not_compared" selected>', false)
            ->assertSee('value="2026-11-01"', false)
            ->assertSee('value="a&amp;b"', false)
            ->assertSee('Clear filters');
    }
}
