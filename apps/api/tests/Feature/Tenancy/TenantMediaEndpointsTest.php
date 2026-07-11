<?php

use App\Identity\Capability;
use App\Models\User;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\MigratedDatabase;
use Tests\Support\PlatformStaff;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;

/*
 * Stage-05c plan, task breakdown item 5 (TDD slice 4): POST
 * /v1/tenants/{tenant}/media, gated on tenants.manage like every other
 * mutation under the tenancy.platform group (TenantEndpointsTest's own
 * precedent). Runs under the platform posture (PlatformRequestTransaction,
 * asPlatform()), so the acting bearer is Tests\Support\PlatformStaff, not
 * TenantStaff. Deletion is covered here too, through the shared top-level
 * DELETE /v1/media/{media} (App\Http\Controllers\MediaController), which
 * resolves the tenants.manage capability dynamically from the owning
 * Tenant model, mirroring how MediaEndpointsTest.php covers the same route
 * for event media.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake('media');
    Queue::fake();

    $this->tenant = app(TenantTransaction::class)->asPlatform(fn () => Tenant::factory()->create());

    $this->withHeaders(['Authorization' => 'Bearer '.PlatformStaff::token()]);
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    app(TenantTransaction::class)->asTenant(
        $this->tenant->id,
        fn () => DB::table('media')->where('tenant_id', $this->tenant->id)->delete(),
    );

    app(TenantTransaction::class)->asTenant($sentinel, function () use ($sentinel): void {
        DB::table('outbox_deliveries')->where('tenant_id', $sentinel)->delete();
        DB::table('outbox_events')->where('tenant_id', $sentinel)->delete();
        DB::table('memberships')->where('tenant_id', $sentinel)->delete();
    });

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        DB::table('roles')->whereNotNull('tenant_id')->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });

    User::query()->delete();
});

function uploadTenantMedia(string $tenantId, UploadedFile $file, string $collection): TestResponse
{
    return test()->post(
        "/v1/tenants/{$tenantId}/media",
        ['file' => $file, 'collection' => $collection],
        ['Content-Type' => 'multipart/form-data', 'Accept' => 'application/json'],
    );
}

describe('POST /v1/tenants/{tenant}/media', function () {
    it('uploads a logo and returns the exact MediaData shape', function () {
        $response = uploadTenantMedia($this->tenant->id, UploadedFile::fake()->image('logo.png', 20, 10), 'logo');

        $response->assertCreated()->assertConformsToOpenApi();

        $body = $response->json();

        expect(Str::isUuid($body['id']))->toBeTrue()
            ->and($body['collection'])->toBe('logo')
            ->and($body['file_name'])->toBe('logo.png')
            ->and($body['mime_type'])->toBe('image/png')
            ->and($body['size'])->toBeGreaterThan(0)
            ->and($body['url'])->toBeString()->not->toBeEmpty()
            ->and($body['conversions'])->toBe(['thumb' => null, 'card' => null, 'hero' => null])
            ->and($body['alt_text'])->toBeNull()
            ->and($body['order'])->toBe(1)
            ->and($body['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and(array_keys($body))->toBe([
                'id', 'collection', 'file_name', 'mime_type', 'size', 'url', 'conversions', 'alt_text', 'order', 'created_at',
            ]);

        $row = app(TenantTransaction::class)->asTenant(
            $this->tenant->id,
            fn () => DB::table('media')->where('id', $body['id'])->first(),
        );

        expect($row->tenant_id)->toBe($this->tenant->id);
    });

    it('replaces the existing logo on a second upload, leaving exactly one row', function () {
        uploadTenantMedia($this->tenant->id, UploadedFile::fake()->image('first.png'), 'logo')->assertCreated();
        uploadTenantMedia($this->tenant->id, UploadedFile::fake()->image('second.png'), 'logo')->assertCreated();

        $rows = app(TenantTransaction::class)->asTenant(
            $this->tenant->id,
            fn () => DB::table('media')->where('model_id', $this->tenant->id)->where('collection_name', 'logo')->get(),
        );

        expect($rows)->toHaveCount(1)
            ->and($rows->first()->file_name)->toBe('second.png');
    });

    it('rejects a disallowed mime type with request.validation_failed', function () {
        $response = uploadTenantMedia($this->tenant->id, UploadedFile::fake()->create('malware.pdf', 10), 'logo');

        $response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('file');
    });

    it('rejects a file over the configured size ceiling with request.validation_failed', function () {
        config()->set('media.max_upload_kb', 100);

        $response = uploadTenantMedia($this->tenant->id, UploadedFile::fake()->image('logo.png')->size(200), 'logo');

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('file');
    });

    it('rejects a missing file with request.validation_failed', function () {
        $response = $this->post(
            "/v1/tenants/{$this->tenant->id}/media",
            ['collection' => 'logo'],
            ['Content-Type' => 'multipart/form-data', 'Accept' => 'application/json'],
        );

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('file');
    });

    it('rejects a collection value outside logo with request.validation_failed', function () {
        $response = uploadTenantMedia($this->tenant->id, UploadedFile::fake()->image('logo.png'), 'banner');

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('collection');
    });

    it('renders a payload_too_large problem for a request whose declared size exceeds the server limit, before validation runs', function () {
        $response = $this->call(
            'POST',
            "/v1/tenants/{$this->tenant->id}/media",
            ['collection' => 'logo'],
            [],
            ['file' => UploadedFile::fake()->image('logo.png')],
            array_merge($this->serverVariables, [
                'CONTENT_TYPE' => 'multipart/form-data',
                'CONTENT_LENGTH' => (string) (500 * 1024 * 1024),
            ]),
        );

        $response->assertStatus(413)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'payload_too_large');
    });

    it('returns a tenant_not_found problem for an unknown tenant id', function () {
        uploadTenantMedia((string) Str::uuid7(), UploadedFile::fake()->image('logo.png'), 'logo')
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_not_found');
    });

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->withHeaders(['Content-Type' => 'multipart/form-data', 'Accept' => 'application/json'])
            ->post("/v1/tenants/{$this->tenant->id}/media", ['file' => UploadedFile::fake()->image('logo.png'), 'collection' => 'logo'])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking tenants.manage with missing_capability', function () {
        $this->withHeaders(['Authorization' => 'Bearer '.PlatformStaff::token(Capability::EventsView)]);

        uploadTenantMedia($this->tenant->id, UploadedFile::fake()->image('logo.png'), 'logo')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });
});

describe('logo URL on GET /v1/tenants/{tenant}', function () {
    it('is null before any logo is uploaded', function () {
        $this->getJson('/v1/tenants/'.$this->tenant->id)
            ->assertOk()
            ->assertJsonPath('branding_settings.logo_url', null);
    });

    it('reflects the uploaded logo\'s URL once uploaded', function () {
        $upload = uploadTenantMedia($this->tenant->id, UploadedFile::fake()->image('logo.png'), 'logo');
        $upload->assertCreated();

        $this->getJson('/v1/tenants/'.$this->tenant->id)
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertJsonPath('branding_settings.logo_url', $upload->json('url'));
    });

    it('reverts to null once the logo is deleted through DELETE /v1/media/{media}', function () {
        $upload = uploadTenantMedia($this->tenant->id, UploadedFile::fake()->image('logo.png'), 'logo');
        $upload->assertCreated();

        $this->withHeaders(['X-Tenant-Id' => $this->tenant->id])
            ->deleteJson('/v1/media/'.$upload->json('id'))
            ->assertNoContent();

        $this->getJson('/v1/tenants/'.$this->tenant->id)
            ->assertOk()
            ->assertJsonPath('branding_settings.logo_url', null);
    });
});

describe('DELETE /v1/media/{media} for a tenant logo', function () {
    it('deletes the media row, its file, and its conversions from the disk, returning 204', function () {
        $upload = uploadTenantMedia($this->tenant->id, UploadedFile::fake()->image('logo.png'), 'logo');
        $upload->assertCreated();

        $row = app(TenantTransaction::class)->asTenant(
            $this->tenant->id,
            fn () => DB::table('media')->where('id', $upload->json('id'))->first(),
        );
        $path = "{$row->tenant_id}/{$row->uuid}/{$row->file_name}";

        expect(Storage::disk('media')->exists($path))->toBeTrue();

        $this->withHeaders(['X-Tenant-Id' => $this->tenant->id])
            ->deleteJson('/v1/media/'.$upload->json('id'))
            ->assertNoContent();

        expect(Storage::disk('media')->exists($path))->toBeFalse();

        $exists = app(TenantTransaction::class)->asTenant(
            $this->tenant->id,
            fn () => DB::table('media')->where('id', $upload->json('id'))->exists(),
        );

        expect($exists)->toBeFalse();
    });

    it('rejects a bearer with no membership at all', function () {
        // Attached directly through the model, not the HTTP upload
        // endpoint: exchanging a second bearer mid-test would otherwise
        // hit the RequestGuard user-caching hazard
        // tests/Isolation/EventMediaEndpointsIsolationTest.php's own
        // docblock records ("a second bearer token exchanged mid-test
        // would silently keep authenticating as the first token's
        // user"), since this file's beforeEach already resolves a
        // PlatformStaff bearer for every test.
        $mediaId = app(TenantTransaction::class)->asPlatform(function (): string {
            $tenant = Tenant::query()->findOrFail($this->tenant->id);

            return $tenant->addMedia(UploadedFile::fake()->image('logo.png'))
                ->withProperties(['tenant_id' => $tenant->id])
                ->toMediaCollection('logo')
                ->id;
        });

        $strangerToken = StaffTokens::issue(User::factory()->create());

        test()->withHeaders([
            'Authorization' => 'Bearer '.$strangerToken,
            'X-Tenant-Id' => $this->tenant->id,
        ])
            ->deleteJson('/v1/media/'.$mediaId)
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_access_denied');
    });
});
