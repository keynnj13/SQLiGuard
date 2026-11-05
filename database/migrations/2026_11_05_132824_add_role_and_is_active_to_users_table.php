<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Role values are listed literally (not read from App\Enums\Role) so this
     * migration keeps its meaning if the enum changes later. New and existing
     * accounts default to the least-privileged role and to active.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['administrator', 'student'])->default('student');
            $table->boolean('is_active')->default(true);

            $table->index(['role', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Rolling back discards every account's role and status.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'is_active']);
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
