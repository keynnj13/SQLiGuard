<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Creates an administrator account interactively. This is the only way to
 * create the first account, because SQLiGuard has no public registration.
 *
 * The password is read with hidden input, validated against the application
 * password policy, stored only as a hash (User `hashed` cast), and never
 * written to the console or logs.
 */
#[Signature('sqliguard:create-admin')]
#[Description('Create an administrator account (prompts for name, email and a hidden password)')]
class CreateAdministrator extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command prompts for a password and must be run interactively.');

            return self::FAILURE;
        }

        if (User::query()->activeAdministrators()->exists()
            && ! $this->confirm('An active administrator already exists. Create another administrator account?')) {
            $this->info('No account was created.');

            return self::SUCCESS;
        }

        $input = [
            'name' => trim((string) $this->ask('Name')),
            'email' => Str::lower(trim((string) $this->ask('Email address'))),
            'password' => (string) $this->secret('Password (input is hidden)'),
            'password_confirmation' => (string) $this->secret('Confirm password'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            $this->line('No account was created.');

            return self::FAILURE;
        }

        $user = new User;
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => Role::Administrator,
            'is_active' => true,
        ]);

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            $this->error('An account with this email address already exists. No account was created.');

            return self::FAILURE;
        }

        $this->info("Administrator account created for {$user->email}.");

        return self::SUCCESS;
    }
}
