<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Support\Search\EventSearchDocumentBuilder;
use App\EventCatalog\Support\Search\EventSearchDocumentRow;
use App\EventCatalog\Support\Search\EventSearcher;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05c plan, task breakdown item 9 (TDD sequencing Slice 6, Feature):
 * the q parameter on GET /v1/storefront/events. The projector
 * (App\EventCatalog\Jobs\RefreshSearchIndex, task-07) and PostgresEventSearcher
 * itself (task-08) already have dedicated test coverage, so this file
 * plants event_search_documents rows directly, the same way
 * tests/Unit/EventCatalog/Search/PostgresEventSearcherTest.php does, to
 * control exactly the content and locale each smoke test depends on
 * without re-exercising the outbox pipeline.
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
            DB::table('event_search_documents')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_deliveries')->where('tenant_id', $tenantId)->delete();
            DB::table('outbox_events')->where('tenant_id', $tenantId)->delete();
            DB::table('events')->where('tenant_id', $tenantId)->delete();
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
function searchStorefrontEvent(string $tenantId, array $attributes = []): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create(['tenant_id' => $tenantId, ...$attributes]),
    );
}

/**
 * Plants one event_search_documents row with a real, locale-appropriate
 * search_vector, reusing EventSearchDocumentBuilder's own SQL fragment and
 * bindings (the same way RefreshSearchIndex's upsert and
 * PostgresEventSearcherTest's own plantSearchDocument() do), so this test
 * suite's assertions exercise genuine PostgreSQL ranking rather than a
 * hand-typed approximation.
 */
function plantStorefrontSearchDocument(
    string $tenantId,
    string $eventId,
    string $locale,
    string $name,
    ?string $description,
    DateTimeInterface $eventStartsAt,
): void {
    $row = new EventSearchDocumentRow(
        tenantId: $tenantId,
        eventId: $eventId,
        locale: $locale,
        name: $name,
        description: $description,
        regconfig: EventSearchDocumentBuilder::regconfigFor($locale),
        eventStartsAt: CarbonImmutable::instance($eventStartsAt),
    );

    $searchVectorSql = EventSearchDocumentBuilder::searchVectorSql();

    DB::insert(
        <<<SQL
            insert into event_search_documents
                (id, tenant_id, event_id, locale, name, description, event_starts_at, search_vector, created_at, updated_at)
            values (?, ?, ?, ?, ?, ?, ?, {$searchVectorSql}, now(), now())
            SQL,
        [
            (string) Str::uuid7(),
            $tenantId,
            $eventId,
            $locale,
            $name,
            $description,
            $eventStartsAt,
            ...EventSearchDocumentBuilder::searchVectorBindings($row),
        ],
    );
}

describe('GET /v1/storefront/events?q=', function () {
    it('ranks an event with the term in its name above one with the term only in its description', function () {
        // start_at deliberately puts descriptionMatch first in plain
        // start_at ascending order (stage-05a's own default list order),
        // so this assertion only holds when the response is actually
        // ranked by relevance rather than falling back to the plain list.
        $nameMatch = searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Jazz Festival'], 'start_at' => CarbonImmutable::now()->addWeeks(2), 'end_at' => CarbonImmutable::now()->addWeeks(2)->addHours(3)]);
        $descriptionMatch = searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Blues Night'], 'start_at' => CarbonImmutable::now()->addWeek(), 'end_at' => CarbonImmutable::now()->addWeek()->addHours(3)]);

        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($nameMatch, $descriptionMatch): void {
            plantStorefrontSearchDocument($this->tenantId, $nameMatch->id, 'en', 'Jazz Festival', null, $nameMatch->start_at);
            plantStorefrontSearchDocument($this->tenantId, $descriptionMatch->id, 'en', 'Blues Night', 'An annual festival celebration', $descriptionMatch->start_at);
        });

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events?q=festival')
            ->assertOk()
            ->assertConformsToOpenApi();

        expect(collect($response->json('data'))->pluck('id')->all())->toBe([$nameMatch->id, $descriptionMatch->id]);
    });

    it('finds an event translated only in the default locale through the non-default locale fallback document', function () {
        $event = searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Jazz Festival']]);
        // Published but never queried for; only in the response if q is
        // ignored entirely, which would defeat this test's purpose.
        $decoy = searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Comedy Night']]);

        // Simulates exactly what EventSearchDocumentBuilder would have
        // produced for the fr document: no fr translation exists, so the
        // builder falls back to the tenant default locale (en) content,
        // regardless of the fr row's own regconfig.
        app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => plantStorefrontSearchDocument($this->tenantId, $event->id, 'fr', 'Jazz Festival', null, $event->start_at),
        );

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events?q=festival&locale=fr')
            ->assertOk()
            ->assertConformsToOpenApi();

        $ids = collect($response->json('data'))->pluck('id')->all();

        expect($ids)->toBe([$event->id])
            ->and($ids)->not->toContain($decoy->id);
    });

    it('never returns a draft or canceled event even with a stale document row planted directly', function () {
        $draft = searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Draft, 'name' => ['en' => 'Jazz Festival']]);
        $canceled = searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Canceled, 'name' => ['en' => 'Jazz Festival']]);
        // Published but has no search document of its own and does not
        // match the query; only appears if q is ignored and the plain
        // published list is returned instead of real search results.
        searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Comedy Night']]);

        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($draft, $canceled): void {
            plantStorefrontSearchDocument($this->tenantId, $draft->id, 'en', 'Jazz Festival', null, $draft->start_at);
            plantStorefrontSearchDocument($this->tenantId, $canceled->id, 'en', 'Jazz Festival', null, $canceled->start_at);
        });

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events?q=jazz')
            ->assertOk()
            ->assertConformsToOpenApi();

        expect($response->json('data'))->toBe([]);
    });

    it('rejects a one-character q with request.validation_failed', function () {
        $this->getJson('http://'.$this->host.'/v1/storefront/events?q=a')
            ->assertUnprocessable()
            ->assertConformsToOpenApi()
            ->assertJsonPath('code', 'request.validation_failed')
            ->assertJsonPath('errors.q.0', fn ($message) => is_string($message));
    });

    it('paginates results with the standard data/links/meta envelope', function () {
        $event = searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Jazz Festival']]);

        app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => plantStorefrontSearchDocument($this->tenantId, $event->id, 'en', 'Jazz Festival', null, $event->start_at),
        );

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events?q=jazz')
            ->assertOk()
            ->assertConformsToOpenApi();

        $body = $response->json();

        expect(array_keys($body))->toContain('data', 'links', 'meta');
    });

    it('returns a deterministic order across repeated identical requests', function () {
        $startsAt = CarbonImmutable::now()->addWeek();
        $first = searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Jazz Festival'], 'start_at' => $startsAt, 'end_at' => $startsAt->addHours(3)]);
        $second = searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Jazz Festival'], 'start_at' => $startsAt, 'end_at' => $startsAt->addHours(3)]);

        app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($first, $second, $startsAt): void {
            plantStorefrontSearchDocument($this->tenantId, $first->id, 'en', 'Jazz Festival', null, $startsAt);
            plantStorefrontSearchDocument($this->tenantId, $second->id, 'en', 'Jazz Festival', null, $startsAt);
        });

        $expectedOrder = collect([$first->id, $second->id])->sort()->values()->all();

        $firstResponse = $this->getJson('http://'.$this->host.'/v1/storefront/events?q=jazz')->assertOk();
        $secondResponse = $this->getJson('http://'.$this->host.'/v1/storefront/events?q=jazz')->assertOk();

        expect(collect($firstResponse->json('data'))->pluck('id')->all())->toBe($expectedOrder)
            ->and(collect($secondResponse->json('data'))->pluck('id')->all())->toBe($expectedOrder);
    });

    it('leaves the list unchanged from stage 5a behavior when q is absent', function () {
        searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'No Query Event']]);

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events')
            ->assertOk()
            ->assertConformsToOpenApi();

        expect(collect($response->json('data'))->pluck('name')->all())->toContain('No Query Event');
    });

    it('passes against a container-swapped fake EventSearcher, proving the Meilisearch seam', function () {
        $event = searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Fake Result']]);
        // Published, otherwise indistinguishable from $event to the plain
        // list query; only excluded if the controller actually calls the
        // container-bound (fake) EventSearcher rather than falling back to
        // the plain published list.
        searchStorefrontEvent($this->tenantId, ['status' => EventStatus::Published, 'name' => ['en' => 'Real Event Not Returned By The Fake']]);

        $fake = new class($event) implements EventSearcher
        {
            public function __construct(private readonly Event $event) {}

            public function search(string $query, string $locale): LengthAwarePaginator
            {
                return new LengthAwarePaginator(new EloquentCollection([$this->event]), 1, 15, 1);
            }
        };

        $this->app->instance(EventSearcher::class, $fake);

        $response = $this->getJson('http://'.$this->host.'/v1/storefront/events?q=anything')
            ->assertOk()
            ->assertConformsToOpenApi();

        expect(collect($response->json('data'))->pluck('id')->all())->toBe([$event->id]);
    });
});
