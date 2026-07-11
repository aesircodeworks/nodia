<?php

use App\EventCatalog\Models\Event;
use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-05c plan, task breakdown item 2 (TDD slice 2): DELETE
 * /v1/media/{media} is a top-level route with no single owning bounded
 * context (App\Http\Controllers\MediaController's own docblock), so its
 * feature test lives at the top of tests/Feature like HealthControllerTest,
 * not under tests/Feature/EventCatalog alongside the nested upload/list
 * routes.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake('media');

    $this->tenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);
    $this->otherTenantId = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create()->id);

    $this->event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId]),
    );

    $this->withHeaders([
        'Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, [Capability::EventsView, Capability::EventsManage]),
        'X-Tenant-Id' => $this->tenantId,
    ]);
});

afterEach(function (): void {
    foreach ([$this->tenantId, $this->otherTenantId] as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('memberships')->where('tenant_id', $tenantId)->delete();
            DB::table('media')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function (): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot(config()->string('tenancy.platform_tenant_id'))->delete();
    });

    User::query()->delete();
});

/**
 * @return array{id: string, path: string}
 */
function attachEventCover(string $tenantId, Event $event): array
{
    return app(TenantTransaction::class)->asTenant($tenantId, function () use ($event): array {
        $media = $event->addMedia(UploadedFile::fake()->image('cover.jpg'))->toMediaCollection('cover');

        return ['id' => $media->id, 'path' => "{$media->tenant_id}/{$media->uuid}/{$media->file_name}"];
    });
}

describe('DELETE /v1/media/{media}', function () {
    it('deletes the media row, its file, and its conversions from the disk, returning 204', function () {
        $media = attachEventCover($this->tenantId, $this->event);

        expect(Storage::disk('media')->exists($media['path']))->toBeTrue();

        $this->deleteJson('/v1/media/'.$media['id'])->assertNoContent();

        expect(Storage::disk('media')->exists($media['path']))->toBeFalse();

        $exists = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => DB::table('media')->where('id', $media['id'])->exists(),
        );

        expect($exists)->toBeFalse();
    });

    it('records exactly one activity_log row for the deletion', function () {
        $media = attachEventCover($this->tenantId, $this->event);

        $before = app(TenantTransaction::class)->asPlatform(
            fn () => DB::table('activity_log')->where('tenant_id', $this->tenantId)->count(),
        );

        $this->deleteJson('/v1/media/'.$media['id'])->assertNoContent();

        $after = app(TenantTransaction::class)->asPlatform(
            fn () => DB::table('activity_log')->where('tenant_id', $this->tenantId)->count(),
        );

        expect($after)->toBe($before + 1);
    });

    it('returns a request.not_found problem for a foreign tenant\'s media', function () {
        $foreignEvent = app(TenantTransaction::class)->asTenant(
            $this->otherTenantId,
            fn () => Event::factory()->create(['tenant_id' => $this->otherTenantId]),
        );
        $foreignMedia = attachEventCover($this->otherTenantId, $foreignEvent);

        $this->deleteJson('/v1/media/'.$foreignMedia['id'])
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for an unknown media id', function () {
        $this->deleteJson('/v1/media/'.Str::uuid7())
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function () {
        $media = attachEventCover($this->tenantId, $this->event);

        $this->withoutToken()
            ->deleteJson('/v1/media/'.$media['id'], [], ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.manage with missing_capability', function () {
        $media = attachEventCover($this->tenantId, $this->event);

        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView)])
            ->deleteJson('/v1/media/'.$media['id'])
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });

    it('rejects a bearer with no membership in the asserted tenant', function () {
        $media = attachEventCover($this->tenantId, $this->event);
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken])
            ->deleteJson('/v1/media/'.$media['id'])
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_access_denied');
    });
});
