<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EnsureAccountIsActiveTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_session_of_a_deactivated_account_is_ended_on_its_next_request(): void
    {
        $user = User::factory()->inactive()->create();

        $response = $this->actingAs($user)->get(route('student.dashboard'));

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'This account has been deactivated. Contact your administrator.']);
        $this->assertGuest();
    }

    public function test_active_account_keeps_its_session(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->withoutVite()->get(route('student.dashboard'));

        $response->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
