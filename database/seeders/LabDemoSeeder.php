<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deterministic, clearly fictitious records for the lab demonstration tables.
 *
 * Repeatable: every run replaces the lab tables' contents with exactly these
 * rows (same IDs, no duplicates), inside one transaction on the application
 * connection. It never touches application tables.
 * Run with `php artisan db:seed --class=LabDemoSeeder`.
 *
 * Fake credentials: only demo.analyst has a known password, because it is
 * published as a sample input. Every other fake account receives a new
 * cryptographically random password on each run; it is hashed immediately
 * and never stored, printed or logged, so nobody knows it. The digests are
 * unsalted SHA-256, which is acceptable ONLY for this fake lab data (see
 * docs/architecture.md section 4.4). Real SQLiGuard accounts use bcrypt.
 */
class LabDemoSeeder extends Seeder
{
    public const int PUBLISHED_SAMPLE_ACCOUNT_ID = 2;

    /**
     * The one fake password that is intentionally public (tautology sample input).
     */
    public const string PUBLISHED_SAMPLE_PASSWORD = 'Analyst-Lab-2026';

    public const int RANDOM_PASSWORD_LENGTH = 20;

    /**
     * Fake lab accounts, keyed by their fixed IDs.
     *
     * @var array<int, array{username: string, display_name: string, account_type: string}>
     */
    private const array DEMO_ACCOUNTS = [
        1 => ['username' => 'demo.admin', 'display_name' => 'Demo Administrator (fictional)', 'account_type' => 'administrator'],
        2 => ['username' => 'demo.analyst', 'display_name' => 'Demo Analyst (fictional)', 'account_type' => 'analyst'],
        3 => ['username' => 'demo.student', 'display_name' => 'Demo Student (fictional)', 'account_type' => 'student'],
        4 => ['username' => 'demo.guest', 'display_name' => 'Demo Guest (fictional)', 'account_type' => 'guest'],
    ];

    /**
     * A new unguessable password (letters, numbers and symbols chosen with
     * random_int(), a CSPRNG). Callers must hash it and discard it.
     */
    public static function randomPassword(): string
    {
        return Str::password(self::RANDOM_PASSWORD_LENGTH);
    }

    /**
     * SHA-256 hex digest of the account's fake password. The random
     * passwords exist only inside this call.
     */
    private static function passwordDigestFor(int $accountId): string
    {
        $password = $accountId === self::PUBLISHED_SAMPLE_ACCOUNT_ID
            ? self::PUBLISHED_SAMPLE_PASSWORD
            : self::randomPassword();

        return hash('sha256', $password);
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $connection = DB::connection('pgsql');

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->transaction(function () use ($connection): void {
            $connection->table('lab.demo_account_credentials')->delete();
            $connection->table('lab.demo_accounts')->delete();
            $connection->table('lab.demo_products')->delete();
            $connection->table('lab.demo_internal_projects')->delete();
            $connection->table('lab.demo_documents')->delete();

            $connection->table('lab.demo_accounts')->insert(array_map(
                fn (int $id, array $account): array => ['id' => $id, ...$account],
                array_keys(self::DEMO_ACCOUNTS),
                self::DEMO_ACCOUNTS,
            ));

            $connection->table('lab.demo_account_credentials')->insert(array_map(
                fn (int $id): array => ['account_id' => $id, 'password_digest' => self::passwordDigestFor($id)],
                array_keys(self::DEMO_ACCOUNTS),
            ));

            $connection->table('lab.demo_products')->insert([
                ['id' => 1, 'name' => 'Demo Notebook A5', 'category' => 'stationery', 'price' => '3.50', 'is_published' => true],
                ['id' => 2, 'name' => 'Demo Gel Pen Set', 'category' => 'stationery', 'price' => '5.25', 'is_published' => true],
                ['id' => 3, 'name' => 'Demo Desk Organizer', 'category' => 'stationery', 'price' => '12.00', 'is_published' => true],
                ['id' => 4, 'name' => 'Demo Prototype Smart Pen', 'category' => 'stationery', 'price' => '99.00', 'is_published' => false],
                ['id' => 5, 'name' => 'Demo USB-C Cable', 'category' => 'electronics', 'price' => '7.99', 'is_published' => true],
                ['id' => 6, 'name' => 'Demo Wireless Mouse', 'category' => 'electronics', 'price' => '18.40', 'is_published' => true],
            ]);

            $connection->table('lab.demo_internal_projects')->insert([
                ['id' => 101, 'codename' => 'FAKE Project Kestrel', 'classification' => 'internal-only', 'budget' => '125000.00'],
                ['id' => 102, 'codename' => 'FAKE Project Lantern', 'classification' => 'confidential', 'budget' => '480000.00'],
                ['id' => 103, 'codename' => 'FAKE Project Meridian', 'classification' => 'restricted', 'budget' => '72500.00'],
            ]);

            $connection->table('lab.demo_documents')->insert([
                ['id' => 1, 'title' => 'Team Alpha public roadmap (fictional)', 'owner_team' => 'team-alpha', 'visibility' => 'public'],
                ['id' => 2, 'title' => 'Team Alpha draft budget (fictional)', 'owner_team' => 'team-alpha', 'visibility' => 'private'],
                ['id' => 3, 'title' => 'Team Alpha incident notes (fictional)', 'owner_team' => 'team-alpha', 'visibility' => 'private'],
                ['id' => 4, 'title' => 'Team Beta public FAQ (fictional)', 'owner_team' => 'team-beta', 'visibility' => 'public'],
                ['id' => 5, 'title' => 'Team Beta hiring plan (fictional)', 'owner_team' => 'team-beta', 'visibility' => 'private'],
            ]);
        });
    }
}
