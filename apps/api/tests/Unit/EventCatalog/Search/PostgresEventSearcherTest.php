<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\Models\Event;
use App\EventCatalog\Support\Search\EventSearchDocumentBuilder;
use App\EventCatalog\Support\Search\EventSearchDocumentRow;
use App\EventCatalog\Support\Search\PostgresEventSearcher;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05c plan, task breakdown item 8, TDD sequencing Slice 6, Unit
 * (failing): "PostgresEventSearcher builds websearch_to_tsquery with the
 * locale regconfig, ranks with ts_rank_cd, tie-breaks on event_starts_at
 * then id, and joins the published-visibility scope." DB-backed,
 * mirroring EventSearchDocumentBuilderTest's own precedent
 * (tests/Unit/EventCatalog/Search/EventSearchDocumentBuilderTest.php):
 * websearch_to_tsquery, ts_rank_cd, and the visibility join are real
 * PostgreSQL behavior no fake connection can stand in for. Search
 * documents are planted directly with raw SQL (plantSearchDocument()
 * below), the same way tests/Feature/EventCatalog/RefreshSearchIndexTest.php
 * plants a stray row, rather than through RefreshSearchIndex, so each
 * test controls exactly the regconfig, weighting, and timestamps its
 * assertion depends on.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->searcher = app(PostgresEventSearcher::class);

    $this->tenantId = app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create([
            'default_locale' => 'en',
            'supported_locales' => ['en', 'de'],
        ])->id,
    );
});

afterEach(function (): void {
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        function (): void {
            DB::table('event_search_documents')->where('tenant_id', $this->tenantId)->delete();
            Event::query()->where('tenant_id', $this->tenantId)->delete();
        },
    );

    app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
    );
});

/**
 * Plants one event_search_documents row with a real, locale-appropriate
 * search_vector, reusing EventSearchDocumentBuilder's own SQL fragment
 * and bindings (the same way RefreshSearchIndex's upsert does) so the
 * vector this test asserts against is genuine PostgreSQL output, not a
 * hand-typed approximation.
 */
function plantSearchDocument(
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

function plantSearchableEvent(string $tenantId, EventStatus $status, DateTimeInterface $startAt): Event
{
    return app(TenantTransaction::class)->asTenant(
        $tenantId,
        fn () => Event::factory()->create([
            'tenant_id' => $tenantId,
            'status' => $status,
            'start_at' => $startAt,
            'end_at' => CarbonImmutable::instance($startAt)->addHours(3),
        ]),
    );
}

it('parses the query with the regconfig mapped from the requested locale, not a fixed configuration', function (): void {
    // "Running" only stems to the lexeme "run" under the english
    // configuration; en maps to english (EventSearchDocumentBuilder::
    // regconfigFor), so a query for the unstemmed word "running" must
    // still match a document whose own vector was built (and therefore
    // stored) as the stemmed lexeme "run". A searcher that ignored the
    // locale and parsed every query as "simple" would produce the
    // unstemmed lexeme "running" here and fail to match.
    $event = plantSearchableEvent($this->tenantId, EventStatus::Published, now()->addWeek());
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => plantSearchDocument($this->tenantId, $event->id, 'en', 'Running Festivals', null, now()->addWeek()),
    );

    $results = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $this->searcher->search('running', 'en'),
    );

    expect($results->pluck('id')->all())->toBe([$event->id]);
});

it('parses the query with the simple configuration for a locale with no dedicated mapping', function (): void {
    // de is unmapped, so EventSearchDocumentBuilder::regconfigFor maps it
    // to simple, which performs no stemming: the document's own vector
    // (built with simple) stores the unstemmed lexeme "running", so only
    // an unstemmed query for "running" matches it. A searcher that
    // ignored the locale and always parsed as english would stem the
    // query to "run" and fail to match this document.
    $event = plantSearchableEvent($this->tenantId, EventStatus::Published, now()->addWeek());
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => plantSearchDocument($this->tenantId, $event->id, 'de', 'Running Festivals', null, now()->addWeek()),
    );

    $results = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $this->searcher->search('running', 'de'),
    );

    expect($results->pluck('id')->all())->toBe([$event->id]);
});

it('ranks a name match above a description-only match', function (): void {
    $nameMatch = plantSearchableEvent($this->tenantId, EventStatus::Published, now()->addWeek());
    $descriptionMatch = plantSearchableEvent($this->tenantId, EventStatus::Published, now()->addWeek());

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($nameMatch, $descriptionMatch): void {
        plantSearchDocument($this->tenantId, $nameMatch->id, 'en', 'Jazz Festival', null, now()->addWeek());
        plantSearchDocument($this->tenantId, $descriptionMatch->id, 'en', 'Blues Night', 'An annual festival celebration', now()->addWeek());
    });

    $results = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $this->searcher->search('festival', 'en'),
    );

    expect($results->pluck('id')->all())->toBe([$nameMatch->id, $descriptionMatch->id]);
});

it('tie-breaks equally ranked results on event_starts_at ascending', function (): void {
    $later = plantSearchableEvent($this->tenantId, EventStatus::Published, now()->addMonth());
    $sooner = plantSearchableEvent($this->tenantId, EventStatus::Published, now()->addWeek());

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($later, $sooner): void {
        plantSearchDocument($this->tenantId, $later->id, 'en', 'Jazz Festival', null, now()->addMonth());
        plantSearchDocument($this->tenantId, $sooner->id, 'en', 'Jazz Festival', null, now()->addWeek());
    });

    $results = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $this->searcher->search('jazz', 'en'),
    );

    expect($results->pluck('id')->all())->toBe([$sooner->id, $later->id]);
});

it('tie-breaks equally ranked results with the same event_starts_at on id ascending', function (): void {
    $startsAt = now()->addWeek();
    $first = plantSearchableEvent($this->tenantId, EventStatus::Published, $startsAt);
    $second = plantSearchableEvent($this->tenantId, EventStatus::Published, $startsAt);

    app(TenantTransaction::class)->asTenant($this->tenantId, function () use ($first, $second, $startsAt): void {
        plantSearchDocument($this->tenantId, $first->id, 'en', 'Jazz Festival', null, $startsAt);
        plantSearchDocument($this->tenantId, $second->id, 'en', 'Jazz Festival', null, $startsAt);
    });

    $expectedOrder = collect([$first->id, $second->id])->sort()->values()->all();

    $results = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $this->searcher->search('jazz', 'en'),
    );

    expect($results->pluck('id')->all())->toBe($expectedOrder);
});

it('reapplies the published-visibility scope at query time, excluding a stale document for a draft event', function (): void {
    $draft = plantSearchableEvent($this->tenantId, EventStatus::Draft, now()->addWeek());
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => plantSearchDocument($this->tenantId, $draft->id, 'en', 'Jazz Festival', null, now()->addWeek()),
    );

    $results = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $this->searcher->search('jazz', 'en'),
    );

    expect($results->pluck('id')->all())->toBe([]);
});

it('reapplies the published-visibility scope at query time, excluding a stale document for a canceled event', function (): void {
    $canceled = plantSearchableEvent($this->tenantId, EventStatus::Canceled, now()->addWeek());
    app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => plantSearchDocument($this->tenantId, $canceled->id, 'en', 'Jazz Festival', null, now()->addWeek()),
    );

    $results = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => $this->searcher->search('jazz', 'en'),
    );

    expect($results->pluck('id')->all())->toBe([]);
});
