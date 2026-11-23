<?php

namespace Tests\Feature\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The TrimStrings exception in bootstrap/app.php applies only to
 * demonstration submissions. Every other request keeps Laravel's trimming.
 */
class TrimStringsScopeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_account_management_input_is_still_trimmed(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)->post(route('admin.users.store'), [
            'name' => '   Padded Name   ',
            'email' => 'padded@example.test',
            'role' => Role::Student->value,
            'password' => 'Valid-Lab-Pass-42!',
            'password_confirmation' => 'Valid-Lab-Pass-42!',
        ]);

        $this->assertSame('Padded Name', User::query()->where('email', 'padded@example.test')->sole()->name);
    }

    public function test_account_edits_are_still_trimmed(): void
    {
        $administrator = User::factory()->administrator()->create();
        $student = User::factory()->create();

        $this->actingAs($administrator)->put(route('admin.users.update', $student), [
            'name' => "\t Renamed Student \t",
            'email' => $student->email,
            'role' => Role::Student->value,
        ]);

        $this->assertSame('Renamed Student', $student->refresh()->name);
    }
}
