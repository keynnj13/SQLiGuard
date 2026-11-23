<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demonstration history: one row per completed Vulnerable/Secure comparison,
 * in `public.comparison_records` on the application connection.
 *
 * Metadata only. There is deliberately no column for the submitted input,
 * passwords, generated SQL, bound parameters, returned rows or raw database
 * errors. Rows are immutable (no updated_at) and stay attributable to their
 * account: deleting an account that has history is restricted.
 *
 * Allowed values are listed literally (not read from the enums) so this
 * migration keeps its meaning if an enum changes later. The named CHECK
 * constraints are PostgreSQL-only: the schema builder has no CHECK support
 * and SQLite cannot add constraints to an existing table, so the in-memory
 * SQLite test database gets the same table without them.
 */
return new class extends Migration
{
    private const string ERROR_CATEGORIES = "'syntax_error', 'permission_denied', 'statement_timeout', 'read_only_violation', 'invalid_object_reference', 'invalid_value', 'unexpected'";

    /**
     * Named CHECK constraints (PostgreSQL), name => condition.
     *
     * @var array<string, string>
     */
    private const array CHECKS = [
        // Allowed values.
        'comparison_records_demonstration_type_check' => "demonstration_type IN ('tautology', 'union', 'comment')",
        'comparison_records_classification_category_check' => "classification_category IN ('normal', 'tautology', 'union', 'comment', 'unknown')",
        'comparison_records_classification_rules_check' => "jsonb_typeof(classification_rules) = 'object'",
        'comparison_records_classifier_version_check' => 'classifier_version >= 1',
        'comparison_records_vulnerable_status_check' => "vulnerable_status IN ('executed', 'failed', 'refused')",
        'comparison_records_secure_status_check' => "secure_status IN ('executed', 'failed')",
        'comparison_records_vulnerable_error_category_check' => 'vulnerable_error_category IN ('.self::ERROR_CATEGORIES.')',
        'comparison_records_secure_error_category_check' => 'secure_error_category IN ('.self::ERROR_CATEGORIES.')',
        'comparison_records_vulnerable_row_count_range_check' => 'vulnerable_row_count BETWEEN 0 AND 50',
        'comparison_records_secure_row_count_range_check' => 'secure_row_count BETWEEN 0 AND 50',
        'comparison_records_vulnerable_duration_check' => 'vulnerable_duration_us >= 0',
        'comparison_records_secure_duration_check' => 'secure_duration_us >= 0',

        // Each mode's fields agree with its status.
        'comparison_records_vulnerable_row_count_status_check' => "(vulnerable_status = 'executed') = (vulnerable_row_count IS NOT NULL)",
        'comparison_records_secure_row_count_status_check' => "(secure_status = 'executed') = (secure_row_count IS NOT NULL)",
        'comparison_records_vulnerable_error_status_check' => "(vulnerable_status = 'failed') = (vulnerable_error_category IS NOT NULL)",
        'comparison_records_secure_error_status_check' => "(secure_status = 'failed') = (secure_error_category IS NOT NULL)",
        'comparison_records_vulnerable_duration_status_check' => "(vulnerable_status = 'refused') = (vulnerable_duration_us IS NULL)",
        // The executor fetches ROW_LIMIT + 1 rows and keeps 50, so truncation implies exactly 50.
        'comparison_records_vulnerable_truncated_check' => 'NOT vulnerable_truncated OR vulnerable_row_count = 50',

        // Mirrors Comparison::inputAlteredQuery(): refused -> NULL, vulnerable failed -> TRUE,
        // secure failed -> NULL, both executed -> TRUE or FALSE.
        'comparison_records_results_differ_check' => "CASE WHEN vulnerable_status = 'refused' THEN results_differ IS NULL WHEN vulnerable_status = 'failed' THEN results_differ IS TRUE WHEN secure_status = 'executed' THEN results_differ IS NOT NULL ELSE results_differ IS NULL END",
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('comparison_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('demonstration_type', 20);
            $table->string('classification_category', 20);
            $table->jsonb('classification_rules');
            $table->smallInteger('classifier_version');
            $table->string('vulnerable_status', 10);
            $table->smallInteger('vulnerable_row_count')->nullable();
            $table->boolean('vulnerable_truncated')->default(false);
            $table->string('vulnerable_error_category', 30)->nullable();
            $table->integer('vulnerable_duration_us')->nullable();
            $table->string('secure_status', 10);
            $table->smallInteger('secure_row_count')->nullable();
            $table->string('secure_error_category', 30)->nullable();
            $table->integer('secure_duration_us');
            $table->boolean('results_differ')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // B-tree indexes are scanned backwards for newest-first ordering.
            $table->index(['user_id', 'created_at', 'id'], 'comparison_records_user_history_index');
            $table->index(['created_at', 'id'], 'comparison_records_history_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::CHECKS as $name => $condition) {
            DB::statement("ALTER TABLE comparison_records ADD CONSTRAINT {$name} CHECK ({$condition})");
        }
    }

    /**
     * Reverse the migrations.
     *
     * Rolling back discards all demonstration history.
     */
    public function down(): void
    {
        Schema::dropIfExists('comparison_records');
    }
};
