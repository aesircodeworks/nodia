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

- [ ] task-01: Failing isolation and unit tests, medialibrary installed against current docs, custom Media model (UUIDv7, tenant stamping), adjusted `media` migration with RLS, tenant path generator, MinIO/S3 disk config (plan task 1, slice 1)
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
