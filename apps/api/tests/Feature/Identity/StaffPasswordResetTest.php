<?php

use App\Identity\Mail\PasswordResetMail;
use App\Identity\Models\StaffPasswordResetToken;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, Slice 8 (task breakdown item 16): POST
 * /v1/auth/staff/password/reset and .../reset/confirm. Every case invites
 * the reset token by mailing it through the real endpoint and recovering
 * it from Mail::fake()'s captured PasswordResetMail, mirroring
 * tests/Feature/Identity/CustomerClaimTest.php's own "prove the endpoint
 * end to end" precedent. Unlike ClaimToken/InvitationToken, the reset
 * token is single-use and tracked in the staff_password_reset_tokens
 * table (stage-03 plan, Slice 8 Concurrency), so a consumed token cannot
 * be replayed the way a still-valid claim or invitation token can.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    // staff_password_reset_tokens.user_id is a real foreign key
    // (2026_07_10_000019_create_staff_password_reset_tokens_table.php),
    // unlike the Passport oauth token tables, so it must be cleared
    // before the blanket User delete below or it fails the constraint.
    StaffPasswordResetToken::query()->delete();
    User::query()->delete();
});

/**
 * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
 */
function issueResetSubjectTokenPair(string $email): array
{
    /** @var array{access_token: string, refresh_token: string, token_type: string, expires_in: int} $pair */
    $pair = test()->postJson('/v1/auth/staff/token', [
        'email' => $email,
        'password' => 'the-old-password',
    ])->json();

    return $pair;
}

function requestPasswordResetToken(string $email): string
{
    Mail::fake();

    test()->postJson('/v1/auth/staff/password/reset', ['email' => $email])->assertStatus(202);

    $token = null;

    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($email, &$token): bool {
        if (! $mail->hasTo($email)) {
            return false;
        }

        $token = $mail->token;

        return true;
    });

    Mail::fake();

    return $token;
}

describe('POST /v1/auth/staff/password/reset', function (): void {
    it('renders 202 and mails a token for a known email', function (): void {
        $user = User::factory()->create(['email' => 'known-reset@example.com']);

        Mail::fake();

        test()->postJson('/v1/auth/staff/password/reset', ['email' => $user->email])
            ->assertStatus(202)
            ->assertConformsToOpenApi();

        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->hasTo($user->email));
    });

    it('renders 202 for an unknown email without mailing anything, so the response never reveals which', function (): void {
        Mail::fake();

        test()->postJson('/v1/auth/staff/password/reset', ['email' => 'unknown-reset@example.com'])
            ->assertStatus(202);

        Mail::assertNothingSent();
    });

    it('fails request validation for a malformed email', function (): void {
        test()->postJson('/v1/auth/staff/password/reset', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'request.validation_failed']);
    });
});

describe('POST /v1/auth/staff/password/reset/confirm', function (): void {
    it('sets the new password, stops the old one from working, and revokes every live access and refresh token', function (): void {
        $user = User::factory()->create(['email' => 'confirm-reset@example.com', 'password' => 'the-old-password']);
        $pair = issueResetSubjectTokenPair($user->email);

        $token = requestPasswordResetToken($user->email);

        test()->postJson('/v1/auth/staff/password/reset/confirm', [
            'token' => $token,
            'password' => 'a-brand-new-password',
        ])
            ->assertNoContent()
            ->assertConformsToOpenApi();

        test()->postJson('/v1/auth/staff/token', [
            'email' => $user->email,
            'password' => 'the-old-password',
        ])->assertStatus(401);

        test()->postJson('/v1/auth/staff/token', [
            'email' => $user->email,
            'password' => 'a-brand-new-password',
        ])->assertOk();

        // Illuminate\Auth\AuthManager caches a resolved guard for the life of
        // the container (tests/Feature/Identity/StaffRefreshTest.php's own
        // precedent, "revokes every live token in the family" case).
        Auth::forgetGuards();

        test()->getJson('/v1/me', ['Authorization' => 'Bearer '.$pair['access_token']])
            ->assertStatus(401)
            ->assertJson(['code' => 'auth.unauthenticated']);

        // App\Identity\OAuth\IdentityRefreshTokenRepository::isRefreshTokenRevoked()
        // treats any already-revoked token as reuse (its own docblock),
        // which is exactly what App\Identity\Actions\RevokeAllUserTokens
        // just made this one, so this renders refresh_token_reused rather
        // than invalid_refresh_token; either way it is a 401 and the
        // stale refresh token no longer mints a new pair, which is the
        // invariant this task's plan actually mandates.
        test()->postJson('/v1/auth/staff/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertStatus(401)
            ->assertJson(['code' => 'refresh_token_reused']);
    });

    it('rejects a tampered or unknown token with reset_token_invalid', function (): void {
        test()->postJson('/v1/auth/staff/password/reset/confirm', [
            'token' => 'not-a-real-token',
            'password' => 'a-brand-new-password',
        ])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'reset_token_invalid']);
    });

    it('rejects an expired token with reset_token_expired under the fake clock', function (): void {
        $user = User::factory()->create(['email' => 'expired-reset@example.com']);
        $token = requestPasswordResetToken($user->email);

        test()->travel(config()->integer('identity.reset_token_ttl_minutes') + 1)->minutes();

        test()->postJson('/v1/auth/staff/password/reset/confirm', [
            'token' => $token,
            'password' => 'a-brand-new-password',
        ])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'reset_token_expired']);
    });

    it('rejects a second confirmation of an already-consumed token with reset_token_invalid', function (): void {
        $user = User::factory()->create(['email' => 'reused-reset@example.com']);
        $token = requestPasswordResetToken($user->email);

        test()->postJson('/v1/auth/staff/password/reset/confirm', [
            'token' => $token,
            'password' => 'first-new-password',
        ])->assertNoContent();

        test()->postJson('/v1/auth/staff/password/reset/confirm', [
            'token' => $token,
            'password' => 'second-new-password',
        ])
            ->assertStatus(401)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'reset_token_invalid']);
    });

    it('fails request validation for a missing token or a too-short password', function (): void {
        test()->postJson('/v1/auth/staff/password/reset/confirm', ['token' => '', 'password' => 'x'])
            ->assertStatus(422)
            ->assertConformsToOpenApi()
            ->assertJson(['code' => 'request.validation_failed']);
    });
});
