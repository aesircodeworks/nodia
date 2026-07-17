<?php

use App\Identity\Actions\ConsumePasswordResetToken;
use App\Identity\Models\StaffPasswordResetToken;
use App\Identity\Support\PasswordResetTokenHasher;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Stage-03 plan, Slice 8 Concurrency (task breakdown item 16), written and
 * failing before the guard exists: two parallel confirmations of one
 * password reset token must resolve to exactly one winner, via the
 * conditional UPDATE ... SET consumed_at = now() WHERE token_hash = ? AND
 * consumed_at IS NULL AND expires_at > ?, checked by affected-row count,
 * never read-then-write (master plan test-first rule 2), mirroring
 * tests/Concurrency/RecoveryCodeConsumptionContentionTest.php's own
 * precedent for the sibling single-use-token guard.
 *
 * Each forked worker inherits the booted application
 * (Tests\Concurrency\Support\ParallelRunner's own docblock), so the guard
 * is called directly rather than through the confirm endpoint.
 */
beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    StaffPasswordResetToken::query()->delete();
    User::query()->delete();
});

it('resolves parallel consumption of one password reset token to exactly one winner via the affected-row-count guard', function (): void {
    $user = User::factory()->create();
    $token = 'a-plaintext-reset-token';

    StaffPasswordResetToken::factory()->for($user)->create([
        'token_hash' => PasswordResetTokenHasher::hash($token),
        'expires_at' => Date::now()->addMinutes(60),
    ]);

    $results = ParallelRunner::run(
        2,
        fn (PDO $pdo): bool => app(ConsumePasswordResetToken::class)(PasswordResetTokenHasher::hash($token)),
    );

    $winners = array_filter($results, fn (bool $won): bool => $won);

    expect($winners)->toHaveCount(1);
});
