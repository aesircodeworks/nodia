<?php

use App\Identity\Actions\ConsumeRecoveryCode;
use App\Identity\Models\MfaRecoveryCode;
use App\Models\User;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Task breakdown item 10 (stage-03 plan): the consumption invariant,
 * written and failing before the guard exists.
 * App\Identity\Actions\ConsumeRecoveryCode does not exist yet; task
 * breakdown item 11 (Slice 5) builds it as a conditional UPDATE ... SET
 * used_at = now() WHERE user_id = ? AND code_hash = ? AND used_at IS
 * NULL, checked by affected-row count, never read-then-write (master
 * plan test-first rule 2; Data model "mfa_recovery_codes"). Fixtures hash
 * their plaintext code directly with hash('sha256', ...) rather than
 * through App\Identity\Support\RecoveryCodeHasher (also not built yet;
 * see RecoveryCodeHasherTest.php), pinning the exact deterministic
 * algorithm task breakdown item 11 must implement for these tests to
 * pass.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

afterEach(function (): void {
    MfaRecoveryCode::query()->delete();
    User::query()->delete();
});

it('consumes a matching, unused recovery code exactly once', function () {
    $user = User::factory()->create();
    $code = 'ABCD-1234-EFGH';

    $recoveryCode = MfaRecoveryCode::factory()->for($user)->create([
        'code_hash' => hash('sha256', $code),
    ]);

    expect(app(ConsumeRecoveryCode::class)($user->id, $code))->toBeTrue();

    expect($recoveryCode->refresh()->used_at)->not->toBeNull();
});

it('rejects a second consumption attempt of an already-used code', function () {
    $user = User::factory()->create();
    $code = 'ABCD-1234-EFGH';

    MfaRecoveryCode::factory()->for($user)->create([
        'code_hash' => hash('sha256', $code),
        'used_at' => now(),
    ]);

    expect(app(ConsumeRecoveryCode::class)($user->id, $code))->toBeFalse();
});

it('rejects a plaintext code with no matching hash', function () {
    $user = User::factory()->create();

    MfaRecoveryCode::factory()->for($user)->create([
        'code_hash' => hash('sha256', 'ABCD-1234-EFGH'),
    ]);

    expect(app(ConsumeRecoveryCode::class)($user->id, 'WRONG-CODE'))->toBeFalse();
});

it('rejects a matching code presented for a different user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $code = 'ABCD-1234-EFGH';

    MfaRecoveryCode::factory()->for($owner)->create([
        'code_hash' => hash('sha256', $code),
    ]);

    expect(app(ConsumeRecoveryCode::class)($other->id, $code))->toBeFalse();
});
