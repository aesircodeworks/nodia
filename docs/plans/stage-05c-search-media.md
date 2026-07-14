# Stage 5c: Search and Media

Implementation plan for the third slice of Stage 5 (Event Catalog) in [api-implementation-plan.md](../api-implementation-plan.md). The master plan's "Method: the TDD loop" and "Contract pipeline" sections are binding for every slice below. Design authority is [system-design.md](../system-design.md); convention authority is [api-conventions.md](../api-conventions.md), [data-conventions.md](../data-conventions.md), and [event-conventions.md](../event-conventions.md).

## Scope and non-goals

This stage delivers the two remaining Event Catalog capabilities named in the master plan: media through medialibrary, and PostgreSQL full-text search behind an interface that permits the Meilisearch upgrade path (system-design 15.3 names PostgreSQL full-text search as the initial engine with Meilisearch as the designated upgrade).

In scope:

- The medialibrary `media` table, with the published migration adjusted for UUIDv7 keys and a non-null `tenant_id` under RLS, per data-conventions and ADR 014. This is shared infrastructure: system-design 8.1 places tenant branding assets and file attachments in this polymorphic table, and system-design 15.2 and 15.4 route event images, branding assets, and later ticket PDFs through it.
- A custom Media model (UUIDv7, tenant stamping), a tenant-prefixed path generator for object storage, and MinIO/S3 disk configuration (system-design 15.3: any S3-compatible store, MinIO for development).
- Event image management: cover (single file) and gallery collections on events, admin upload, list, and delete endpoints, queued image conversions, and additive exposure of image URLs on the Stage 5a storefront read surface (system-design 8.2 note: event images and other attachments are medialibrary media records).
- Tenant branding assets: a logo collection on tenants with upload and delete, because ADR 014 assigns branding assets to medialibrary and no other stage covers the upload surface.
- Full-text search over published events: an `event_search_documents` projection maintained by an outbox consumer (`RefreshSearchIndex`, the EventCatalog job named in system-design 3.2; system-design 9.2 routes catalog events to the search index refresh), a `PostgresEventSearcher` behind an `EventSearcher` interface bound in the EventCatalog service provider, a purpose-built `q` parameter on the storefront events list, and a rebuild command.

Non-goals, explicitly deferred:

- Meilisearch driver: deferred behind the `EventSearcher` interface until PostgreSQL full-text search demonstrably falls short (master plan open decisions; system-design 15.3). This stage's exit proves the seam exists, not the second driver.
- Ticket PDF generation and storage: Stage 8a (`GenerateTicketPdf` stores medialibrary attachments per system-design 15.4). Stage 8a consumes the media infrastructure shipped here.
- Admin full-text search: admin lists keep the Stage 5a query-builder filters (api-conventions list rules). Full-text stays a storefront concern until a need is demonstrated.
- Category filtering in search: categories are named in the Event Catalog context description (system-design 3.1) but absent from the data model; the master plan defers them until the design defines them.
- Typo tolerance, synonyms, prefix-as-you-type suggestions, and search analytics: these are the Meilisearch triggers, not PostgreSQL features to emulate.
- Venue and seat map imagery, media reordering endpoints, responsive image generation, and on-the-fly manipulations: additive later if a feature needs them.
- Frontend work of any kind (master plan preamble: this plan covers the API only).

## Dependencies

Required from earlier stages:

- Stage 1: RFC 9457 problem handler with the stable code registry, the Contract suite and OpenAPI conformance gate, the real Isolation harness (two-tenant fixture with an RLS-enabled test connection).
- Stage 2: `tenants` (including the `supported_locales` and `default_locale` columns the document builder needs; Stage 2 exposes no read Action for them, so task 6 here ships one in Tenancy, since EventCatalog may not query the `tenants` table), tenant resolution middleware (Host for storefront routes, `X-Tenant-Id` for admin routes), the `SET LOCAL app.tenant_id` transaction wrapper and per-table RLS policy pattern (system-design 4.1).
- Stage 3: staff authentication, capability-based policies (system-design 5.3), activity log for admin mutations.
- Stage 4: outbox delivery via Horizon, `outbox_deliveries` tracking, idempotent-consumer pattern, and the replay primitive (system-design 9.1, 9.2). Queued media conversions also need Horizon workers.
- Stage 5a: `events` with translatable name and description (system-design 8.2, 12), the draft/published/canceled lifecycle, catalog events recorded to the outbox (`EventCreated`, `EventUpdated`, `EventPublished`, `EventCanceled`, system-design 9.3), the storefront events list and detail endpoints with locale negotiation, and the published-visibility scope that hides drafts.

Stage 5b (seating templates) is not a dependency; 5c can start as soon as 5a merges and can proceed in parallel with 5b.

Consumed by later stages:

- Stage 8a: `GenerateTicketPdf` stores PDFs through the media infrastructure (custom Media model, tenant path generator, disk config) shipped here.
- Stage 11: reporting projectors follow the same idempotent outbox-consumer pattern this stage's search projector exercises; no direct artifact dependency.
- The storefront frontends (roadmap Phase 2) consume the image URLs and search parameter, outside this plan's scope.

## Data model

No money columns exist in this stage; the money rules apply vacuously. All primary keys are UUIDv7 via `HasUuids`, all timestamps UTC, all tables snake_case plural per data-conventions.

### media

The medialibrary published migration, adjusted before use per data-conventions ("Published migrations from third-party packages (activitylog, medialibrary) are adjusted for UUID keys and non-null `tenant_id`") and ADR 014.

- `id` uuid primary key (custom Media model extending medialibrary's, with `HasUuids`).
- `tenant_id` uuid non-null. Stamped automatically from the request tenant context on create.
- `model_type` string, `model_id` uuid: the polymorphic owner (Event, Tenant now; Ticket in Stage 8a). Composite index on `(model_type, model_id)`.
- `uuid` uuid, medialibrary's own public identifier column, unique index. Kept because medialibrary's URL and conversion machinery uses it.
- `collection_name`, `name`, `file_name`, `mime_type`, `disk`, `conversions_disk` strings; `size` bigint; `manipulations`, `custom_properties`, `generated_conversions`, `responsive_images` json; `order_column` integer (plain integer maintained by medialibrary's sortable trait, not an auto-increment column, so it does not violate the no-auto-increment rule whose sole exception is `outbox_events.sequence`).
- Index on `tenant_id`.
- RLS policy in the same migration, single-table, comparing `tenant_id` to `current_setting('app.tenant_id')`, following the Stage 2 policy pattern. The table does not merge without it.

Object storage layout: a custom `PathGenerator` prefixes every stored path with `tenant_id/media_uuid/`, so tenant assets are partitioned in the bucket as well as in the database. RLS protects the rows; the path prefix keeps the objects auditable and makes per-tenant export and erasure (Stage 12) tractable.

Collections registered in this stage:

- `Event`: `cover` (single file, replaced on re-upload) and `gallery` (multiple, ordered). Accepted mime types `image/jpeg`, `image/png`, `image/webp`; max size from config (`media.max_upload_kb`, default 10240).
- `Tenant`: `logo` (single file), same accepted types.

Conversions: `thumb`, `card`, `hero` (fixed widths from config), generated on queued jobs via Horizon, stored alongside the original. Conversion URLs appear in the wire shape once generated; clients receive the original URL immediately.

### event_search_documents

The search projection, owned by EventCatalog. One row per published event per tenant-supported locale.

- `id` uuid primary key.
- `tenant_id` uuid non-null.
- `event_id` uuid non-null, foreign key to `events`, cascade on delete.
- `locale` string non-null.
- `name` text non-null, `description` text nullable: the locale-resolved content the vector was built from, kept for debuggability and rebuild verification.
- `search_vector` tsvector non-null, written by the projector (not a generated column, because the text search configuration varies by locale and `to_tsvector(regconfig, text)` with a column-derived regconfig is not immutable). The projector computes `setweight(to_tsvector(config, name), 'A') || setweight(to_tsvector(config, description), 'B')`, mapping locale to regconfig in code (`pt` to `portuguese`, `en` to `english`, unmapped locales to `simple`).
- `event_starts_at` timestamptz non-null, denormalized from the event for deterministic rank tie-breaking without a join in the ORDER BY.
- Unique index on `(event_id, locale)`; the projector upserts on this key with `ON CONFLICT`, which is what makes concurrent duplicate deliveries harmless.
- GIN index on `search_vector`, custom-named `event_search_documents_search_vector_idx` per data-conventions (the builder cannot express it, so it ships as a raw statement in the migration).
- Index on `tenant_id`.
- RLS policy in the same migration, same pattern as above.

Rows exist only for published events. Locale coverage is every tenant-supported locale, with laravel-translatable fallback applied at document-build time (system-design 12: fallback to the tenant default locale), so a query in any supported locale finds every published event even when a translation is missing.

## Domain events

Produced: none. This stage adds no event types to the registry in system-design 9.3, and event-conventions require registry updates only when a type is added. Media mutations do not record domain events; nothing downstream consumes them, and adding speculative types violates the registry discipline.

Consumed, all by the `RefreshSearchIndex` consumer (EventCatalog's own job, system-design 3.2; routing per system-design 9.2 "catalog events feed the search index"):

| Event            | Effect on the projection                                                                                 |
| ---------------- | -------------------------------------------------------------------------------------------------------- |
| `EventPublished` | Upsert one document per tenant-supported locale from current event state                                 |
| `EventUpdated`   | If the event is currently published, upsert all locale documents; if not published, delete any documents |
| `EventCanceled`  | Delete all documents for the event                                                                       |
| `EventCreated`   | No-op (drafts are never indexed); subscribed for completeness so routing stays uniform                   |

Envelope: these events are Stage 5a producers and already carry the full envelope per event-conventions (`id`, `sequence`, `type`, non-null `tenant_id`, `aggregate_type` `event`, `aggregate_id`, `correlation_id`, `occurred_at`, payload). This stage adds a subscriber, not a producer, so no payload changes; the consumer uses only the aggregate ID and loads current event state from its own context's models (permitted: the projector lives inside EventCatalog, which owns both `events` and the projection). The tenant locale configuration the document builder needs (`supported_locales`, `default_locale`) is Tenancy-owned, so the builder obtains it through a Tenancy read Action, never by querying `tenants` (system-design 3.1 boundary rule), following the Stage 5a settlement-currency Action precedent; task 6 ships that Action.

Idempotence and ordering: the consumer is idempotent by event ID via `outbox_deliveries` per event-conventions, and additionally naturally idempotent because it rebuilds documents from current event state rather than applying deltas; processing the same event twice, or processing `EventUpdated` and `EventPublished` in either order, converges on the same rows. It therefore needs no per-aggregate ordered consumption (the Stage 4 ordered helper stays reserved for the ledger, system-design 9.2). Rebuild: system-design 9.1 prescribes rebuilding projections by rescanning the outbox in sequence order through the Stage 4 replay primitive; this stage deliberately deviates from that mechanism because search documents derive entirely from current event state, so `search:rebuild` scans published events directly instead. The master plan's Stage 5c section sanctions this deviation (search is a disposable derived index; historical payload equivalence is not required); its justification is the slice 7 equivalence test asserting the state scan produces row-for-row the same index an outbox replay of the latest catalog event per aggregate would.

## Endpoints

All routes under `/v1`, snake_case JSON, laravel-data objects as source of truth, OpenAPI merged with each endpoint per the contract pipeline. Admin routes resolve tenant via `X-Tenant-Id` against memberships; storefront routes resolve via Host (api-conventions). All error responses are RFC 9457 problem documents with stable `code` values from the Stage 1 registry.

### Storefront search

`GET /v1/storefront/events?q={query}`

Extends the Stage 5a storefront events list with a purpose-built `q` parameter (api-conventions: storefront lists expose purpose-built parameters, never query-builder passthrough). Without `q`, behavior is unchanged from 5a. With `q`:

- The negotiated locale (Stage 5a negotiation: explicit `locale` parameter, then `Accept-Language`, then tenant default; system-design 12 also places customer preference in the chain, but Stage 5a defers that step to Stage 7 because these storefront reads carry no customer identity) selects which locale documents are queried.
- The query is parsed with `websearch_to_tsquery` and matched against `search_vector`; results are ranked by `ts_rank_cd` with a deterministic tie-break on `event_starts_at` ascending then `id`.
- Results join to `events` and reapply the Stage 5a published-visibility scope at query time, so a stale document can never leak a draft or canceled event.
- Page pagination with the standard paginator envelope (`data`, `links`, `meta`); search results are a bounded collection under api-conventions, and relevance order is not a stable cursor key.
- Response items are the Stage 5a storefront event list Data object, now including image fields (below). No new response class for search.

Validation: `q` is a string, 2 to 200 characters. Errors: `422` problem document with `code` `request.validation_failed` and the `errors` map; `404` with `code` `unknown_host` for an unresolvable host (Stage 2 resolution middleware behavior, unchanged).

### Storefront media exposure

No new endpoint. The Stage 5a storefront event detail and list Data objects gain additive fields:

- `cover_image`: nullable `MediaImageData`
- `gallery`: array of `MediaImageData`

`MediaImageData`: `{ id, url, conversions: { thumb, card, hero }, alt_text }` where each conversion value is a nullable URL string (null until the queued conversion completes) and `alt_text` comes from media custom properties. Additive fields only, so the OpenAPI change is non-breaking and the TypeScript regeneration is drift-checked as usual.

### Admin event media

Capability `events.manage` via the EventCatalog policy (the Stage 3 registry entry Stage 5a gates every event mutation on); mutations recorded to the activity log (Stage 3).

`POST /v1/events/{event}/media`

Multipart request bound to `EventMediaUploadData`: `file` (required, image mime allowlist, size cap) and `collection` (required, enum `cover` or `gallery`). Uploading to `cover` replaces the existing file (single-file collection). Returns `201` with `MediaData`: `{ id, collection, file_name, mime_type, size, url, conversions, alt_text, order, created_at }`.

Errors: `422` `request.validation_failed` with `errors` map for missing file, disallowed `collection`, disallowed mime type, or size over the configured cap; `404` `request.not_found` for an event outside the tenant (RLS makes this indistinguishable from nonexistence); `403` `auth.forbidden` for missing capability; `413` payloads rejected at the HTTP layer before validation are still rendered as problem documents with `code` `payload_too_large` by the Stage 1 handler.

`GET /v1/events/{event}/media?collection={collection}`

Bounded list, no pagination, ordered by collection then `order_column`. Returns `200` with `data` array of `MediaData`. Optional `collection` filter validated against the enum.

`DELETE /v1/media/{media}`

Top-level resource per api-conventions nesting rules. Deletes the media row, its stored file, and conversions. Returns `204`. Authorization delegates to the owning model's policy (event media requires `events.manage`, tenant logo requires `tenants.manage` per the branding section below). Errors: `404` `request.not_found`, `403` `auth.forbidden`.

### Admin tenant branding media

`POST /v1/tenants/{tenant}/media`

Body `TenantMediaUploadData`: `file` plus `collection` (enum `logo`). Single-file collection, replace on re-upload. Capability: `tenants.manage`. Neither Stage 2 nor Stage 3 defines a branding-specific capability (Stage 2 branding mutations go through the platform-admin `PATCH /v1/tenants/{tenant}`, and the Stage 3 registry's only tenancy entry is `tenants.manage`), so logo upload adopts the same platform-scope semantics as the rest of the tenant mutation surface rather than extending the Capability enum. Returns `201` `MediaData`. Same error codes as event media. Deletion goes through `DELETE /v1/media/{media}`.

The logo URL populates the `logo_url` placeholder Stage 2 already defines on `BrandingSettingsData`, so the platform-admin tenant read surface (`GET /v1/tenants/{tenant}`, `TenantData`) returns it. No storefront branding read endpoint exists in any dependency stage (Stage 2 ships the storefront route group empty and Stage 5a populates it only with the events list and detail), so a storefront branding surface stays out of scope here; the storefront frontends read branding through whichever stage introduces that endpoint.

## TDD sequencing

Every slice runs the double loop from the master plan: outside feature test first, contract second, inside unit tests third, then green, refactor, `composer types:generate`, commit scoped `catalog` (or `tenancy` for slice 4, `support` for slice 1 infra). The two non-negotiable test-first rules that apply here: both new tables start with failing isolation tests, and the search projector starts with a failing duplicate-delivery test. No invariant-guarding conditional-UPDATE transitions exist in this stage, so the Concurrency suite is not applicable; the only concurrent write path covered by test, the projector upsert, gets a duplicate-delivery test rather than an oversell simulation. One known race is accepted without a guard: single-file collection replacement (cover, logo) is medialibrary's clear-then-add, a read-then-write that two concurrent uploads to the same collection can race, potentially leaving two rows or none. This is acceptable for idempotently re-uploadable marketing images with no financial or inventory invariant; the losing state is corrected by the next upload, and guarding it would mean locking around or forking medialibrary internals. The acceptance is recorded here so it reads as a decision, not an oversight.

Slice 1: media infrastructure

- Isolation (first, failing): two-tenant fixture proves cross-tenant SELECT, UPDATE, and DELETE on `media` return zero rows under RLS.
- Unit (failing): custom Media model generates UUIDv7 ids; tenant stamping fills `tenant_id` from the request tenant context and refuses to create without one; `PathGenerator` prefixes paths with `tenant_id/media_uuid/`.
- Then: install medialibrary against its current documentation, override the model, write the adjusted migration with the RLS policy, configure the media disk for MinIO/S3.

Slice 2: admin event media endpoints

- Feature (first, failing): upload cover returns 201 with the `MediaData` shape; second cover upload replaces the first (list shows one); gallery accepts multiple and preserves order; disallowed mime and oversized file return 422 problem documents with `request.validation_failed` and correct `errors` keys; missing capability returns 403 `auth.forbidden`; other-tenant event returns 404 `request.not_found`; delete returns 204 and the file and conversions are gone from the fake disk; list filters by collection.
- Contract: OpenAPI paths for the three endpoints, conformance asserted by the feature tests.
- Unit (failing): collection registration (accepted mimes, single-file semantics); conversion definitions enqueue jobs on the expected queue.
- Isolation: endpoint-level cross-tenant denial for all three routes (the suite covers every endpoint per master plan).

Slice 3: storefront media exposure

- Feature (first, failing): published event detail and list include `cover_image` and `gallery` with conversion URLs (null before conversion, populated after running the queued job in-test); an event without media returns `cover_image: null, gallery: []`; draft invisibility on the storefront path is unchanged.
- Contract: additive fields in the storefront event schemas; TypeScript regenerated without drift.

Slice 4: tenant branding media

- Feature (first, failing): logo upload, replace, delete; `tenants.manage` denial; `GET /v1/tenants/{tenant}` returns the logo URL in `BrandingSettingsData.logo_url`.
- Contract and isolation as above. Commit scope `tenancy`.

Slice 5: search projection

- Isolation (first, failing): cross-tenant access to `event_search_documents` fails under RLS.
- Feature/consumer (first, failing): duplicate delivery of the same `EventPublished` event produces exactly one document set (the master plan's mandatory outbox-consumer test); `EventPublished` creates one row per tenant-supported locale with fallback-resolved content; `EventUpdated` on a published event refreshes documents, on a draft deletes any; `EventCanceled` deletes all rows; out-of-order delivery (`EventUpdated` processed after a later `EventCanceled`) converges on the canceled state because the projector reads current status.
- Unit (failing): document builder resolves translations with fallback per locale; locale-to-regconfig mapping including the `simple` fallback for unmapped locales; weighting puts name at weight A and description at weight B.
- Then: migration with RLS and GIN index, builder, consumer job, subscription routing in the EventCatalog provider.

Slice 6: storefront search endpoint

- Feature (first, failing): relevance smoke tests per the master plan Stage 5 test list: an event with the term in its name outranks one with the term only in its description; a query in a supported non-default locale finds an event translated only in the default locale (fallback document); a draft or canceled event is never returned even when a stale document row is planted directly; `q` of 1 character returns 422 `request.validation_failed`; results paginate with the standard envelope; ordering is deterministic across repeated identical requests.
- Contract: `q` parameter documented on the storefront events path.
- Unit (failing): `PostgresEventSearcher` builds `websearch_to_tsquery` with the locale regconfig, ranks with `ts_rank_cd`, tie-breaks on `event_starts_at` then `id`, and joins the published-visibility scope.
- Architecture: controllers depend on the `EventSearcher` interface, never the Postgres class; a container-swapped fake searcher passes the feature path, proving the Meilisearch seam.

Slice 7: rebuild command

- Feature (first, failing): after planting published, draft, and canceled events and running the projector, truncating `event_search_documents` and running `php artisan search:rebuild` reproduces row-for-row identical documents (the equivalence test that justifies the documented deviation from the system-design 9.1 outbox-replay mechanism, see Domain events); the command is tenant-iterating and runs under the RLS regime rather than bypassing it.

## Task breakdown

Ordered; each is a small independently mergeable unit unless noted.

1. `feat(support)`: medialibrary installed, custom Media model, adjusted `media` migration with RLS policy, tenant path generator, disk config. Includes slice 1 tests. Blocks tasks 2 through 5.
2. `feat(catalog)`: event `cover` and `gallery` collections, `POST /v1/events/{event}/media`, `GET /v1/events/{event}/media`, `DELETE /v1/media/{media}`, `MediaData` and upload Data objects, OpenAPI, policies, activity log entries, generated TypeScript.
3. `feat(catalog)`: queued `thumb`, `card`, `hero` conversions and conversion URLs in `MediaData`.
4. `feat(catalog)`: storefront event Data objects gain `cover_image` and `gallery`; OpenAPI and TypeScript regenerated.
5. `feat(tenancy)`: tenant `logo` collection, `POST /v1/tenants/{tenant}/media` gated on `tenants.manage`, logo URL populating `BrandingSettingsData.logo_url` on the admin tenant read shape.
6. `feat(catalog)`: `event_search_documents` migration with RLS and GIN index; document builder with locale fallback and regconfig mapping; the Tenancy read Action returning `supported_locales` and `default_locale` (small cross-context piece mirroring the Stage 5a settlement-currency Action, committed separately as `feat(tenancy)`). Blocks 7 through 9.
7. `feat(catalog)`: `RefreshSearchIndex` outbox consumer with subscription routing and duplicate-delivery, out-of-order, and lifecycle tests.
8. `feat(catalog)`: `EventSearcher` interface, `PostgresEventSearcher`, container binding with config-selected driver (`search.driver`, only `postgres` implemented).
9. `feat(catalog)`: `q` parameter on the storefront events list, relevance smoke tests, OpenAPI parameter, pagination.
10. `feat(catalog)`: `search:rebuild` artisan command with the equivalence test.
11. `docs`: mark Stage 5c in the master plan status table; error code registry entries (`payload_too_large` if not already present) recorded with the Stage 1 registry.

## Exit criteria

The master plan's Stage 5 exit line, restricted to this slice's surface and expanded into individually testable checks:

1. A tenant can attach a cover image and gallery images to an event entirely over the API, and a host-resolved storefront consumer sees their URLs only on published events (feature tests, slices 2 through 4).
2. A platform admin can upload a tenant branding logo and the admin tenant read surface (`GET /v1/tenants/{tenant}`) returns its URL in `BrandingSettingsData.logo_url` (slice 4); a storefront branding read endpoint does not exist in any stage yet and is not this stage's to add.
3. Cross-tenant reads and writes on `media` and `event_search_documents` provably fail under RLS, and every new endpoint has isolation coverage (Isolation suite).
4. Duplicate delivery of any catalog event to the search consumer produces exactly one document set; out-of-order delivery converges on current event state (consumer tests, slice 5).
5. Search relevance smoke tests pass: name matches outrank description matches, locale fallback finds untranslated events, ordering is deterministic (slice 6).
6. Draft and canceled events never appear in search results, even with a stale projection row planted (slice 6).
7. `search:rebuild` reproduces the projector-built index exactly (slice 7).
8. The storefront search path passes against a container-swapped fake `EventSearcher`, proving the Meilisearch upgrade seam (Architecture and feature tests, slice 6).
9. All endpoints have merged OpenAPI contracts and pass conformance; generated TypeScript is committed without drift; Feature, Unit, Contract, Architecture, and Isolation suites are green; Larastan and Pint clean (definition of done, master plan).

## Risks and open questions

- Package currency: medialibrary's compatibility with the Laravel version in `apps/api/composer.json` (currently `laravel/framework ^13.8`) and its current UUID-key guidance must be verified against current documentation at implementation time, not assumed; the adjusted-migration shape follows whatever the current published migration contains.
- Public versus signed URLs: event images and logos are public marketing content, but MinIO buckets default private. Decide between a public-read media bucket and time-limited signed URLs; signed URLs complicate CDN and cache behavior on the storefront. Recommendation: public-read bucket for these collections, revisit when Stage 8a adds ticket PDFs, which must be private. Decide before task 2 merges since it fixes the `url` semantics in the contract.
- Object storage isolation is by path prefix and URL secrecy only; RLS covers rows, not objects. Acceptable for public images, but Stage 8a must not reuse the public bucket for PDFs. Flagged here so the disk configuration keeps collections bucket-addressable.
- Branding authorization scope: gating logo upload on `tenants.manage` keeps branding a platform-admin concern, consistent with Stage 2 routing branding mutations through the platform-admin tenant PATCH. If tenant staff ever need self-service branding, that is a deliberate Capability enum extension (new entry plus the Stage 3 authorization-matrix test update), not a reinterpretation of `tenants.manage`.
- Stage 5a must record `EventUpdated` for every attendee-facing content change, including translation-only edits, or search documents go stale. If 5a's producer skips any such path, fixing it is in 5a's scope but blocks slice 5 acceptance.
- Search visibility of past events: this plan reapplies the Stage 5a published-visibility scope verbatim, so whether ended events are searchable follows whatever 5a decided for the plain list. If 5a has no rule, one must be added there, not forked here.
- Locale-to-regconfig coverage: unmapped locales degrade to the `simple` configuration, which drops stemming quality. Acceptable initially; a persistent relevance gap for a real tenant locale is a Meilisearch trigger, not cause for custom dictionaries.
- `websearch_to_tsquery` does no prefix matching, so partial words return nothing. Accepted for this stage; as-you-type search is a storefront feature that would pull the Meilisearch decision forward.
- Multi-currency and money: not touched by this stage; no `*_amount` columns are added.
