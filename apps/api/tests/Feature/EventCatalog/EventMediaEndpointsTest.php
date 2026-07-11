<?php

use App\EventCatalog\Models\Event;
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
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;
use Tests\Support\StaffTokens;
use Tests\Support\TenantStaff;

/*
 * Stage-05c plan, task breakdown item 2 (TDD slice 2): POST/GET
 * /v1/events/{event}/media, gated on events.manage/events.view like every
 * other event mutation and read (EventEndpointsTest's own precedent).
 * DELETE /v1/media/{media} is covered in MediaEndpointsTest.php, since it
 * is a top-level route shared with the later tenant-logo collection, not
 * owned by EventCatalog.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();
    Storage::fake('media');
    Queue::fake();

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

function uploadEventMedia(string $eventId, UploadedFile $file, string $collection): TestResponse
{
    return test()->post(
        "/v1/events/{$eventId}/media",
        ['file' => $file, 'collection' => $collection],
        ['Content-Type' => 'multipart/form-data', 'Accept' => 'application/json'],
    );
}

describe('POST /v1/events/{event}/media', function () {
    it('uploads a cover image and returns the exact MediaData shape', function () {
        $response = uploadEventMedia($this->event->id, UploadedFile::fake()->image('cover.jpg', 20, 10), 'cover');

        $response->assertCreated()->assertConformsToOpenApi();

        $body = $response->json();

        expect(Str::isUuid($body['id']))->toBeTrue()
            ->and($body['collection'])->toBe('cover')
            ->and($body['file_name'])->toBe('cover.jpg')
            ->and($body['mime_type'])->toBe('image/jpeg')
            ->and($body['size'])->toBeGreaterThan(0)
            ->and($body['url'])->toBeString()->not->toBeEmpty()
            ->and($body['conversions'])->toBe(['thumb' => null, 'card' => null, 'hero' => null])
            ->and($body['alt_text'])->toBeNull()
            ->and($body['order'])->toBe(1)
            ->and($body['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and(array_keys($body))->toBe([
                'id', 'collection', 'file_name', 'mime_type', 'size', 'url', 'conversions', 'alt_text', 'order', 'created_at',
            ]);
    });

    it('replaces the existing cover file on a second upload, leaving exactly one cover row', function () {
        uploadEventMedia($this->event->id, UploadedFile::fake()->image('first.jpg'), 'cover')->assertCreated();
        uploadEventMedia($this->event->id, UploadedFile::fake()->image('second.jpg'), 'cover')->assertCreated();

        $rows = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => DB::table('media')->where('model_id', $this->event->id)->where('collection_name', 'cover')->get(),
        );

        expect($rows)->toHaveCount(1)
            ->and($rows->first()->file_name)->toBe('second.jpg');
    });

    it('accepts multiple gallery uploads, ordering them by upload sequence', function () {
        uploadEventMedia($this->event->id, UploadedFile::fake()->image('one.jpg'), 'gallery')->assertCreated();
        $second = uploadEventMedia($this->event->id, UploadedFile::fake()->image('two.jpg'), 'gallery')->assertCreated();

        expect($second->json('order'))->toBe(2);

        $rows = app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => DB::table('media')
                ->where('model_id', $this->event->id)
                ->where('collection_name', 'gallery')
                ->orderBy('order_column')
                ->pluck('file_name'),
        );

        expect($rows->all())->toBe(['one.jpg', 'two.jpg']);
    });

    it('records exactly one activity_log row for the upload', function () {
        $before = app(TenantTransaction::class)->asPlatform(
            fn () => DB::table('activity_log')->where('tenant_id', $this->tenantId)->count(),
        );

        uploadEventMedia($this->event->id, UploadedFile::fake()->image('cover.jpg'), 'cover')->assertCreated();

        $after = app(TenantTransaction::class)->asPlatform(
            fn () => DB::table('activity_log')->where('tenant_id', $this->tenantId)->count(),
        );

        expect($after)->toBe($before + 1);
    });

    it('rejects a disallowed mime type with request.validation_failed', function () {
        $response = uploadEventMedia($this->event->id, UploadedFile::fake()->create('malware.pdf', 10), 'cover');

        $response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('file');
    });

    it('rejects a file over the configured size ceiling with request.validation_failed', function () {
        config()->set('media.max_upload_kb', 100);

        $response = uploadEventMedia($this->event->id, UploadedFile::fake()->image('cover.jpg')->size(200), 'cover');

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('file');
    });

    it('rejects a missing file with request.validation_failed', function () {
        $response = $this->post(
            "/v1/events/{$this->event->id}/media",
            ['collection' => 'cover'],
            ['Content-Type' => 'multipart/form-data', 'Accept' => 'application/json'],
        );

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('file');
    });

    it('rejects a collection value outside cover and gallery with request.validation_failed', function () {
        $response = uploadEventMedia($this->event->id, UploadedFile::fake()->image('cover.jpg'), 'banner');

        $response->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');

        expect($response->json('errors'))->toHaveKey('collection');
    });

    it('renders a payload_too_large problem for a request whose declared size exceeds the server limit, before validation runs', function () {
        $response = $this->call(
            'POST',
            "/v1/events/{$this->event->id}/media",
            ['collection' => 'cover'],
            [],
            ['file' => UploadedFile::fake()->image('cover.jpg')],
            array_merge($this->serverVariables, [
                'CONTENT_TYPE' => 'multipart/form-data',
                'CONTENT_LENGTH' => (string) (500 * 1024 * 1024),
            ]),
        );

        $response->assertStatus(413)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'payload_too_large');
    });

    it('returns a request.not_found problem for a foreign tenant\'s event', function () {
        $foreignEvent = app(TenantTransaction::class)->asTenant(
            $this->otherTenantId,
            fn () => Event::factory()->create(['tenant_id' => $this->otherTenantId]),
        );

        uploadEventMedia($foreignEvent->id, UploadedFile::fake()->image('cover.jpg'), 'cover')
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for an unknown event id', function () {
        uploadEventMedia((string) Str::uuid7(), UploadedFile::fake()->image('cover.jpg'), 'cover')
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->withHeaders(['X-Tenant-Id' => $this->tenantId, 'Content-Type' => 'multipart/form-data', 'Accept' => 'application/json'])
            ->post("/v1/events/{$this->event->id}/media", ['file' => UploadedFile::fake()->image('cover.jpg'), 'collection' => 'cover'])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.manage with missing_capability', function () {
        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsView)]);

        uploadEventMedia($this->event->id, UploadedFile::fake()->image('cover.jpg'), 'cover')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });

    it('rejects a bearer with no membership in the asserted tenant', function () {
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken]);

        uploadEventMedia($this->event->id, UploadedFile::fake()->image('cover.jpg'), 'cover')
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_access_denied');
    });
});

describe('GET /v1/events/{event}/media', function () {
    it('returns an empty list for an event with no media', function () {
        $this->getJson("/v1/events/{$this->event->id}/media")
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertExactJson(['data' => []]);
    });

    it('lists cover and gallery media ordered by collection then upload order', function () {
        uploadEventMedia($this->event->id, UploadedFile::fake()->image('gallery-1.jpg'), 'gallery')->assertCreated();
        uploadEventMedia($this->event->id, UploadedFile::fake()->image('gallery-2.jpg'), 'gallery')->assertCreated();
        uploadEventMedia($this->event->id, UploadedFile::fake()->image('cover.jpg'), 'cover')->assertCreated();

        $response = $this->getJson("/v1/events/{$this->event->id}/media");

        $response->assertOk()->assertConformsToOpenApi();

        expect(collect($response->json('data'))->map(fn (array $m) => [$m['collection'], $m['file_name']])->all())
            ->toBe([
                ['cover', 'cover.jpg'],
                ['gallery', 'gallery-1.jpg'],
                ['gallery', 'gallery-2.jpg'],
            ]);
    });

    it('filters by collection with the collection query parameter', function () {
        uploadEventMedia($this->event->id, UploadedFile::fake()->image('gallery-1.jpg'), 'gallery')->assertCreated();
        uploadEventMedia($this->event->id, UploadedFile::fake()->image('cover.jpg'), 'cover')->assertCreated();

        $response = $this->getJson("/v1/events/{$this->event->id}/media?collection=cover");

        $response->assertOk()->assertConformsToOpenApi()->assertJsonCount(1, 'data');

        expect($response->json('data.0.collection'))->toBe('cover');
    });

    it('rejects a collection filter outside cover and gallery with request.validation_failed', function () {
        $this->getJson("/v1/events/{$this->event->id}/media?collection=banner")
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed');
    });

    it('returns a request.not_found problem for a foreign tenant\'s event', function () {
        $foreignEvent = app(TenantTransaction::class)->asTenant(
            $this->otherTenantId,
            fn () => Event::factory()->create(['tenant_id' => $this->otherTenantId]),
        );

        $this->getJson("/v1/events/{$foreignEvent->id}/media")
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns a request.not_found problem for an unknown event id', function () {
        $this->getJson('/v1/events/'.Str::uuid7().'/media')
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('rejects a request with no bearer', function () {
        $this->withoutToken()
            ->getJson("/v1/events/{$this->event->id}/media", ['X-Tenant-Id' => $this->tenantId])
            ->assertUnauthorized()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'auth.unauthenticated');
    });

    it('rejects a bearer lacking events.view with missing_capability', function () {
        $this->withHeaders(['Authorization' => 'Bearer '.TenantStaff::token($this->tenantId, Capability::EventsManage)])
            ->getJson("/v1/events/{$this->event->id}/media")
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'missing_capability');
    });

    it('rejects a bearer with no membership in the asserted tenant', function () {
        $strangerToken = StaffTokens::issue(User::factory()->create());

        $this->withHeaders(['Authorization' => 'Bearer '.$strangerToken])
            ->getJson("/v1/events/{$this->event->id}/media")
            ->assertForbidden()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'tenant_access_denied');
    });
});

describe('queued thumb, card, and hero conversions', function () {
    it('enqueues one PerformConversionsJob on the media-conversions queue and leaves conversions null until it runs', function () {
        $upload = uploadEventMedia($this->event->id, UploadedFile::fake()->image('cover.jpg', 2000, 1000), 'cover');

        $upload->assertCreated();

        expect($upload->json('conversions'))->toBe(['thumb' => null, 'card' => null, 'hero' => null]);

        Queue::assertPushedOn('media-conversions', PerformConversionsJob::class);

        $listedBeforeJobRuns = $this->getJson("/v1/events/{$this->event->id}/media")->json('data.0.conversions');

        expect($listedBeforeJobRuns)->toBe(['thumb' => null, 'card' => null, 'hero' => null]);

        $job = Queue::pushed(PerformConversionsJob::class)->sole();

        app(TenantTransaction::class)->asTenant($this->tenantId, fn () => app()->call([$job, 'handle']));

        $listedAfterJobRuns = $this->getJson("/v1/events/{$this->event->id}/media")->json('data.0.conversions');

        expect($listedAfterJobRuns['thumb'])->toBeString()->not->toBeEmpty()
            ->and($listedAfterJobRuns['card'])->toBeString()->not->toBeEmpty()
            ->and($listedAfterJobRuns['hero'])->toBeString()->not->toBeEmpty();
    });
});
