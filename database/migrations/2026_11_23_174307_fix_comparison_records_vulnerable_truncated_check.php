<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrects comparison_records_vulnerable_truncated_check (PostgreSQL only).
 *
 * The original condition `NOT vulnerable_truncated OR vulnerable_row_count = 50`
 * evaluates to NULL, which a CHECK accepts, when Vulnerable Mode failed or
 * was refused (row count NULL), so `vulnerable_truncated = true` could be
 * stored without any rows. IS NOT DISTINCT FROM never yields NULL, so
 * truncation now requires exactly 50 returned rows.
 *
 * Only this constraint is replaced, in a single ALTER TABLE statement. The
 * table, its rows and every other object are left unchanged. The SQLite test
 * database has no CHECK constraints (see the create migration), so this is
 * skipped there.
 */
return new class extends Migration
{
    private const string CONSTRAINT = 'comparison_records_vulnerable_truncated_check';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->replaceCheck('NOT vulnerable_truncated OR vulnerable_row_count IS NOT DISTINCT FROM 50');
    }

    /**
     * Reverse the migrations: restore the original expression.
     */
    public function down(): void
    {
        $this->replaceCheck('NOT vulnerable_truncated OR vulnerable_row_count = 50');
    }

    private function replaceCheck(string $condition): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE comparison_records DROP CONSTRAINT '.self::CONSTRAINT.', ADD CONSTRAINT '.self::CONSTRAINT." CHECK ({$condition})");
    }
};
