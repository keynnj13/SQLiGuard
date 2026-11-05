<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fake demonstration tables in the dedicated `lab` schema (PostgreSQL only).
 *
 * Runs on the application connection (sqliguard_app owns the `lab` schema).
 * The restricted sqliguard_lab account receives exactly:
 *   - SELECT on lab.demo_accounts, lab.demo_products,
 *     lab.demo_internal_projects and lab.demo_documents;
 *   - EXECUTE on lab.demo_password_matches(bigint, text).
 * It receives nothing on lab.demo_account_credentials, so no injection can
 * read the fake password digests. No default privileges are created.
 *
 * Skipped on other drivers: the in-memory SQLite test database has no `lab`
 * schema, and demonstration queries always run on the real `lab` connection.
 */
return new class extends Migration
{
    /**
     * Tables the lab account may read, each granted explicitly below.
     */
    private const array LAB_READABLE_TABLES = [
        'demo_accounts',
        'demo_products',
        'demo_internal_projects',
        'demo_documents',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Tautology demonstration: a fake login dataset. Passwords are kept in
        // a separate table that the lab account cannot read.
        Schema::create('lab.demo_accounts', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('username', 64)->unique();
            $table->string('display_name', 100);
            $table->string('account_type', 20);
        });

        Schema::create('lab.demo_account_credentials', function (Blueprint $table) {
            $table->bigInteger('account_id')->primary();
            $table->char('password_digest', 64);
            $table->foreign('account_id')->references('id')->on('lab.demo_accounts')->cascadeOnDelete();
        });

        // UNION demonstration: a product catalogue, plus designated fake
        // "internal" records that the catalogue query must never show.
        Schema::create('lab.demo_products', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('name', 100);
            $table->string('category', 50);
            $table->decimal('price', 8, 2);
            $table->boolean('is_published');
        });

        Schema::create('lab.demo_internal_projects', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('codename', 100);
            $table->string('classification', 50);
            $table->decimal('budget', 12, 2);
        });

        // Comment demonstration: documents filtered by team and visibility.
        Schema::create('lab.demo_documents', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('title', 150);
            $table->string('owner_team', 50);
            $table->enum('visibility', ['public', 'private']);
        });

        // Boolean password check for the fake login. SECURITY DEFINER lets the
        // lab account verify a password without being able to read any digest.
        // The body has no dynamic SQL and the search_path is pinned.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION lab.demo_password_matches(p_account_id bigint, p_password text)
            RETURNS boolean
            LANGUAGE sql
            STABLE
            STRICT
            SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
                SELECT EXISTS (
                    SELECT 1
                    FROM lab.demo_account_credentials AS c
                    WHERE c.account_id = p_account_id
                      AND c.password_digest = encode(sha256(convert_to(p_password, 'UTF8')), 'hex')
                )
            $$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION lab.demo_password_matches(bigint, text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION lab.demo_password_matches(bigint, text) TO sqliguard_lab');

        DB::statement('REVOKE ALL ON lab.demo_account_credentials FROM PUBLIC');

        foreach (self::LAB_READABLE_TABLES as $table) {
            DB::statement("GRANT SELECT ON lab.{$table} TO sqliguard_lab");
        }
    }

    /**
     * Reverse the migrations.
     *
     * Drops the demonstration tables, their fake data and the function.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP FUNCTION IF EXISTS lab.demo_password_matches(bigint, text)');

        Schema::dropIfExists('lab.demo_documents');
        Schema::dropIfExists('lab.demo_internal_projects');
        Schema::dropIfExists('lab.demo_products');
        Schema::dropIfExists('lab.demo_account_credentials');
        Schema::dropIfExists('lab.demo_accounts');
    }
};
