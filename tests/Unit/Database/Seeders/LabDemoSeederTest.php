<?php

namespace Tests\Unit\Database\Seeders;

use Database\Seeders\LabDemoSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Generated fake passwords are never printed: every assertion compares
 * lengths, counts or booleans, so a failure message cannot reveal one.
 */
class LabDemoSeederTest extends TestCase
{
    public function test_random_passwords_have_the_configured_length(): void
    {
        $password = LabDemoSeeder::randomPassword();

        $this->assertSame(LabDemoSeeder::RANDOM_PASSWORD_LENGTH, strlen($password));
        $this->assertSame(20, LabDemoSeeder::RANDOM_PASSWORD_LENGTH);
    }

    public function test_random_passwords_always_contain_a_letter_a_number_and_a_symbol(): void
    {
        // Str::password() guarantees one character from each pool (letters,
        // numbers, symbols); upper and lower case share the letters pool.
        foreach (range(1, 50) as $attempt) {
            $password = LabDemoSeeder::randomPassword();

            $classes = [
                'letter' => preg_match('/[a-zA-Z]/', $password) === 1,
                'number' => preg_match('/[0-9]/', $password) === 1,
                'symbol' => preg_match('/[^a-zA-Z0-9]/', $password) === 1,
            ];

            $this->assertSame(['letter' => true, 'number' => true, 'symbol' => true], $classes);
        }
    }

    public function test_random_passwords_are_not_repeated(): void
    {
        $passwords = array_map(fn (): string => LabDemoSeeder::randomPassword(), range(1, 50));

        $this->assertSame(50, count(array_unique($passwords)), 'Generated passwords were repeated.');
    }

    public function test_the_published_sample_password_is_unchanged(): void
    {
        $this->assertSame('Analyst-Lab-2026', LabDemoSeeder::PUBLISHED_SAMPLE_PASSWORD);
        $this->assertSame(2, LabDemoSeeder::PUBLISHED_SAMPLE_ACCOUNT_ID);
    }
}
