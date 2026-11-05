<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LoginControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string PASSWORD = 'Valid-Lab-Pass-42!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guest_can_view_the_login_page(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertViewIs('auth.login')
            ->assertSee('name="password"', false);
    }

    public function test_active_user_logs_in_and_is_redirected_to_the_dashboard(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => self::PASSWORD]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_regenerates_the_session_id(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->get(route('login'));
        $sessionIdBeforeLogin = session()->getId();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionIdBeforeLogin, session()->getId());
    }

    public function test_login_matches_the_email_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'student@example.test', 'password' => self::PASSWORD]);

        $this->post(route('login.store'), ['email' => '  Student@Example.TEST ', 'password' => self::PASSWORD]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected_with_a_generic_message(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $response = $this->from(route('login'))
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'Wrong-Pass-42!']);

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest();
    }

    public function test_inactive_account_cannot_log_in_and_gets_the_same_generic_message(): void
    {
        $user = User::factory()->inactive()->create(['password' => self::PASSWORD]);

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => self::PASSWORD]);

        $response->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest();
    }

    public function test_email_and_password_are_required(): void
    {
        $response = $this->post(route('login.store'), []);

        $response->assertSessionHasErrors([
            'email' => 'The email field is required.',
            'password' => 'The password field is required.',
        ]);
        $this->assertGuest();
    }

    public function test_login_is_locked_after_five_failed_attempts_even_with_the_correct_password(): void
    {
        Event::fake([Lockout::class]);
        $this->freezeTime();
        $user = User::factory()->create(['password' => self::PASSWORD]);
        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Wrong-Pass-42!']);
        }

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => self::PASSWORD]);

        $response->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 60 seconds.']);
        $this->assertGuest();
        Event::assertDispatched(Lockout::class);
    }

    public function test_authenticated_user_is_redirected_away_from_the_login_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('login'));

        $response->assertRedirect(route('dashboard'));
    }

    public function test_login_returns_to_the_protected_page_that_was_requested(): void
    {
        $administrator = User::factory()->administrator()->create(['password' => self::PASSWORD]);
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));

        $response = $this->post(route('login.store'), ['email' => $administrator->email, 'password' => self::PASSWORD]);

        $response->assertRedirect(route('admin.users.index'));
    }

    public function test_login_ignores_a_user_supplied_redirect_target(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $response = $this->post(
            route('login.store', ['redirect' => 'https://evil.example', 'intended' => 'https://evil.example']),
            ['email' => $user->email, 'password' => self::PASSWORD, 'redirect' => 'https://evil.example'],
        );

        $response->assertRedirect(route('dashboard'));
    }

    public function test_logout_invalidates_the_session_and_rotates_the_csrf_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['_token' => 'token-before-logout', 'lab_probe' => 'present'])
            ->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
        $response->assertSessionMissing('lab_probe');
        $this->assertNotSame('token-before-logout', session()->token());
    }

    public function test_logout_does_not_accept_get_requests(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/logout');

        $response->assertMethodNotAllowed();
        $this->assertAuthenticatedAs($user);
    }
}
