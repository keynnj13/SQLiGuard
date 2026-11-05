<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DemonstrationType;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class DemonstrationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * POST a comparison with a valid CSRF token. Outside the `testing`
     * environment Laravel enforces CSRF, as it does in the real lab.
     *
     * @param  array<string, string>  $input
     */
    private function runAs(User $user, DemonstrationType $type, array $input): TestResponse
    {
        return $this->actingAs($user)
            ->withSession(['_token' => 'phase3-csrf-token'])
            ->post(route('demonstrations.run', $type), [...$input, '_token' => 'phase3-csrf-token']);
    }

    /**
     * @return array<string, array{DemonstrationType}>
     */
    public static function demonstrations(): array
    {
        return [
            'tautology' => [DemonstrationType::Tautology],
            'union' => [DemonstrationType::Union],
            'comment' => [DemonstrationType::Comment],
        ];
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function roles(): array
    {
        return [
            'administrator' => [Role::Administrator],
            'student' => [Role::Student],
        ];
    }

    public function test_guest_is_redirected_to_login_from_the_demonstration_list(): void
    {
        $this->get(route('demonstrations.index'))->assertRedirect(route('login'));
    }

    #[DataProvider('demonstrations')]
    public function test_guest_cannot_open_or_run_a_demonstration(DemonstrationType $type): void
    {
        $this->get(route('demonstrations.show', $type))->assertRedirect(route('login'));

        $this->post(route('demonstrations.run', $type), ['username' => 'x', 'category' => 'x', 'team' => 'x'])
            ->assertRedirect(route('login'));
    }

    #[DataProvider('roles')]
    public function test_both_roles_can_open_the_demonstration_list(Role $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($user)->get(route('demonstrations.index'));

        $response->assertOk()
            ->assertSee('Tautology-based injection')
            ->assertSee('UNION-based injection')
            ->assertSee('Comment-based injection');
    }

    #[DataProvider('roles')]
    public function test_both_roles_can_open_every_demonstration(Role $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        foreach (DemonstrationType::cases() as $type) {
            $this->actingAs($user)->get(route('demonstrations.show', $type))
                ->assertOk()
                ->assertSee($type->demonstration()->title())
                ->assertSee('Run comparison');
        }
    }

    public function test_unknown_demonstration_returns_404(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)->get('/demonstrations/stacked-queries')->assertNotFound();
        $this->actingAs($student)->post('/demonstrations/stacked-queries', ['q' => 'x'])->assertNotFound();
    }

    public function test_student_still_cannot_reach_account_management(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_sample_prefills_the_form_with_its_escaped_value(): void
    {
        $student = User::factory()->create();

        $response = $this->actingAs($student)->get(route('demonstrations.show', ['demonstration' => 'tautology', 'sample' => 'always-true-username']));

        $response->assertOk()->assertSee('value="&#039; OR &#039;1&#039;=&#039;1"', false);
    }

    public function test_unknown_sample_key_is_ignored(): void
    {
        $student = User::factory()->create();

        $response = $this->actingAs($student)->get(route('demonstrations.show', ['demonstration' => 'union', 'sample' => 'not-a-sample']));

        $response->assertOk()->assertSee('value=""', false);
    }

    public function test_required_input_is_validated_before_anything_runs(): void
    {
        $student = User::factory()->create();

        $response = $this->actingAs($student)->post(route('demonstrations.run', DemonstrationType::Union), []);

        $response->assertSessionHasErrors(['category' => 'The category field is required.']);
    }

    public function test_input_longer_than_200_characters_is_rejected(): void
    {
        $student = User::factory()->create();

        $response = $this->actingAs($student)->post(route('demonstrations.run', DemonstrationType::Comment), ['team' => str_repeat('a', 201)]);

        $response->assertSessionHasErrors(['team' => 'The team field must not be greater than 200 characters.']);
    }

    public function test_control_characters_are_rejected(): void
    {
        $student = User::factory()->create();

        $response = $this->actingAs($student)->post(route('demonstrations.run', DemonstrationType::Comment), ['team' => "team-alpha\0' --"]);

        $response->assertSessionHasErrors(['team' => 'The team must not contain control characters.']);
    }

    public function test_runs_are_rate_limited_per_user(): void
    {
        $student = User::factory()->create();
        foreach (range(1, 30) as $attempt) {
            $this->actingAs($student)->post(route('demonstrations.run', DemonstrationType::Union), []);
        }

        $response = $this->actingAs($student)->post(route('demonstrations.run', DemonstrationType::Union), []);

        $response->assertTooManyRequests();
    }

    #[Group('lab-database')]
    public function test_student_runs_a_tautology_comparison_and_sees_both_modes_side_by_side(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        $student = User::factory()->create();
        $usersBefore = User::query()->count();

        $response = $this->runAs($student, DemonstrationType::Tautology, [
            'username' => "' OR '1'='1",
            'password' => 'anything',
        ]);

        $response->assertOk()
            ->assertSeeInOrder(['Vulnerable Mode', 'Secure Mode'])
            ->assertSee('Input altered the vulnerable query')
            ->assertSee('4 accounts matched.')
            ->assertSee('No matching account: the fake login is refused.')
            ->assertSee('<span class="mark px-0">&#039; OR &#039;1&#039;=&#039;1</span>', false)
            ->assertSee('lab.demo_password_matches(id, ?) and &quot;username&quot; = ?', false)
            ->assertDontSee('password_digest');
        $this->assertAuthenticatedAs($student);
        $this->assertSame($usersBefore, User::query()->count());
    }

    #[Group('lab-database')]
    public function test_comparison_escapes_markup_in_the_input(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        $student = User::factory()->create();

        $response = $this->runAs($student, DemonstrationType::Comment, [
            'team' => '<script>alert(1)</script>',
        ]);

        $response->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    #[Group('lab-database')]
    public function test_outside_the_local_environment_only_secure_mode_runs(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $administrator = User::factory()->administrator()->create();

        $response = $this->runAs($administrator, DemonstrationType::Comment, [
            'team' => "team-alpha' --",
        ]);

        $response->assertOk()
            ->assertSee('Vulnerable Mode is only available when SQLiGuard runs in the local lab environment')
            ->assertSee('No documents returned.')
            ->assertDontSee('Lab environment warning')
            ->assertDontSee('Team Alpha draft budget');
    }
}
