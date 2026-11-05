<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string PASSWORD = 'Valid-Lab-Pass-42!';

    private const string LAST_ADMINISTRATOR_MESSAGE = 'At least one active administrator must remain. Activate or promote another administrator first.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array<string, mixed>
     */
    private function newAccountPayload(array $overrides = []): array
    {
        return [
            'name' => 'Grace Tester',
            'email' => 'grace@example.test',
            'role' => Role::Student->value,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            ...$overrides,
        ];
    }

    public function test_account_list_shows_accounts_without_password_hashes(): void
    {
        $administrator = User::factory()->administrator()->create();
        $student = User::factory()->create(['name' => 'Listed Student']);

        $response = $this->actingAs($administrator)->get(route('admin.users.index'));

        $response->assertOk()
            ->assertSee('Listed Student')
            ->assertSee($student->email)
            ->assertDontSee($student->password)
            ->assertDontSee($administrator->password);
    }

    public function test_account_list_escapes_user_supplied_names(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->create(['name' => '<script>alert("x")</script>']);

        $response = $this->actingAs($administrator)->get(route('admin.users.index'));

        $response->assertOk()
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert("x")</script>', false);
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

    #[DataProvider('roles')]
    public function test_administrator_creates_an_active_account_with_the_chosen_role_and_a_hashed_password(Role $role): void
    {
        $administrator = User::factory()->administrator()->create();

        $response = $this->actingAs($administrator)
            ->post(route('admin.users.store'), $this->newAccountPayload(['role' => $role->value]));

        $response->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', 'Account created for grace@example.test.');
        $created = User::query()->where('email', 'grace@example.test')->sole();
        $this->assertSame($role, $created->role);
        $this->assertTrue($created->is_active);
        $this->assertNotSame(self::PASSWORD, $created->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $created->password));
    }

    public function test_duplicate_email_is_rejected_regardless_of_case(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->create(['email' => 'grace@example.test']);

        $response = $this->actingAs($administrator)
            ->post(route('admin.users.store'), $this->newAccountPayload(['email' => 'Grace@Example.TEST']));

        $response->assertSessionHasErrors(['email' => 'The email has already been taken.']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_weak_password_is_rejected(): void
    {
        $administrator = User::factory()->administrator()->create();

        $response = $this->actingAs($administrator)->post(
            route('admin.users.store'),
            $this->newAccountPayload(['password' => 'short', 'password_confirmation' => 'short']),
        );

        $response->assertSessionHasErrors(['password' => 'The password field must be at least 12 characters.']);
        $this->assertDatabaseMissing('users', ['email' => 'grace@example.test']);
    }

    public function test_unknown_role_is_rejected(): void
    {
        $administrator = User::factory()->administrator()->create();

        $response = $this->actingAs($administrator)
            ->post(route('admin.users.store'), $this->newAccountPayload(['role' => 'superuser']));

        $response->assertSessionHasErrors(['role' => 'The selected role is invalid.']);
        $this->assertDatabaseMissing('users', ['email' => 'grace@example.test']);
    }

    public function test_administrator_edits_name_email_and_role_of_another_account(): void
    {
        $administrator = User::factory()->administrator()->create();
        $student = User::factory()->create();

        $response = $this->actingAs($administrator)->put(route('admin.users.update', $student), [
            'name' => 'Renamed Tester',
            'email' => 'renamed@example.test',
            'role' => Role::Administrator->value,
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $student->refresh();
        $this->assertSame('Renamed Tester', $student->name);
        $this->assertSame('renamed@example.test', $student->email);
        $this->assertSame(Role::Administrator, $student->role);
    }

    public function test_administrator_sets_a_new_hashed_password_for_another_account(): void
    {
        $administrator = User::factory()->administrator()->create();
        $student = User::factory()->create();

        $this->actingAs($administrator)->put(route('admin.users.update', $student), [
            'name' => $student->name,
            'email' => $student->email,
            'role' => Role::Student->value,
            'password' => 'New-Lab-Pass-77?',
            'password_confirmation' => 'New-Lab-Pass-77?',
        ]);

        $this->assertTrue(Hash::check('New-Lab-Pass-77?', $student->refresh()->password));
    }

    public function test_blank_password_on_edit_keeps_the_current_password(): void
    {
        $administrator = User::factory()->administrator()->create();
        $student = User::factory()->create();
        $originalHash = $student->password;

        $this->actingAs($administrator)->put(route('admin.users.update', $student), [
            'name' => 'Still Same Password',
            'email' => $student->email,
            'role' => Role::Student->value,
            'password' => '',
            'password_confirmation' => '',
        ]);

        $this->assertSame($originalHash, $student->refresh()->password);
    }

    public function test_edit_form_for_own_account_does_not_offer_the_role_or_expose_the_hash(): void
    {
        $administrator = User::factory()->administrator()->create();

        $response = $this->actingAs($administrator)->get(route('admin.users.edit', $administrator));

        $response->assertOk()
            ->assertSee('You cannot change your own role.')
            ->assertDontSee('name="role"', false)
            ->assertDontSee($administrator->password);
    }

    public function test_administrator_cannot_change_their_own_role(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->administrator()->create();

        $response = $this->actingAs($administrator)->put(route('admin.users.update', $administrator), [
            'name' => $administrator->name,
            'email' => $administrator->email,
            'role' => Role::Student->value,
        ]);

        $response->assertSessionHasErrors(['role' => 'You cannot change your own role.']);
        $this->assertSame(Role::Administrator, $administrator->refresh()->role);
    }

    public function test_last_active_administrator_cannot_be_demoted(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->administrator()->inactive()->create();

        $response = $this->actingAs($administrator)->put(route('admin.users.update', $administrator), [
            'name' => $administrator->name,
            'email' => $administrator->email,
            'role' => Role::Student->value,
        ]);

        $response->assertSessionHasErrors(['role' => self::LAST_ADMINISTRATOR_MESSAGE]);
        $this->assertSame(Role::Administrator, $administrator->refresh()->role);
    }

    public function test_administrator_can_demote_another_administrator_while_one_remains(): void
    {
        $administrator = User::factory()->administrator()->create();
        $otherAdministrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)->put(route('admin.users.update', $otherAdministrator), [
            'name' => $otherAdministrator->name,
            'email' => $otherAdministrator->email,
            'role' => Role::Student->value,
        ]);

        $this->assertSame(Role::Student, $otherAdministrator->refresh()->role);
    }

    public function test_administrator_changing_their_own_password_stays_logged_in(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)->put(route('admin.users.update', $administrator), [
            'name' => $administrator->name,
            'email' => $administrator->email,
            'password' => 'New-Lab-Pass-77?',
            'password_confirmation' => 'New-Lab-Pass-77?',
        ]);
        $response = $this->get(route('admin.users.index'));

        $response->assertOk();
        $this->assertAuthenticatedAs($administrator);
    }

    public function test_administrator_deactivates_an_account(): void
    {
        $administrator = User::factory()->administrator()->create();
        $student = User::factory()->create();

        $response = $this->actingAs($administrator)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.deactivate', $student));

        $response->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', "Account deactivated for {$student->email}.");
        $this->assertFalse($student->refresh()->is_active);
    }

    public function test_administrator_activates_an_account(): void
    {
        $administrator = User::factory()->administrator()->create();
        $student = User::factory()->inactive()->create();

        $response = $this->actingAs($administrator)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.activate', $student));

        $response->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', "Account activated for {$student->email}.");
        $this->assertTrue($student->refresh()->is_active);
    }

    public function test_administrator_cannot_deactivate_their_own_account(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->administrator()->create();

        $response = $this->actingAs($administrator)->patch(route('admin.users.deactivate', $administrator));

        $response->assertSessionHasErrors(['account' => 'You cannot deactivate your own account.']);
        $this->assertTrue($administrator->refresh()->is_active);
    }

    public function test_last_active_administrator_cannot_be_deactivated(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->administrator()->inactive()->create();

        $response = $this->actingAs($administrator)->patch(route('admin.users.deactivate', $administrator));

        $response->assertSessionHasErrors(['account' => self::LAST_ADMINISTRATOR_MESSAGE]);
        $this->assertTrue($administrator->refresh()->is_active);
    }

    public function test_administrator_can_deactivate_another_administrator_while_one_remains(): void
    {
        $administrator = User::factory()->administrator()->create();
        $otherAdministrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)->patch(route('admin.users.deactivate', $otherAdministrator));

        $this->assertFalse($otherAdministrator->refresh()->is_active);
    }

    /**
     * State-changing account-management requests.
     *
     * @return array<string, array{string, string}>
     */
    public static function managementRequests(): array
    {
        return [
            'create' => ['post', 'admin.users.store'],
            'edit' => ['put', 'admin.users.update'],
            'activate' => ['patch', 'admin.users.activate'],
            'deactivate' => ['patch', 'admin.users.deactivate'],
        ];
    }

    #[DataProvider('managementRequests')]
    public function test_student_cannot_manage_accounts(string $method, string $routeName): void
    {
        $student = User::factory()->create();
        $target = User::factory()->inactive()->create(['name' => 'Untouched']);

        $response = $this->actingAs($student)->{$method}(
            route($routeName, ['user' => $target]),
            $this->newAccountPayload(['role' => Role::Administrator->value]),
        );

        $response->assertForbidden();
        $this->assertDatabaseCount('users', 2);
        $target->refresh();
        $this->assertSame('Untouched', $target->name);
        $this->assertSame(Role::Student, $target->role);
        $this->assertFalse($target->is_active);
        $this->assertSame(Role::Student, $student->refresh()->role);
    }

    public function test_guest_cannot_create_an_account(): void
    {
        $response = $this->post(route('admin.users.store'), $this->newAccountPayload());

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }
}
