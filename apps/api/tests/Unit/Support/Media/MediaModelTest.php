<?php

use App\Support\Media\Models\Media;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05c plan, task breakdown item 1 / TDD sequencing Slice 1: the
 * custom Media model generates UUIDv7 ids the same way every other
 * primary key in this codebase does (data-conventions; App\Tenancy\Models\
 * Tenant's own TenantTest precedent), and stamps tenant_id from the
 * request-scoped TenantContext on create, because medialibrary constructs
 * Media rows itself deep inside FileAdder::toMediaCollection() with no
 * call-site hook to pass tenant_id explicitly.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
});

afterEach(function (): void {
    app(TenantTransaction::class)->asPlatform(function (): void {
        Media::query()->where('tenant_id', $this->tenantId)->delete();
        Tenant::query()->whereKey($this->tenantId)->delete();
    });
});

/**
 * @return array<string, mixed>
 */
function makeMediaAttributes(string $tenantId, array $overrides = []): array
{
    return [
        'model_type' => Tenant::class,
        'model_id' => $tenantId,
        'collection_name' => 'logo',
        'name' => 'logo',
        'file_name' => 'logo.png',
        'disk' => 'media',
        'size' => 10,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        ...$overrides,
    ];
}

it('generates a uuid version 7 primary key', function () {
    $media = new Media;

    expect($media->usesUniqueIds())->toBeTrue()
        ->and(Str::isUuid($media->newUniqueId()))->toBeTrue()
        ->and($media->newUniqueId()[14])->toBe('7');
});

it('stamps tenant_id from the active tenant context when not supplied', function () {
    $media = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Media::create(makeMediaAttributes($this->tenantId)),
    );

    expect($media->tenant_id)->toBe($this->tenantId);
});

it('refuses to create without an active tenant context', function () {
    expect(fn () => Media::create(makeMediaAttributes($this->tenantId)))
        ->toThrow(LogicException::class, 'No tenant context is active');
});

it('does not override an explicitly supplied tenant_id', function () {
    $media = app(TenantTransaction::class)->asPlatform(
        fn () => Media::create(makeMediaAttributes($this->tenantId, ['tenant_id' => $this->tenantId])),
    );

    expect($media->tenant_id)->toBe($this->tenantId);
});
