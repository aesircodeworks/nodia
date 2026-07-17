<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Models\TicketType;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05a plan, task breakdown item 10 (TDD slice 5): the Host-resolved
 * storefront read surface. Only published events are visible, content is
 * locale-negotiated, and internal state never leaks.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    ['tenant' => $tenant, 'host' => $host] = app(TenantTransaction::class)->asPlatform(function (): array {
        $tenant = Tenant::factory()->create(['default_locale' => 'en', 'supported_locales' => ['en', 'fr']]);
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        return ['tenant' => $tenant, 'host' => $domain->domain];
    });

    $this->tenantId = $tenant->id;
    $this->host = $host;
});

afterEach(function (): void {
    $sentinel = config()->string('tenancy.platform_tenant_id');

    $tenantIds = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKeyNot($sentinel)->pluck('id')->all(),
    );

    foreach ($tenantIds as $tenantId) {
        app(TenantTransaction::class)->asTenant($tenantId, function () use ($tenantId): void {
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('media')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_type_inventory')->where('tenant_id', $tenantId)->delete();
            DB::table('ticket_types')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
            DB::table('venues')->where('tenant_id', $tenantId)->delete();
        });
    }

    app(TenantTransaction::class)->asPlatform(function () use ($sentinel): void {
        TenantDomain::query()->delete();
        Tenant::query()->whereKeyNot($sentinel)->delete();
    });
});

/**
 * @param  array<string, mixed>  $attributes
 */
function storefrontEvent(string $tenantId, array $attributes = []): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function storefrontTicketType(string $tenantId, string $eventId, array $attributes = []): TicketType
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => TicketType::factory()->create(['tenant_id' => $tenantId, 'event_id' => $eventId, ...$attributes]),
    );
}

/**
 * @param  array<string, string>  $customProperties
 */
function attachEventMedia(string $tenantId, Event $event, string $collection, string $fileName = 'image.jpg', array $customProperties = []): Media
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => $event->addMedia(UploadedFile::fake()->image($fileName, 2000, 1000))
            ->withCustomProperties($customProperties)
            ->toMediaCollection($collection),
    );
}

describe('GET /v1/storefront/events', function () {
    it('lists only published events ordered by start_at ascending', function () {
        storefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Later'], 'start_at' => '2026-09-02T00:00:00Z', 'end_at' => '2026-09-02T03:00:00Z']);
        storefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Sooner'], 'start_at' => '2026-09-01T00:00:00Z', 'end_at' => '2026-09-01T03:00:00Z']);
        storefrontEvent($this->tenantId, ['status' => EventStatus::Draft, 'name' => ['en' => 'Draft']]);
        storefrontEvent($this->tenantId, ['status' => EventStatus::Canceled, 'name' => ['en' => 'Canceled']]);

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events')
            ->assertOk()
            ->assertConformsToOpenApi();

        $names = collect($response->json('data'))->pluck('name')->all();

        expect($names)->toBe(['Sooner', 'Later']);
    });

    it('resolves content in the tenant default locale and sets Content-Language', function () {
        storefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'English Name', 'fr' => 'Nom Francais']]);

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events')
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertHeader('Content-Language', 'en');

        expect($response->json('data.0.name'))->toBe('English Name')
            ->and($response->json('data.0.locale'))->toBe('en');
    });

    it('honors an explicit supported locale query parameter', function () {
        storefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'English Name', 'fr' => 'Nom Francais']]);

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events?locale=fr')
            ->assertOk()
            ->assertHeader('Content-Language', 'fr');

        expect($response->json('data.0.name'))->toBe('Nom Francais')
            ->and($response->json('data.0.locale'))->toBe('fr');
    });

    it('negotiates the locale from Accept-Language when no explicit locale is given', function () {
        storefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'English Name', 'fr' => 'Nom Francais']]);

        $response = $this->withHeaders(['Accept-Language' => 'fr-CA, en;q=0.5'])
            ->getJson('http://'.$this->host.'/v1/storefront/events')
            ->assertOk()
            ->assertHeader('Content-Language', 'fr');

        expect($response->json('data.0.name'))->toBe('Nom Francais');
    });

    it('falls back to the tenant default locale for a missing translation', function () {
        storefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'English Only']]);

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events?locale=fr')
            ->assertOk()
            ->assertHeader('Content-Language', 'fr');

        expect($response->json('data.0.name'))->toBe('English Only')
            ->and($response->json('data.0.locale'))->toBe('fr');
    });

    it('never exposes internal event state on the storefront shape', function () {
        storefrontEvent($this->tenantId, ['status' => EventStatus::Published]);

        $keys = array_keys($this->getJson('http://'.$this->host.'/v1/storefront/events')->assertOk()->json('data.0'));

        expect($keys)->not->toContain('status')
            ->and($keys)->not->toContain('async_payment_policy')
            ->and($keys)->not->toContain('tenant_id');
    });
});

describe('GET /v1/storefront/events/{event}', function () {
    it('returns a published event with its embedded ticket types', function () {
        $event = storefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Show Night']]);
        storefrontTicketType($this->tenantId, $event->id, ['name' => 'General Admission', 'price' => Money::of(2500, 'USD')]);

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events/'.$event->id)
            ->assertOk()
            ->assertConformsToOpenApi()
            ->assertHeader('Content-Language', 'en');

        expect($response->json('name'))->toBe('Show Night')
            ->and($response->json('ticket_types'))->toHaveCount(1)
            ->and($response->json('ticket_types.0.name'))->toBe('General Admission')
            ->and($response->json('ticket_types.0.price'))->toBe(['amount' => 2500, 'currency' => 'USD']);
    });

    it('returns request.not_found for a draft event', function () {
        $event = storefrontEvent($this->tenantId, ['status' => EventStatus::Draft]);

        $this->getJson('http://'.$this->host.'/v1/storefront/events/'.$event->id)
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns request.not_found for a canceled event', function () {
        $event = storefrontEvent($this->tenantId, ['status' => EventStatus::Canceled]);

        $this->getJson('http://'.$this->host.'/v1/storefront/events/'.$event->id)
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns request.not_found for an unknown event id', function () {
        $this->getJson('http://'.$this->host.'/v1/storefront/events/'.Str::uuid7()->toString())
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });

    it('returns request.not_found for a malformed, non-uuid event id', function () {
        // The whereUuid route constraint makes this miss the route so it
        // renders the standard not-found problem, rather than reaching
        // whereKey() against the uuid column and raising a 500.
        $this->getJson('http://'.$this->host.'/v1/storefront/events/not-a-uuid')
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });
});

/*
 * Stage-05c plan, task breakdown item 4 (TDD slice 3): the Stage 5a
 * storefront event Data objects gain additive cover_image and gallery
 * fields, sourced from the same medialibrary media stage-05c tasks 1
 * through 3 already attach to Event. No new endpoint, no new response
 * class; conversion URLs are null until the queued job runs, exactly like
 * the admin MediaData shape.
 */
describe('storefront media exposure', function () {
    beforeEach(function (): void {
        Storage::fake('media');
        Queue::fake();
    });

    it('includes cover_image and gallery on the list with conversion urls null before the queued conversion runs', function () {
        $event = storefrontEvent($this->tenantId, ['status' => EventStatus::Published]);
        $cover = attachEventMedia($this->tenantId, $event, 'cover', 'cover.jpg', ['alt_text' => 'Crowd cheering']);
        $galleryOne = attachEventMedia($this->tenantId, $event, 'gallery', 'gallery-one.jpg');
        $galleryTwo = attachEventMedia($this->tenantId, $event, 'gallery', 'gallery-two.jpg');

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events')
            ->assertOk()
            ->assertConformsToOpenApi();

        expect($response->json('data.0.cover_image.id'))->toBe($cover->id)
            ->and($response->json('data.0.cover_image.alt_text'))->toBe('Crowd cheering')
            ->and($response->json('data.0.cover_image.conversions'))->toBe(['thumb' => null, 'card' => null, 'hero' => null])
            ->and($response->json('data.0.gallery.0.id'))->toBe($galleryOne->id)
            ->and($response->json('data.0.gallery.1.id'))->toBe($galleryTwo->id);
    });

    it('includes cover_image and gallery on the detail with conversion urls populated after the queued conversion runs', function () {
        $event = storefrontEvent($this->tenantId, ['status' => EventStatus::Published]);
        attachEventMedia($this->tenantId, $event, 'cover', 'cover.jpg');

        $before = $this->getJson('http://'.$this->host.'/v1/storefront/events/'.$event->id)
            ->assertOk()
            ->assertConformsToOpenApi()
            ->json('cover_image.conversions');

        expect($before)->toBe(['thumb' => null, 'card' => null, 'hero' => null]);

        $job = Queue::pushed(PerformConversionsJob::class)->sole();

        app(TenantTransaction::class)->asTenant($this->tenantId, fn () => app()->call([$job, 'handle']));

        $after = $this->getJson('http://'.$this->host.'/v1/storefront/events/'.$event->id)
            ->assertOk()
            ->json('cover_image.conversions');

        expect($after['thumb'])->toBeString()->not->toBeEmpty()
            ->and($after['card'])->toBeString()->not->toBeEmpty()
            ->and($after['hero'])->toBeString()->not->toBeEmpty();
    });

    it('returns cover_image null and an empty gallery for an event without media', function () {
        storefrontEvent($this->tenantId, ['status' => EventStatus::Published]);

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events')
            ->assertOk()
            ->assertConformsToOpenApi();

        expect($response->json('data.0.cover_image'))->toBeNull()
            ->and($response->json('data.0.gallery'))->toBe([]);
    });

    it('still returns request.not_found for a draft event with media attached', function () {
        $event = storefrontEvent($this->tenantId, ['status' => EventStatus::Draft]);
        attachEventMedia($this->tenantId, $event, 'cover', 'cover.jpg');

        $this->getJson('http://'.$this->host.'/v1/storefront/events/'.$event->id)
            ->assertNotFound()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.not_found');
    });
});
