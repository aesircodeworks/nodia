# Execution Journal: Stage 5c, Search and Media

Durable record of execution runs for [stage-05c-search-media.md](../stage-05c-search-media.md). Append a new run header per run; never rewrite prior entries.

## Run: 2026-07-10

- Stage: 5c, Search and Media
- Date: 2026-07-10
- Branch: `feat/api-implementation`
- Base commit: `4eaa46288ddc4b36f7112bb78c25adc395a74529`

Verified starting state: Stages 1 through 5b are Done; Stage 5b closed at `4eaa462`. Stage 5c is Not started and no part of it exists in the codebase: `spatie/laravel-medialibrary` is absent from `apps/api/composer.json`, there is no `media` or `event_search_documents` migration, no custom Media model or path generator, no `EventSearcher` interface or `PostgresEventSearcher`, no `RefreshSearchIndex` consumer, no `search:rebuild` command, no `q` parameter on the storefront events list, and no media paths in `docs/openapi/openapi.yaml`. Available from prior stages: the RLS policy helper and two-tenant isolation fixture (Stages 1 and 2), capability Gates and the activity log (Stage 3), outbox delivery with `outbox_deliveries` idempotence tracking and Horizon workers (Stage 4), and `events` with translatable content, the publish lifecycle, the catalog outbox producers (`EventCreated`, `EventUpdated`, `EventPublished`, `EventCanceled`), and the storefront list and detail endpoints with locale negotiation (Stage 5a).

Open decision flagged by the plan (Risks): public-read media bucket versus signed URLs must be decided before task-02 merges, since it fixes the `url` semantics in the contract. Plan recommendation: public-read bucket for marketing images, revisit for Stage 8a ticket PDFs.

### Task checklist

- [x] task-01: Failing isolation and unit tests, medialibrary installed against current docs, custom Media model (UUIDv7, tenant stamping), adjusted `media` migration with RLS, tenant path generator, MinIO/S3 disk config (plan task 1, slice 1)
- [ ] task-02: Event `cover` and `gallery` collections, `POST /v1/events/{event}/media`, `GET /v1/events/{event}/media`, `DELETE /v1/media/{media}`, `MediaData` and upload Data objects, OpenAPI, policy and activity log wiring, generated types (plan task 2, slice 2)
- [ ] task-03: Queued `thumb`, `card`, `hero` conversions and conversion URLs in `MediaData` (plan task 3, slice 2)
- [ ] task-04: Storefront event Data objects gain additive `cover_image` and `gallery` fields, OpenAPI and TypeScript regenerated (plan task 4, slice 3)
- [ ] task-05: Tenant `logo` collection, `POST /v1/tenants/{tenant}/media` gated on `tenants.manage`, logo URL populating `BrandingSettingsData.logo_url` (plan task 5, slice 4)
- [ ] task-06: `event_search_documents` migration with RLS and GIN index, document builder with locale fallback and regconfig mapping, Tenancy read Action for `supported_locales` and `default_locale` (plan task 6, slice 5)
- [ ] task-07: `RefreshSearchIndex` outbox consumer with subscription routing, duplicate-delivery, out-of-order, and lifecycle tests (plan task 7, slice 5)
- [ ] task-08: `EventSearcher` interface, `PostgresEventSearcher`, config-selected container binding (plan task 8, slice 6)
- [ ] task-09: `q` parameter on the storefront events list with relevance smoke tests, pagination, OpenAPI parameter, fake-searcher seam proof (plan task 9, slice 6)
- [ ] task-10: `search:rebuild` artisan command with the state-scan-versus-replay equivalence test (plan task 10, slice 7)
- [ ] task-11: Sweep: error code registry entries (`payload_too_large` if absent), endpoint-level isolation coverage check, master plan status flip to Done (plan task 11)

### Review rounds

### Decisions and deviations

#### task-01, 2026-07-10

Landed the `media` table under RLS with a custom tenant-stamping Media model and tenant-prefixed object storage paths (plan task breakdown item 1, TDD sequencing Slice 1).

What landed:

- Verified `spatie/laravel-medialibrary`'s current documentation before installing: PHP 8.2+ and Laravel 10+ (compatible with this repo's `laravel/framework ^13.8`), `media_model`/`path_generator`/`disk_name` config overrides, and the package's own separate `uuid` column (`Spatie\MediaLibrary\MediaCollections\Models\Concerns\HasUuid`) versus this table's `id` primary key. Installed at `^11.23` (`composer require spatie/laravel-medialibrary`).
- `tests/Isolation/MediaIsolationTest.php` and its `tests/Isolation/Support/MediaFixture.php`, written and confirmed red first (`relation "media" does not exist` before the migration existed): two-tenant fixture proving cross-tenant SELECT returns nothing, cross-tenant UPDATE and DELETE affect zero rows, a forged `tenant_id` insert is rejected through `WITH CHECK`, a raw SQL query stays isolated, `nodia_platform` reads across every tenant, and — unlike every other tenant-scoped table's own isolation file — `nodia_platform` can additionally write under any `tenant_id` with no matching tenant context, proving the platform-write policy this table needs ahead of Stage 5c task 5.
- `tests/Unit/Support/Media/MediaModelTest.php` and `tests/Unit/Support/Media/TenantPathGeneratorTest.php`, written and confirmed red first (`Class "App\Support\Media\Models\Media" not found`): the custom Media model generates UUIDv7 primary keys (mirroring `App\Tenancy\Models\Tenant`'s own `TenantTest` precedent), stamps `tenant_id` from the active `TenantContext` when not supplied, refuses to create with no active tenant transaction (`LogicException` from `TenantContext::tenantId()` itself, reused rather than duplicated), and does not override an explicitly supplied `tenant_id`; the `TenantPathGenerator` prefixes the original, conversions, and responsive-images paths with `tenant_id/media_uuid/`.
- One migration, `2026_07_10_000029_create_media_table.php`, adjusting the package's published `create_media_table` migration for a UUIDv7 `id` (replacing the auto-incrementing bigint), `uuidMorphs('model')` (replacing `morphs('model')`, since every polymorphic owner — Event, Tenant now, Ticket in Stage 8a — has a uuid primary key) and a non-null `tenant_id` with a real foreign key to `tenants` plus its own index. `Rls::applyTenantPolicies('media', platformWrite: true)`: the platform-write grant is a deliberate addition beyond what venues/ticket_types need, because Stage 5c task 5's tenant branding logo upload runs entirely under the platform posture (`asPlatform()`, mirroring every `/v1/tenants/{tenant}` mutation), where `app.tenant_id` is the platform sentinel tenant rather than the target tenant; since merged migrations are never edited, this had to be decided now rather than deferred to task 5.
- `App\Support\Media\Models\Media` (extends the package's own Media model, adds `HasUuids`, stamps `tenant_id` from `App\Support\Tenancy\TenantContext` on `creating` when not already set) and `App\Support\Media\TenantPathGenerator` (implements the package's `PathGenerator` contract), both under `App\Support` alongside `Support\Audit` and `Support\Outbox` as shared infrastructure with no single owning bounded context; `tests/Architecture/PresetTest.php`'s Laravel-preset exemption list extended with `App\Support\Media\Models`, the same exemption `Support\Audit\Models` and `Support\Outbox\Models` already have.
- `config/media-library.php` (published from the package, then adjusted) points `media_model` at the custom model and `path_generator` at `TenantPathGenerator`; `config/filesystems.php` gained a `media` disk, S3-driver against the same MinIO backend the existing generic `s3` disk uses, with `visibility => public`. This resolves the plan's own open Risk (public-read bucket versus signed URLs) in favor of the plan's own stated recommendation: public-read for marketing images (event covers, galleries, tenant logos), revisited only when Stage 8a's ticket PDFs need a private disk.

Test evidence:

- `tests/Isolation/MediaIsolationTest.php`: 8/8 green (confirmed red first).
- `tests/Unit/Support/Media/MediaModelTest.php` and `TenantPathGeneratorTest.php`: 7/7 green (confirmed red first).
- Full `composer test` (Feature, Unit, Contract, Architecture, Isolation, Concurrency): 1600/1600 passed (Isolation 163/163, Unit 523/523, Architecture 37/37, Feature 641/641, Contract 220/220, Concurrency 16/16, run individually before the combined run, then the combined run confirmed the same total green).
- `composer lint` (Pint): passed. `composer analyse` (Larastan): passed, 0 errors. `composer types:generate`: no diff (this task adds no laravel-data classes or endpoints, so nothing for the TypeScript generator to pick up).

Deviations from the plan:

- None from the task instructions. One design decision beyond what the plan's Data model section states explicitly: the `platformWrite: true` RLS grant (see above), inferred from Stage 5c task 5's tenant branding upload running under the platform posture, since the migration cannot be revisited once merged.

Committed as `c8e1004` `feat(support): media infrastructure with tenant-scoped RLS`.
