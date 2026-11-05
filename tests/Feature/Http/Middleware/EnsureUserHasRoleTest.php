<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnsureUserHasRoleTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * GET pages in the `role:administrator` group.
     *
     * @return array<string, array{string}>
     */
    public static function administratorPages(): array
    {
        return [
            'admin dashboard' => ['admin.dashboard'],
            'account list' => ['admin.users.index'],
            'create account form' => ['admin.users.create'],
            'edit account form' => ['admin.users.edit'],
        ];
    }

    /**
     * Every GET page that requires authentication.
     *
     * @return array<string, array{string}>
     */
    public static function protectedPages(): array
    {
        return [
            'dashboard redirect' => ['dashboard'],
            'student dashboard' => ['student.dashboard'],
            ...self::administratorPages(),
        ];
    }

    #[DataProvider('administratorPages')]
    public function test_administrator_can_open_administrator_page(string $routeName): void
    {
        $administrator = User::factory()->administrator()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($administrator)->get(route($routeName, ['user' => $target]));

        $response->assertOk();
    }

    #[DataProvider('administratorPages')]
    public function test_student_is_forbidden_from_administrator_page(string $routeName): void
    {
        $student = User::factory()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($student)->get(route($routeName, ['user' => $target]));

        $response->assertForbidden()->assertSee('Access denied');
    }

    #[DataProvider('protectedPages')]
    public function test_guest_is_redirected_to_login_from_protected_page(string $routeName): void
    {
        $target = User::factory()->create();

        $response = $this->get(route($routeName, ['user' => $target]));

        $response->assertRedirect(route('login'));
    }

    public function test_student_can_open_the_student_dashboard(): void
    {
        $student = User::factory()->create();

        $response = $this->actingAs($student)->get(route('student.dashboard'));

        $response->assertOk()->assertSee('Student/Tester dashboard');
    }

    public function test_administrator_is_forbidden_from_the_student_dashboard(): void
    {
        $administrator = User::factory()->administrator()->create();

        $response = $this->actingAs($administrator)->get(route('student.dashboard'));

        $response->assertForbidden();
    }

    public function test_student_navigation_does_not_link_to_account_management(): void
    {
        $student = User::factory()->create();

        $response = $this->actingAs($student)->get(route('student.dashboard'));

        $response->assertOk()->assertDontSee(route('admin.users.index'));
    }
}
