<?php

use App\Identity\Actions\ConsumeInvitationToken;
use App\Identity\Models\StaffInvitationToken;
use App\Identity\Support\InvitationTokenHasher;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Stage-03 plan, task breakdown item 9: two parallel acceptances of one
 * invitation token must resolve to exactly one winner, via the conditional
 * UPDATE ... SET consumed_at = now() WHERE token_hash = ? AND consumed_at
 * IS NULL AND expires_at > ?, checked by affected-row count, never
 * read-then-write (master plan test-first rule 2), mirroring
 * tests/Concurrency/PasswordResetTokenConsumptionContentionTest.php's own
 * precedent for the sibling single-use-token guard.
 *
 * Each forked worker inherits the booted application
 * (Tests\Concurrency\Support\ParallelRunner's own docblock), so the guard
 * is called directly rather than through the accept endpoint.
 */
beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    StaffInvitationToken::query()->delete();
    User::query()->delete();
});

it('resolves parallel consumption of one invitation token to exactly one winner via the affected-row-count guard', function (): void {
    $user = User::factory()->create();
    $token = 'a-plaintext-invitation-token';

    StaffInvitationToken::factory()->for($user)->create([
        'token_hash' => InvitationTokenHasher::hash($token),
        'expires_at' => Date::now()->addMinutes(60),
    ]);

    $results = ParallelRunner::run(
        2,
        fn (PDO $pdo): bool => app(ConsumeInvitationToken::class)(InvitationTokenHasher::hash($token)),
    );

    $winners = array_filter($results, fn (bool $won): bool => $won);

    expect($winners)->toHaveCount(1);
});
