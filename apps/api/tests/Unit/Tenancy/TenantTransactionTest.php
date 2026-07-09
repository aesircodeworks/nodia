<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use App\Support\Tenancy\InvalidTenantIdException;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PostgresTestDatabase;

beforeEach(function (): void {
    PostgresTestDatabase::use();
    Rls::createRoles();
    Rls::createResolverRole();
    Rls::grantMembershipToCurrentUser();
});

function currentPostgresState(): array
{
    $row = DB::selectOne(
        "select current_user as role, current_setting('app.tenant_id', true) as tenant_id",
    );

    return [
        'transaction_level' => DB::transactionLevel(),
        'role' => $row->role,
        'tenant_id' => $row->tenant_id,
    ];
}

it('runs the callback in a transaction under the nodia_app posture with the tenant setting applied', function () {
    $tenantId = Str::uuid7()->toString();

    $observed = app(TenantTransaction::class)->asTenant($tenantId, fn () => currentPostgresState());

    expect($observed['transaction_level'])->toBe(1)
        ->and($observed['role'])->toBe(Rls::APP_ROLE)
        ->and($observed['tenant_id'])->toBe($tenantId);
});

it('runs the callback under the nodia_platform posture with the sentinel tenant id', function () {
    $observed = app(TenantTransaction::class)->asPlatform(fn () => currentPostgresState());

    expect($observed['transaction_level'])->toBe(1)
        ->and($observed['role'])->toBe(Rls::PLATFORM_ROLE)
        ->and($observed['tenant_id'])->toBe(config()->string('tenancy.platform_tenant_id'));
});

it('exposes the current tenant id through the request-scoped container', function () {
    $tenantId = Str::uuid7()->toString();

    $observed = app(TenantTransaction::class)->asTenant($tenantId, fn () => [
        'tenant_id' => app(TenantContext::class)->tenantId(),
        'is_platform' => app(TenantContext::class)->isPlatform(),
    ]);

    expect($observed)->toBe(['tenant_id' => $tenantId, 'is_platform' => false]);

    $observed = app(TenantTransaction::class)->asPlatform(fn () => [
        'tenant_id' => app(TenantContext::class)->tenantId(),
        'is_platform' => app(TenantContext::class)->isPlatform(),
    ]);

    expect($observed)->toBe([
        'tenant_id' => config()->string('tenancy.platform_tenant_id'),
        'is_platform' => true,
    ]);
});

it('returns the callback result', function () {
    $result = app(TenantTransaction::class)->asTenant(Str::uuid7()->toString(), fn () => 'payload');

    expect($result)->toBe('payload');
});

it('leaves no connection or container state behind after commit', function () {
    app(TenantTransaction::class)->asTenant(Str::uuid7()->toString(), fn () => null);

    $state = currentPostgresState();

    expect($state['transaction_level'])->toBe(0)
        ->and($state['role'])->not->toBe(Rls::APP_ROLE)
        ->and($state['tenant_id'])->toBeIn([null, ''])
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
});

it('leaves no connection or container state behind after rollback', function () {
    expect(fn () => app(TenantTransaction::class)->asTenant(
        Str::uuid7()->toString(),
        fn () => throw new RuntimeException('boom'),
    ))->toThrow(RuntimeException::class, 'boom');

    $state = currentPostgresState();

    expect($state['transaction_level'])->toBe(0)
        ->and($state['role'])->not->toBe(Rls::APP_ROLE)
        ->and($state['tenant_id'])->toBeIn([null, ''])
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
});

it('rejects a non-uuid tenant id before any SQL executes', function (string $tenantId) {
    DB::enableQueryLog();

    expect(fn () => app(TenantTransaction::class)->asTenant($tenantId, fn () => null))
        ->toThrow(InvalidTenantIdException::class);

    expect(DB::getQueryLog())->toBe([])
        ->and(DB::transactionLevel())->toBe(0);
})->with([
    'plain string' => 'not-a-uuid',
    'empty string' => '',
    'sql injection shape' => "'; set local role postgres; --",
    'almost a uuid' => '0197fdb2-9c4f-7000-8000-zzzzzzzzzzzz',
]);

it('rejects nesting a tenant transaction inside an active one before any SQL executes', function () {
    $transaction = app(TenantTransaction::class);

    expect(fn () => $transaction->asTenant(Str::uuid7()->toString(), function () use ($transaction) {
        DB::enableQueryLog();

        try {
            $transaction->asPlatform(fn () => null);
        } finally {
            expect(DB::getQueryLog())->toBe([]);
        }
    }))->toThrow(LogicException::class);

    expect(DB::transactionLevel())->toBe(0)
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
});

it('runs the callback under the nodia_resolver posture with no tenant setting', function () {
    $observed = app(TenantTransaction::class)->asDomainResolver(fn () => currentPostgresState());

    expect($observed['transaction_level'])->toBe(1)
        ->and($observed['role'])->toBe(Rls::RESOLVER_ROLE)
        ->and($observed['tenant_id'])->toBeIn([null, '']);
});

it('does not enter the tenant context while resolving', function () {
    $hadTenant = app(TenantTransaction::class)->asDomainResolver(
        fn () => app(TenantContext::class)->hasTenant(),
    );

    expect($hadTenant)->toBeFalse();
});

it('leaves no connection state behind after a resolver transaction', function () {
    app(TenantTransaction::class)->asDomainResolver(fn () => null);

    $state = currentPostgresState();

    expect($state['transaction_level'])->toBe(0)
        ->and($state['role'])->not->toBe(Rls::RESOLVER_ROLE)
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
});

it('rejects a resolver transaction inside an active tenant transaction before any SQL executes', function () {
    $transaction = app(TenantTransaction::class);

    expect(fn () => $transaction->asTenant(Str::uuid7()->toString(), function () use ($transaction) {
        DB::enableQueryLog();

        try {
            $transaction->asDomainResolver(fn () => null);
        } finally {
            expect(DB::getQueryLog())->toBe([]);
        }
    }))->toThrow(LogicException::class);

    expect(DB::transactionLevel())->toBe(0)
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
});

it('rejects a resolver transaction inside an already open database transaction', function () {
    DB::beginTransaction();

    try {
        expect(fn () => app(TenantTransaction::class)->asDomainResolver(fn () => null))
            ->toThrow(LogicException::class);

        expect(DB::transactionLevel())->toBe(1);
    } finally {
        DB::rollBack();
    }
});
