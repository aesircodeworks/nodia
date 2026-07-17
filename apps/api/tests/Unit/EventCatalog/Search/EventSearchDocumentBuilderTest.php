<?php

use App\EventCatalog\Models\Event;
use App\EventCatalog\Support\Search\EventSearchDocumentBuilder;
use App\EventCatalog\Support\Search\EventSearchDocumentRow;
use App\Support\Tenancy\TenantTransaction;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MigratedDatabase;
use Tests\Support\PostgresTestDatabase;

/*
 * Stage-05c plan, task breakdown item 6, TDD sequencing Slice 5, Unit
 * (failing): "document builder resolves translations with fallback per
 * locale; locale-to-regconfig mapping including the simple fallback for
 * unmapped locales; weighting puts name at weight A and description at
 * weight B." DB-backed (unlike a pure value-object test) because
 * documentsFor() genuinely calls App\Tenancy\Actions\
 * ResolveTenantLocaleSettings, which reads a real tenants row, mirroring
 * tests/Unit/EventCatalog/EventModelTest.php's own precedent for
 * exercising a model through a real tenant transaction.
 */

beforeEach(function (): void {
    PostgresTestDatabase::use();
    MigratedDatabase::ensure();

    $this->builder = app(EventSearchDocumentBuilder::class);
});

afterEach(function (): void {
    if (isset($this->tenantId)) {
        app(TenantTransaction::class)->asTenant(
            $this->tenantId,
            fn () => Event::query()->where('tenant_id', $this->tenantId)->delete(),
        );

        app(TenantTransaction::class)->asPlatform(
            fn () => Tenant::query()->whereKey($this->tenantId)->delete(),
        );
    }
});

function makeTenantWithLocales(string $defaultLocale, array $supportedLocales): string
{
    return app(TenantTransaction::class)->asPlatform(
        fn () => Tenant::factory()->create([
            'default_locale' => $defaultLocale,
            'supported_locales' => $supportedLocales,
        ])->id,
    );
}

it('produces one document per tenant-supported locale', function (): void {
    $this->tenantId = makeTenantWithLocales('en', ['en', 'fr']);

    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => ['en' => 'Jazz Festival'],
            'description' => ['en' => 'Live music all weekend'],
        ]),
    );

    $documents = $this->builder->documentsFor($event);

    expect($documents)->toHaveCount(2)
        ->and(array_map(fn (EventSearchDocumentRow $row) => $row->locale, $documents))->toBe(['en', 'fr']);
});

it('resolves a locale own translation when one exists', function (): void {
    $this->tenantId = makeTenantWithLocales('en', ['en', 'fr']);

    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => ['en' => 'Jazz Festival', 'fr' => 'Festival de Jazz'],
            'description' => ['en' => 'Live music all weekend', 'fr' => 'Musique live tout le week-end'],
        ]),
    );

    [$en, $fr] = $this->builder->documentsFor($event);

    expect($en->name)->toBe('Jazz Festival')
        ->and($en->description)->toBe('Live music all weekend')
        ->and($fr->name)->toBe('Festival de Jazz')
        ->and($fr->description)->toBe('Musique live tout le week-end');
});

it('falls back to the tenant default locale when a locale has no translation of its own', function (): void {
    $this->tenantId = makeTenantWithLocales('en', ['en', 'fr']);

    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => ['en' => 'Jazz Festival'],
            'description' => ['en' => 'Live music all weekend'],
        ]),
    );

    [$en, $fr] = $this->builder->documentsFor($event);

    expect($fr->name)->toBe('Jazz Festival')
        ->and($fr->description)->toBe('Live music all weekend')
        ->and($en->name)->toBe('Jazz Festival');
});

it('leaves description null when neither the locale nor the default locale has one', function (): void {
    $this->tenantId = makeTenantWithLocales('en', ['en']);

    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => ['en' => 'Jazz Festival'],
            'description' => ['en' => ''],
        ]),
    );

    [$en] = $this->builder->documentsFor($event);

    expect($en->description)->toBeNull();
});

it('denormalizes the event own start_at onto every locale document', function (): void {
    $this->tenantId = makeTenantWithLocales('en', ['en', 'fr']);

    $event = app(TenantTransaction::class)->asTenant(
        $this->tenantId,
        fn () => Event::factory()->create(['tenant_id' => $this->tenantId]),
    );

    $documents = $this->builder->documentsFor($event);

    foreach ($documents as $document) {
        expect($document->eventStartsAt->equalTo($event->start_at))->toBeTrue();
    }
});

it('maps pt to portuguese, en to english, and every unmapped locale to simple', function (): void {
    expect(EventSearchDocumentBuilder::regconfigFor('pt'))->toBe('portuguese')
        ->and(EventSearchDocumentBuilder::regconfigFor('en'))->toBe('english')
        ->and(EventSearchDocumentBuilder::regconfigFor('fr'))->toBe('simple')
        ->and(EventSearchDocumentBuilder::regconfigFor('de'))->toBe('simple');
});

it('weights the name at A and the description at B in the generated search vector', function (): void {
    PostgresTestDatabase::use();

    $row = new EventSearchDocumentRow(
        tenantId: (string) Str::uuid7(),
        eventId: (string) Str::uuid7(),
        locale: 'en',
        name: 'Jazz Festival',
        description: 'Live music all weekend',
        regconfig: EventSearchDocumentBuilder::regconfigFor('en'),
        eventStartsAt: now()->toImmutable(),
    );

    $sql = 'select ('.EventSearchDocumentBuilder::searchVectorSql().')::text as vector';
    $vector = DB::selectOne($sql, EventSearchDocumentBuilder::searchVectorBindings($row))->vector;

    expect($vector)->toBe("'festiv':2A 'jazz':1A 'live':3B 'music':4B 'weekend':6B");
});

it('applies the simple configuration for an unmapped locale, skipping english stemming and stopword removal', function (): void {
    PostgresTestDatabase::use();

    $row = new EventSearchDocumentRow(
        tenantId: (string) Str::uuid7(),
        eventId: (string) Str::uuid7(),
        locale: 'de',
        name: 'Running Festivals',
        description: null,
        regconfig: EventSearchDocumentBuilder::regconfigFor('de'),
        eventStartsAt: now()->toImmutable(),
    );

    $sql = 'select ('.EventSearchDocumentBuilder::searchVectorSql().')::text as vector';
    $vector = DB::selectOne($sql, EventSearchDocumentBuilder::searchVectorBindings($row))->vector;

    expect($vector)->toBe("'festivals':2A 'running':1A");
});

it('produces an empty B-weighted contribution when the description is null', function (): void {
    PostgresTestDatabase::use();

    $row = new EventSearchDocumentRow(
        tenantId: (string) Str::uuid7(),
        eventId: (string) Str::uuid7(),
        locale: 'en',
        name: 'Jazz Festival',
        description: null,
        regconfig: EventSearchDocumentBuilder::regconfigFor('en'),
        eventStartsAt: now()->toImmutable(),
    );

    $sql = 'select ('.EventSearchDocumentBuilder::searchVectorSql().')::text as vector';
    $vector = DB::selectOne($sql, EventSearchDocumentBuilder::searchVectorBindings($row))->vector;

    expect($vector)->toBe("'festiv':2A 'jazz':1A")
        ->and($vector)->not->toContain('B');
});
