<?php

use App\Identity\Actions\ConsumeRecoveryCode;
use App\Identity\Models\MfaRecoveryCode;
use App\Models\User;
use Tests\Concurrency\Support\ParallelRunner;
use Tests\Support\MigratedDatabase;

/**
 * Task breakdown item 10's parallel recovery-code concurrency test
 * (stage-03 plan, master plan test-first rule 2), written and failing
 * before the guard exists: App\Identity\Actions\ConsumeRecoveryCode does
 * not exist yet (task breakdown item 11, Slice 5, implements it). Two
 * parallel presentations of the same recovery code must resolve to
 * exactly one winner, via the conditional UPDATE ... SET used_at = now()
 * WHERE user_id = ? AND code_hash = ? AND used_at IS NULL, checked by
 * affected-row count, never read-then-write (Data model
 * "mfa_recovery_codes").
 *
 * Each forked worker inherits the booted application
 * (Tests\Concurrency\Support\ParallelRunner's own docblock;
 * Tests\Concurrency\TenantDomainContentionTest's precedent), so the guard
 * is called directly rather than through an HTTP endpoint: no endpoint
 * consumes a recovery code until task breakdown item 11 ships one.
 */
beforeEach(function (): void {
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    MfaRecoveryCode::query()->delete();
    User::query()->delete();
});

it('resolves parallel consumption of one recovery code to exactly one winner via the affected-row-count guard', function (): void {
    $user = User::factory()->create();
    $code = 'ABCD-1234-EFGH';

    MfaRecoveryCode::factory()->for($user)->create([
        'code_hash' => hash('sha256', $code),
    ]);

    $results = ParallelRunner::run(
        2,
        fn (PDO $pdo): bool => app(ConsumeRecoveryCode::class)($user->id, $code),
    );

    $winners = array_filter($results, fn (bool $won): bool => $won);

    expect($winners)->toHaveCount(1);
});
