<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdministratorTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string PASSWORD = 'Admin-Lab-Pass-42!';

    private const string CONFIRM_ANOTHER = 'An active administrator already exists. Create another administrator account?';

    public function test_creates_an_active_administrator_with_a_hashed_password(): void
    {
        $this->artisan('sqliguard:create-admin')
            ->expectsQuestion('Name', 'Ada Instructor')
            ->expectsQuestion('Email address', ' Ada@Example.TEST ')
            ->expectsQuestion('Password (input is hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutput('Administrator account created for ada@example.test.')
            ->doesntExpectOutputToContain(self::PASSWORD)
            ->assertSuccessful();

        $administrator = User::query()->where('email', 'ada@example.test')->sole();
        $this->assertSame('Ada Instructor', $administrator->name);
        $this->assertSame(Role::Administrator, $administrator->role);
        $this->assertTrue($administrator->is_active);
        $this->assertNotSame(self::PASSWORD, $administrator->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $administrator->password));
    }

    public function test_asks_for_confirmation_and_creates_nothing_when_an_active_administrator_exists(): void
    {
        User::factory()->administrator()->create();

        $this->artisan('sqliguard:create-admin')
            ->expectsConfirmation(self::CONFIRM_ANOTHER, 'no')
            ->expectsOutput('No account was created.')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_rejects_an_email_that_already_has_an_account(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->artisan('sqliguard:create-admin')
            ->expectsQuestion('Name', 'Duplicate')
            ->expectsQuestion('Email address', 'Taken@Example.test')
            ->expectsQuestion('Password (input is hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->expectsOutput('The email has already been taken.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_rejects_a_weak_password_without_echoing_it(): void
    {
        $this->artisan('sqliguard:create-admin')
            ->expectsQuestion('Name', 'Weak Password')
            ->expectsQuestion('Email address', 'weak@example.test')
            ->expectsQuestion('Password (input is hidden)', 'qwertyzz')
            ->expectsQuestion('Confirm password', 'qwertyzz')
            ->expectsOutput('The password field must be at least 12 characters.')
            ->doesntExpectOutputToContain('qwertyzz')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rejects_a_password_confirmation_that_does_not_match(): void
    {
        $this->artisan('sqliguard:create-admin')
            ->expectsQuestion('Name', 'Mismatch')
            ->expectsQuestion('Email address', 'mismatch@example.test')
            ->expectsQuestion('Password (input is hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm password', 'Different-Pass-42!')
            ->expectsOutput('The password field confirmation does not match.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_refuses_to_run_without_interaction(): void
    {
        $this->artisan('sqliguard:create-admin', ['--no-interaction' => true])
            ->expectsOutput('This command prompts for a password and must be run interactively.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
