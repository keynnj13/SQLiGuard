<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Deliberately creates no user accounts: a seeded account would have a
     * known password. Create the first administrator with
     * `php artisan sqliguard:create-admin`, then manage accounts in the app.
     *
     * Seeds only the fictitious lab demonstration data (repeatable).
     */
    public function run(): void
    {
        $this->call(LabDemoSeeder::class);
    }
}
