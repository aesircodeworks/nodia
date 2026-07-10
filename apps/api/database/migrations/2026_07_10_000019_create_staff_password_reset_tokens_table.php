<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use staff password reset tokens (stage-03 plan, task breakdown
 * item 16). Named `staff_password_reset_tokens`, not the bare
 * `password_reset_tokens` Laravel's own default scaffold already claims
 * (0001_01_01_000000_create_users_table.php's own unused
 * email/token/created_at table for the framework's stock
 * Illuminate\Auth\Passwords broker, never wired into this application):
 * reusing that name collides at the database level, confirmed the hard
 * way against a real migrate:fresh before settling on this one.
 *
 * Platform-global like `users`, `mfa_recovery_codes`, and the Passport
 * oauth tables (Rls::grantUnscoped): a reset token is authentication
 * infrastructure keyed to a staff identity, not tenant-scoped domain
 * data, so it carries no tenant_id and no RLS.
 *
 * Unlike App\Identity\Support\ClaimToken/InvitationToken (stateless,
 * sealed with the application's own encrypter), this token must be
 * single-use: the stage plan's error code list distinguishes an unknown or
 * already-consumed token (reset_token_invalid) from one whose embedded
 * expiry has passed (reset_token_expired), which a stateless token with no
 * database row could never enforce beyond "still cryptographically
 * valid." consumed_at mirrors mfa_recovery_codes.used_at exactly: atomic
 * single-use consumption is `UPDATE staff_password_reset_tokens SET
 * consumed_at = now() WHERE token_hash = ? AND consumed_at IS NULL AND
 * expires_at > now()`, checked by affected-row count, never
 * read-then-write (master plan test-first rule 2), implemented by
 * App\Identity\Actions\ConsumePasswordResetToken and proven by
 * tests/Concurrency/PasswordResetTokenConsumptionContentionTest.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_password_reset_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained();
            $table->string('token_hash');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index('token_hash');
        });

        Rls::grantUnscoped('staff_password_reset_tokens');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_password_reset_tokens');
    }
};
