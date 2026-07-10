<?php

use App\Identity\Actions\ConsumePasswordResetToken;
use App\Identity\Models\StaffPasswordResetToken;
use App\Identity\Support\PasswordResetTokenHasher;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, Slice 8 (task breakdown item 16): the consumption
 * invariant, mirroring tests/Unit/Identity/RecoveryCodeConsumptionTest.php's
 * own precedent for the sibling single-use-token guard. The conditional
 * UPDATE ... SET consumed_at = now() WHERE token_hash = ? AND consumed_at
 * IS NULL AND expires_at > ?, checked by affected-row count, never
 * read-then-write (master plan test-first rule 2).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    StaffPasswordResetToken::query()->delete();
    User::query()->delete();
});

it('consumes a matching, unconsumed, unexpired token exactly once', function () {
    $user = User::factory()->create();
    $token = 'a-plaintext-reset-token';

    $resetToken = StaffPasswordResetToken::factory()->for($user)->create([
        'token_hash' => PasswordResetTokenHasher::hash($token),
        'expires_at' => Date::now()->addMinutes(60),
    ]);

    expect(app(ConsumePasswordResetToken::class)(PasswordResetTokenHasher::hash($token)))->toBeTrue();

    expect($resetToken->refresh()->consumed_at)->not->toBeNull();
});

it('rejects a second consumption attempt of an already-consumed token', function () {
    $user = User::factory()->create();
    $token = 'a-plaintext-reset-token';

    StaffPasswordResetToken::factory()->for($user)->create([
        'token_hash' => PasswordResetTokenHasher::hash($token),
        'expires_at' => Date::now()->addMinutes(60),
        'consumed_at' => Date::now(),
    ]);

    expect(app(ConsumePasswordResetToken::class)(PasswordResetTokenHasher::hash($token)))->toBeFalse();
});

it('rejects a hash with no matching row', function () {
    expect(app(ConsumePasswordResetToken::class)(PasswordResetTokenHasher::hash('unknown-token')))->toBeFalse();
});

it('treats the exact expiry instant as not consumable, mirroring every other TTL in this codebase', function () {
    test()->freezeTime();

    $user = User::factory()->create();
    $token = 'a-plaintext-reset-token';

    StaffPasswordResetToken::factory()->for($user)->create([
        'token_hash' => PasswordResetTokenHasher::hash($token),
        'expires_at' => Date::now(),
    ]);

    expect(app(ConsumePasswordResetToken::class)(PasswordResetTokenHasher::hash($token)))->toBeFalse();
});

it('honors the reset token ttl from config under the fake clock', function () {
    test()->freezeTime();

    $user = User::factory()->create();
    $token = 'a-plaintext-reset-token';

    StaffPasswordResetToken::factory()->for($user)->create([
        'token_hash' => PasswordResetTokenHasher::hash($token),
        'expires_at' => Date::now()->addMinutes(config()->integer('identity.reset_token_ttl_minutes')),
    ]);

    test()->travel(config()->integer('identity.reset_token_ttl_minutes') * 60 - 1)->seconds();

    expect(app(ConsumePasswordResetToken::class)(PasswordResetTokenHasher::hash($token)))->toBeTrue();
});
