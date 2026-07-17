<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFA enrollment state for staff (stage-03 plan, Data model "users"; task
 * breakdown item 10; system-design 5.1, 8.1). users carries no tenant_id
 * and no RLS (ADR 007, the 0001_01_01 migration and
 * 2026_07_09_000011_grant_users_table_to_rls_roles.php already establish
 * this), so these three columns need no new grant: the table-level grant
 * that migration issued covers every column, including these.
 *
 * mfa_secret is nullable text, encrypted at the application layer via the
 * User model's `encrypted` cast (App\Models\User), not at the database
 * layer: Laravel's encrypted cast round-trips through
 * Illuminate\Contracts\Encryption\Encrypter on every read and write, so
 * the column itself just needs to be wide enough for ciphertext, which
 * `text` guarantees regardless of secret length.
 *
 * Slice 5 (task breakdown item 11) builds the enrollment, confirmation,
 * and enforcement logic that reads and writes these columns; this task
 * ships only the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('mfa_enabled')->default(false)->after('password');
            $table->text('mfa_secret')->nullable()->after('mfa_enabled');
            $table->timestampTz('mfa_confirmed_at')->nullable()->after('mfa_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['mfa_enabled', 'mfa_secret', 'mfa_confirmed_at']);
        });
    }
};
