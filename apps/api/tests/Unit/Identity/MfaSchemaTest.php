<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * The schema half of task breakdown item 10 (stage-03 plan, Data model
 * "users" and "mfa_recovery_codes"): proves the migration itself, not the
 * consumption guard (task breakdown item 11, Slice 5), which does not
 * exist yet and has its own, deliberately failing, coverage in
 * RecoveryCodeHasherTest.php, RecoveryCodeConsumptionTest.php, and
 * tests/Concurrency/RecoveryCodeConsumptionContentionTest.php.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
});

it('adds mfa_enabled, mfa_secret, and mfa_confirmed_at to users', function () {
    $columns = DB::select(
        "select column_name, data_type, is_nullable, column_default
            from information_schema.columns
            where table_name = 'users' and column_name in ('mfa_enabled', 'mfa_secret', 'mfa_confirmed_at')",
    );

    $byName = collect($columns)->keyBy('column_name');

    expect($byName)->toHaveCount(3);

    expect($byName['mfa_enabled']->data_type)->toBe('boolean')
        ->and($byName['mfa_enabled']->is_nullable)->toBe('NO')
        ->and($byName['mfa_enabled']->column_default)->toContain('false');

    expect($byName['mfa_secret']->data_type)->toBe('text')
        ->and($byName['mfa_secret']->is_nullable)->toBe('YES');

    expect($byName['mfa_confirmed_at']->data_type)->toBe('timestamp with time zone')
        ->and($byName['mfa_confirmed_at']->is_nullable)->toBe('YES');
});

it('encrypts mfa_secret at rest through the User model cast', function () {
    $user = User::factory()->create(['mfa_secret' => 'JBSWY3DPEHPK3PXP']);

    $raw = DB::selectOne('select mfa_secret from users where id = ?', [$user->id]);

    expect($raw->mfa_secret)->not->toBe('JBSWY3DPEHPK3PXP');

    $user->refresh();

    expect($user->mfa_secret)->toBe('JBSWY3DPEHPK3PXP');

    $user->delete();
});

it('creates mfa_recovery_codes with the plan-specified columns', function () {
    $columns = DB::select(
        "select column_name, data_type, is_nullable
            from information_schema.columns
            where table_name = 'mfa_recovery_codes'",
    );

    $byName = collect($columns)->keyBy('column_name');

    expect($byName->keys()->sort()->values()->all())->toBe([
        'code_hash',
        'created_at',
        'id',
        'updated_at',
        'used_at',
        'user_id',
    ]);

    expect($byName['id']->data_type)->toBe('uuid')
        ->and($byName['user_id']->data_type)->toBe('uuid')
        ->and($byName['user_id']->is_nullable)->toBe('NO')
        ->and($byName['code_hash']->data_type)->toBe('character varying')
        ->and($byName['code_hash']->is_nullable)->toBe('NO')
        ->and($byName['used_at']->data_type)->toBe('timestamp with time zone')
        ->and($byName['used_at']->is_nullable)->toBe('YES');
});

it('indexes mfa_recovery_codes on user_id', function () {
    $index = DB::selectOne(
        "select indexdef from pg_indexes where tablename = 'mfa_recovery_codes' and indexdef like '%(user_id)%'",
    );

    expect($index)->not->toBeNull();
});

it('leaves row level security disabled on mfa_recovery_codes, the sanctioned platform-global exception', function () {
    $relation = DB::selectOne(
        "select relrowsecurity, relforcerowsecurity from pg_class where relname = 'mfa_recovery_codes' and relnamespace = 'public'::regnamespace",
    );

    expect($relation)->not->toBeNull()
        ->and((bool) $relation->relrowsecurity)->toBeFalse()
        ->and((bool) $relation->relforcerowsecurity)->toBeFalse();
});

it('grants nodia_app and nodia_platform full access to mfa_recovery_codes', function () {
    foreach (['nodia_app', 'nodia_platform'] as $role) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            $granted = DB::selectOne('select has_table_privilege(?, ?, ?) as granted', [$role, 'mfa_recovery_codes', $privilege]);

            expect((bool) $granted->granted)->toBeTrue("expected {$role} to hold {$privilege} on mfa_recovery_codes");
        }
    }
});
