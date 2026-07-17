<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use staff invitation acceptance tokens (stage-03 plan, task
 * breakdown item 9). POST /v1/memberships (InviteUser) issues one and
 * mails the plaintext to the invitee; POST /v1/auth/staff/invitation/
 * accept redeems it exactly once.
 *
 * Platform-global like `users`, `mfa_recovery_codes`, and
 * `staff_password_reset_tokens` (Rls::grantUnscoped): an acceptance token
 * is authentication infrastructure keyed to a staff identity, not
 * tenant-scoped domain data, so it carries no tenant_id and no RLS even
 * though InviteUser mints it inside a tenant-scoped request.
 *
 * consumed_at makes acceptance single-use, mirroring
 * staff_password_reset_tokens exactly: atomic consumption is `UPDATE
 * staff_invitation_tokens SET consumed_at = now() WHERE token_hash = ? AND
 * consumed_at IS NULL AND expires_at > now()`, checked by affected-row
 * count, never read-then-write (master plan test-first rule 2),
 * implemented by App\Identity\Actions\ConsumeInvitationToken. Without it a
 * still-valid token replays into a password reset for as long as it lives,
 * taking over the account even after the legitimate recipient accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invitation_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Cascade, not the sibling reset table's default restrict: an
            // acceptance token is meaningless without its user, and
            // InviteUser mints one on every invite, so a restrict FK would
            // block the user-teardown many suites already run.
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index('token_hash');
        });

        Rls::grantUnscoped('staff_invitation_tokens');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitation_tokens');
    }
};
