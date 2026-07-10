<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use MFA recovery codes (stage-03 plan, Data model
 * "mfa_recovery_codes"; task breakdown item 10). Platform-global like
 * `users` and the Passport oauth tables (Rls::grantUnscoped): a recovery
 * code is authentication infrastructure keyed to a staff identity, not
 * tenant-scoped domain data, so it carries no tenant_id and no RLS.
 *
 * A JSON column on `users` could not express atomic single-use
 * consumption as a conditional UPDATE checked by affected-row count, so
 * each code is its own row (stage-03 plan, Data model). Consumption is
 * `UPDATE mfa_recovery_codes SET used_at = now() WHERE user_id = ? AND
 * code_hash = ? AND used_at IS NULL`, checked by affected-row count,
 * never read-then-write (master plan test-first rule 2); that guard,
 * App\Identity\Actions\ConsumeRecoveryCode, and the hashing it depends on
 * do not exist yet (task breakdown item 11, Slice 5) — this task ships
 * only the schema, plus failing unit and concurrency tests for the guard
 * written ahead of it (task breakdown item 10's own wording).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_recovery_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->string('code_hash');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index('user_id');
        });

        Rls::grantUnscoped('mfa_recovery_codes');
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_recovery_codes');
    }
};
