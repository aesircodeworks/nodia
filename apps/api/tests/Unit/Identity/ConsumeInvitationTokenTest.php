<?php

use App\Identity\Actions\ConsumeInvitationToken;
use App\Identity\Models\StaffInvitationToken;
use App\Identity\Support\InvitationTokenHasher;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-03 plan, task breakdown item 9: the consumption invariant that
 * makes invitation acceptance single-use, mirroring
 * tests/Unit/Identity/ConsumePasswordResetTokenTest.php's own precedent
 * for the sibling single-use-token guard. The conditional UPDATE ... SET
 * consumed_at = now() WHERE token_hash = ? AND consumed_at IS NULL AND
 * expires_at > ?, checked by affected-row count, never read-then-write
 * (master plan test-first rule 2).
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    StaffInvitationToken::query()->delete();
    User::query()->delete();
});

it('consumes a matching, unconsumed, unexpired token exactly once', function () {
    $user = User::factory()->create();
    $token = 'a-plaintext-invitation-token';

    $invitationToken = StaffInvitationToken::factory()->for($user)->create([
        'token_hash' => InvitationTokenHasher::hash($token),
        'expires_at' => Date::now()->addMinutes(60),
    ]);

    expect(app(ConsumeInvitationToken::class)(InvitationTokenHasher::hash($token)))->toBeTrue();

    expect($invitationToken->refresh()->consumed_at)->not->toBeNull();
});

it('rejects a second consumption attempt of an already-consumed token', function () {
    $user = User::factory()->create();
    $token = 'a-plaintext-invitation-token';

    StaffInvitationToken::factory()->for($user)->create([
        'token_hash' => InvitationTokenHasher::hash($token),
        'expires_at' => Date::now()->addMinutes(60),
        'consumed_at' => Date::now(),
    ]);

    expect(app(ConsumeInvitationToken::class)(InvitationTokenHasher::hash($token)))->toBeFalse();
});

it('rejects a hash with no matching row', function () {
    expect(app(ConsumeInvitationToken::class)(InvitationTokenHasher::hash('unknown-token')))->toBeFalse();
});

it('treats the exact expiry instant as not consumable, mirroring every other TTL in this codebase', function () {
    test()->freezeTime();

    $user = User::factory()->create();
    $token = 'a-plaintext-invitation-token';

    StaffInvitationToken::factory()->for($user)->create([
        'token_hash' => InvitationTokenHasher::hash($token),
        'expires_at' => Date::now(),
    ]);

    expect(app(ConsumeInvitationToken::class)(InvitationTokenHasher::hash($token)))->toBeFalse();
});

it('honors the invitation token ttl from config under the fake clock', function () {
    test()->freezeTime();

    $user = User::factory()->create();
    $token = 'a-plaintext-invitation-token';

    StaffInvitationToken::factory()->for($user)->create([
        'token_hash' => InvitationTokenHasher::hash($token),
        'expires_at' => Date::now()->addMinutes(config()->integer('identity.invitation_token_ttl_minutes')),
    ]);

    test()->travel(config()->integer('identity.invitation_token_ttl_minutes') * 60 - 1)->seconds();

    expect(app(ConsumeInvitationToken::class)(InvitationTokenHasher::hash($token)))->toBeTrue();
});
