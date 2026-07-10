<?php

use App\Identity\Actions\RequestPasswordReset;
use App\Identity\Data\RequestPasswordResetData;
use App\Identity\Mail\PasswordResetMail;
use App\Identity\Models\StaffPasswordResetToken;
use App\Identity\Support\PasswordResetTokenHasher;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, Slice 8 (task breakdown item 16): "Unit ... token TTL from
 * config under the fake clock; token hashing at rest." Driven directly
 * (tests/Feature/Identity/StaffPasswordResetTest.php already proves the
 * endpoint end to end and the no-enumeration behavior), mirroring
 * tests/Unit/Identity/ClaimGuestAccountTest.php's own precedent for
 * exercising an Action directly rather than through HTTP.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    StaffPasswordResetToken::query()->delete();
    User::query()->delete();
});

it('stores only the sha256 digest of the mailed token, with an expiry exactly config-driven minutes ahead', function () {
    // Frozen to a whole second: expires_at is a timestamp(0) column
    // (2026_07_10_000019_create_staff_password_reset_tokens_table.php),
    // which truncates the fractional seconds a plain freezeTime() leaves
    // on Date::now(), so comparing against an un-truncated instant would
    // spuriously fail.
    test()->freezeSecond();

    $user = User::factory()->create();

    Mail::fake();

    app(RequestPasswordReset::class)(new RequestPasswordResetData($user->email));

    $mailedToken = null;

    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user, &$mailedToken): bool {
        if (! $mail->hasTo($user->email)) {
            return false;
        }

        $mailedToken = $mail->token;

        return true;
    });

    $row = StaffPasswordResetToken::query()->where('user_id', $user->id)->firstOrFail();

    expect($row->token_hash)->toBe(PasswordResetTokenHasher::hash($mailedToken))
        ->and($row->token_hash)->not->toBe($mailedToken)
        ->and($row->expires_at->equalTo(Date::now()->addMinutes(config()->integer('identity.reset_token_ttl_minutes'))))->toBeTrue();
});

it('is a no-op for an unknown email, mailing nothing and storing no token', function () {
    Mail::fake();

    app(RequestPasswordReset::class)(new RequestPasswordResetData('unknown@example.com'));

    Mail::assertNothingSent();

    expect(StaffPasswordResetToken::query()->count())->toBe(0);
});
