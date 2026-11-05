<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array<string, array{Role, string}>
     */
    public static function roleLandingPages(): array
    {
        return [
            'administrator' => [Role::Administrator, '/admin/dashboard'],
            'student' => [Role::Student, '/student/dashboard'],
        ];
    }

    #[DataProvider('roleLandingPages')]
    public function test_dashboard_redirects_to_the_landing_page_for_the_role(Role $role, string $landingPath): void
    {
        $user = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect($landingPath);
    }
}
