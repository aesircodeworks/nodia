# Stage 5a: Catalog Core and Publish

Implementation plan for the first Event Catalog slice defined in [api-implementation-plan.md](../api-implementation-plan.md). The TDD loop and contract pipeline from the master plan are binding for every slice below, as are [api-conventions.md](../api-conventions.md), [data-conventions.md](../data-conventions.md), and [event-conventions.md](../event-conventions.md). Design authority is [system-design.md](../system-design.md); section numbers cited throughout refer to it.

## Scope and non-goals

This stage delivers the EventCatalog bounded context (section 3.1, section 3.2) up to a publishable general-admission or virtual event:

- `venues` as tenant-scoped physical locations (section 8.2).
- `events` with translatable `name` and `description` (locale-keyed JSON via laravel-translatable, section 12), `timezone` stored as data (section 8), the draft/published/canceled lifecycle, virtual events with the exactly-one-of-venue-or-URL invariant (section 8.3 notes), and a per-event `async_payment_policy` (section 7.4, stored now, enforced by Payments in Stage 8a).
- `ticket_types` with `price_amount` plus `currency` money columns (section 8.2, ADR 018), `sales_start` and `sales_end` windows, `requires_seat` flag, and currency constrained to the tenant's settlement currency (section 12).
- `PublishEvent` and `CancelEvent` Actions as conditional UPDATEs checked by affected-row count, recording `EventPublished` and `EventCanceled` to the outbox in the producing transaction (section 9.1, section 9.3).
- `CreateEvent` and update Actions recording `EventCreated` and `EventUpdated`.
- Admin create, read, update, lifecycle, and list endpoints using spatie/laravel-query-builder with explicit allowlists (api-conventions, ADR 014); no delete endpoints ship in this stage (see risks).
- Storefront read endpoints resolved from the `Host` header (section 4.1), locale-negotiated (section 12), showing exactly the published surface: drafts and canceled events are invisible.
- `EventCatalogServiceProvider` registering routes, policies, and (empty for now) event subscriptions.

Non-goals, deferred explicitly:

- Seat maps and seats: Stage 5b. `requires_seat` ships on `ticket_types` now so the column is stable, but nothing references seats.
- `event_seats` materialization on publish and all inventory (`ticket_type_inventory`, holds, availability): Stage 6. Publishing a seated event is only complete once Stage 6 extends the publish Action; this stage publishes GA and virtual events fully.
- Media (event images through medialibrary) and search: Stage 5c. The storefront list in this stage is a simple indexed lookup over published events, not search.
- Categories: named in section 3.1 but absent from the section 8.2 data model; deferred until the design defines them (master plan open decisions).
- Sales-window enforcement at purchase time: Stages 6 and 7. This stage stores and returns the windows; nothing sells yet.
- Enforcement of `async_payment_policy`: Stage 8a. This stage only stores and validates the shape.

## Dependencies

Must exist from earlier stages:

- Stage 1: RFC 9457 problem handler with the stable `code` registry, `Support/Money` value object and laravel-data transformers for the `{amount, currency}` wire shape, all six test suites wired locally and in CI, the two-tenant isolation fixture, OpenAPI conformance assertions in the feature test base, time control for TTL-adjacent behavior.
- Stage 2: `tenants` and `tenant_domains`, the `SET LOCAL app.tenant_id` transaction wrapper, the per-table RLS policy pattern, tenant resolution middleware for both populations (`Host` for storefront routes, `X-Tenant-Id` for admin routes).
- Stage 3: staff authentication, memberships, capability-based Policies, and the `events.view`, `events.manage`, and `events.publish` capabilities, which Stage 3's initial registry already ships; this stage adds only the catalog Policies, Gates, and endpoint gating that consume them. Also the activity log for staff mutations.
- Stage 4: the outbox recording API callable inside a producing transaction, so every catalog Action records its event from day one.

Later stages consume from this one:

- Stage 5b attaches `seat_maps` to `venues`.
- Stage 5c subscribes the search index refresher to `EventCreated`, `EventUpdated`, `EventPublished`, `EventCanceled` and attaches media to events.
- Stage 6 creates `ticket_type_inventory` rows keyed on `ticket_types`, materializes `event_seats` inside `PublishEvent`, and reads `requires_seat`.
- Stage 7 references `events` and `ticket_types` from orders and reads sales windows and prices through EventCatalog Actions.
- Stage 8a reads `events.async_payment_policy` when offering payment methods.
- Stage 11 reporting projectors subscribe to the catalog events.

## Data model

All three tables: UUIDv7 `id` via `HasUuids`, non-null `tenant_id`, `created_at` and `updated_at` UTC timestamps, and a single-table RLS policy comparing `tenant_id` to `current_setting('app.tenant_id')` created in the same migration as the table (data-conventions, ADR 003). No auto-increment columns.

### venues

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid | PK, UUIDv7 |
| tenant_id | uuid | non-null, FK tenants |
| name | string | non-null |
| address | string | non-null |
| city | string | non-null |
| country | string | non-null, ISO 3166-1 alpha-2, validated in the request layer |
| capacity | integer | non-null, > 0 enforced by CHECK |

Indexes: FK index on `tenant_id` (Laravel default names throughout).

### events

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid | PK, UUIDv7 |
| tenant_id | uuid | non-null, FK tenants |
| venue_id | uuid | nullable, FK venues (section 8.3: virtual events have no venue) |
| status | string | enum-backed: `draft`, `published`, `canceled`; default `draft` |
| name | jsonb | translatable, locale-keyed (section 12, ADR 014) |
| description | jsonb | translatable, locale-keyed |
| start_at | timestamp | non-null, UTC |
| end_at | timestamp | non-null, UTC, CHECK `end_at > start_at` |
| timezone | string | non-null, IANA identifier, validated in the request layer (section 8: timezones are data, never encoded into stored timestamps) |
| is_virtual | boolean | non-null, default false |
| virtual_event_url | string | nullable |
| async_payment_policy | jsonb | non-null, validated shape (see below) |

Constraints:

- CHECK expressing the exactly-one-of invariant: `(is_virtual AND venue_id IS NULL AND virtual_event_url IS NOT NULL) OR (NOT is_virtual AND venue_id IS NOT NULL AND virtual_event_url IS NULL)`. Section 8.3 states this as an application invariant; the CHECK is the structural backstop and request validation produces the friendly error first. Consequence: drafts are venue-complete at creation, which `CreateEvent` requires up front.
- Indexes: `(tenant_id, status)` and `(tenant_id, start_at)` for admin and storefront listing; FK index on `venue_id`.

`async_payment_policy` is a laravel-data object cast (`AsyncPaymentPolicyData`) with the minimal shape Stage 8a needs: `slow_methods_enabled` (bool, default true) and `low_inventory_cutoff` (nullable int, the remaining-inventory threshold below which the platform disables slow methods automatically, section 7.4). Evolution of this shape is additive only.

### ticket_types

| Column | Type | Notes |
| --- | --- | --- |
| id | uuid | PK, UUIDv7 |
| tenant_id | uuid | non-null, FK tenants (denormalized per section 4.2) |
| event_id | uuid | non-null, FK events |
| name | string | non-null (section 8.2 models this untranslated; see open questions) |
| price_amount | integer | non-null, minor units, CHECK `price_amount >= 0` |
| currency | string | non-null, ISO 4217, paired with `price_amount` on the same row (ADR 018) |
| sales_start | timestamp | nullable, UTC |
| sales_end | timestamp | nullable, UTC, CHECK `sales_end > sales_start` when both set |
| requires_seat | boolean | non-null, default false |

Indexes: `(tenant_id, event_id)`; FK index on `event_id`.

Currency constraint: section 12 fixes currency as a property of the ticket type constrained to the tenant's settlement currency. EventCatalog validates it by calling a Tenancy Action returning the tenant's settlement currency (never by touching Tenancy tables, section 3.1 boundary rule). Stage 2 does not create that attribute (its tenant configuration covers branding, locales, and enabled gateways only, and the section 8.1 tenant model has no settlement currency column), so task 6 ships the additive Tenancy migration alongside the read Action.

`ticket_type_inventory` is explicitly not created here; it is Stage 6's first table and its absence means this stage exposes price and sales windows but no availability.

## Domain events

Produced (all four are already in the section 9.3 registry; no registry change needed):

| Event | Aggregate | Payload | Recorded by |
| --- | --- | --- | --- |
| EventCreated | event / event id | `event_id` | CreateEvent |
| EventUpdated | event / event id | `event_id` | UpdateEvent, CreateTicketType, UpdateTicketType (ticket type changes alter the event's sellable surface; the registry has no ticket-type event, so the parent event's update event is the fact recorded) |
| EventPublished | event / event id | `event_id`, `published_at` | PublishEvent |
| EventCanceled | event / event id | `event_id`, `canceled_at`, `prior_status` | CancelEvent |

Envelope per event-conventions: UUIDv7 event `id`, global `sequence`, `type`, non-null `tenant_id`, `aggregate_type` `event`, `aggregate_id`, `correlation_id` propagated from the request, `occurred_at`, laravel-data payload with snake_case keys carrying identifiers and facts, not snapshots. Every event is recorded in the same database transaction as the state change, without exception; for `EventPublished` and `EventCanceled` that means the same transaction as the conditional UPDATE, and the event is recorded only when the affected-row count is 1.

Consumed: nothing in this stage. The first consumers are Stage 5c (search index) and Stage 11 (reporting); both will be idempotent by event ID per event-conventions. This stage's obligation is producer-side only: correct envelopes, correct transaction boundaries, at most one `EventPublished` per publish (proven by the concurrency suite).

Venue mutations record no domain event; the registry has none for venues (see risks).

## Endpoints

All routes under `/v1`, snake_case JSON wire format, laravel-data objects as the single source of truth with TypeScript regenerated via `composer types:generate`, and every endpoint's OpenAPI path merged in `docs/openapi/openapi.yaml` before it ships. Errors are RFC 9457 problem documents; codes below are the stable contract additions, alongside the Stage 1 registry codes (`request.validation_failed`, `request.not_found`, `auth.unauthenticated`, `auth.forbidden`, and tenant resolution codes from Stage 2).

Admin surface (staff bearer token, `X-Tenant-Id` validated against memberships, capability-gated, mutations activity-logged):

| Method and path | Capability | Request Data | Response Data | Errors |
| --- | --- | --- | --- | --- |
| POST /v1/venues | events.manage | CreateVenueData | VenueData (201) | request.validation_failed |
| GET /v1/venues | events.view | query-builder: `filter[name]`, `filter[city]`, `sort` in `name,-name,created_at,-created_at`, page pagination | paginated VenueData | request.validation_failed on unknown filter/sort (rejected, not ignored) |
| GET /v1/venues/{venue} | events.view | | VenueData | request.not_found |
| PATCH /v1/venues/{venue} | events.manage | UpdateVenueData | VenueData | request.validation_failed, request.not_found |
| POST /v1/events | events.manage | CreateEventData | EventData (201) | request.validation_failed |
| GET /v1/events | events.view | query-builder: `filter[status]`, `filter[venue_id]`, `filter[is_virtual]`, `sort` in `start_at,-start_at,created_at,-created_at`, `include=venue,ticket_types`, page pagination | paginated EventData | request.validation_failed |
| GET /v1/events/{event} | events.view | `include=venue,ticket_types` | EventData | request.not_found |
| PATCH /v1/events/{event} | events.manage | UpdateEventData | EventData | request.validation_failed, request.not_found, catalog.event_immutable (409, event is canceled) |
| POST /v1/events/{event}/publish | events.publish | none | EventData | request.not_found, catalog.event_not_publishable (409, status was not draft) |
| POST /v1/events/{event}/cancel | events.publish | none | EventData | request.not_found, catalog.event_not_cancelable (409, already canceled) |
| POST /v1/events/{event}/ticket-types | events.manage | CreateTicketTypeData | TicketTypeData (201) | request.validation_failed, catalog.currency_mismatch (422), catalog.event_immutable (409) |
| GET /v1/events/{event}/ticket-types | events.view | page pagination | paginated TicketTypeData | request.not_found |
| GET /v1/ticket-types/{ticket_type} | events.view | | TicketTypeData | request.not_found |
| PATCH /v1/ticket-types/{ticket_type} | events.manage | UpdateTicketTypeData | TicketTypeData | request.validation_failed, catalog.currency_mismatch, catalog.event_immutable |

Ticket types nest one level under events for creation and listing, then get their own top-level resource for detail and update, per the api-conventions nesting rule. Route parameters are UUIDv7 strings.

Admin Data shapes: `EventData` carries `name` and `description` as full locale-keyed maps (the editing surface), `status`, timestamps as ISO 8601 UTC strings, `timezone` as the IANA string, and money nowhere (events have no price). `TicketTypeData` carries `price` as the `{amount, currency}` object via the Stage 1 Money transformer; `price_amount` never appears bare on the wire.

Storefront surface (no auth, tenant resolved from `Host` against `tenant_domains`, purpose-built parameters, no query-builder passthrough):

| Method and path | Parameters | Response Data | Errors |
| --- | --- | --- | --- |
| GET /v1/storefront/events | `locale` (optional), page pagination, ordered by `start_at` ascending | paginated StorefrontEventData | tenant resolution failure codes from Stage 2 |
| GET /v1/storefront/events/{event} | `locale` (optional) | StorefrontEventData with ticket types | request.not_found (draft and canceled events return 404 indistinguishably from nonexistent ones) |

Locale negotiation per section 12: explicit `locale` query parameter first, then `Accept-Language`, falling back to the tenant default; only tenant-supported locales are honored. Section 12 also places customer preference in the chain; that step is deliberately out of scope here because these storefront reads carry no customer identity, and Stage 7 adds it to the resolver when customer-facing order endpoints arrive. The response resolves `name` and `description` to single strings in the negotiated locale (falling back per laravel-translatable to the tenant default when a translation is missing), includes the resolved `locale`, and sets `Content-Language`. `StorefrontEventData` embeds `StorefrontTicketTypeData` (name, `price` as `{amount, currency}`, sales window); no availability field exists until Stage 6. Raw internal state (status, async_payment_policy) is not exposed on the storefront shape.

The `/v1/storefront` prefix is the routing seam between the two tenant-resolution modes (Host versus `X-Tenant-Id`, section 4.1); it is recorded in the OpenAPI document as the convention for all future storefront reads.

## TDD sequencing

Ordered slices, each a full loop: outside feature test first, contract second, unit tests third, then green, refactor, `composer types:generate`, commit scoped `catalog`. Isolation tests for a new table are written before the migration exists and fail until the table and its policy land.

Slice 1: venues.

- Isolation (first): two-tenant fixture proves cross-tenant SELECT, UPDATE, and DELETE against `venues` return nothing and affect no rows under RLS.
- Feature: POST creates and returns 201 with the wire shape; GET list honors allowed filters and sorts and rejects unknown ones with `request.validation_failed`; GET detail 404s across tenants; PATCH updates; unauthenticated 401 and missing-capability 403 problem documents; mutations appear in the activity log.
- Contract: all four venue paths in openapi.yaml, conformance asserted on every feature test response.
- Unit: CreateVenue and UpdateVenue Actions, country and capacity validation rules.

Slice 2: events, draft lifecycle only.

- Isolation (first): same probes against `events`.
- Feature: create a GA event with venue, create a virtual event with URL; each invalid combination of `is_virtual`, `venue_id`, `virtual_event_url` returns `request.validation_failed` with the offending fields in the errors map; translatable payloads require the tenant default locale and reject unsupported locales; invalid timezone and `end_at <= start_at` rejected; admin list filters, sorts, includes; PATCH records; PATCH on canceled event returns `catalog.event_immutable`.
- Feature (outbox): creating an event records exactly one `EventCreated` row with correct envelope fields including propagated correlation ID; updating records `EventUpdated`; a failing request records nothing (rollback with the producing transaction, mechanism proven in Stage 4, asserted here for the catalog producer).
- Contract: event paths and the EventData schema.
- Unit: CreateEvent and UpdateEvent Actions, the exactly-one-of invariant, AsyncPaymentPolicyData validation and defaults.

Slice 3: ticket types.

- Isolation (first): probes against `ticket_types`.
- Feature: create under an event with `{amount, currency}` money on the wire both ways; a currency differing from the tenant settlement currency returns `catalog.currency_mismatch`; `sales_end` before `sales_start` rejected; negative price rejected; nested list and top-level detail and PATCH; mutations on a canceled event's ticket types return `catalog.event_immutable`; ticket type mutations record `EventUpdated` for the parent event.
- Contract: ticket type paths, TicketTypeData with the Money schema.
- Unit: CreateTicketType and UpdateTicketType Actions, the Tenancy settlement-currency Action call at the boundary.

Slice 4: publish and cancel.

- Concurrency (first): N parallel publish attempts against one draft event on real PostgreSQL yield exactly one success (affected-row count 1) and exactly one `EventPublished` outbox row; the losers receive `catalog.event_not_publishable`. N parallel cancel attempts likewise yield exactly one success and exactly one `EventCanceled` row. A parallel publish-versus-cancel race is asserted per interleaving, because cancel legally succeeds from published: if cancel commits first, publish fails with no `EventPublished` recorded; if publish commits first, the blocked cancel re-evaluates its guard under READ COMMITTED and may also succeed, yielding `EventPublished` then `EventCanceled` and a canceled terminal state. The invariant asserted across both interleavings is that recorded outbox events exactly match the committed row-count-1 transitions: no event without its transition, no lost update.
- Feature: publish a draft returns the published EventData; publish again returns 409 `catalog.event_not_publishable`; publish a canceled event 409; cancel from draft and from published succeed and record `EventCanceled` with `prior_status`; cancel a canceled event returns `catalog.event_not_cancelable`; publish and cancel are audited.
- Unit: PublishEvent and CancelEvent Actions issue a single conditional UPDATE guarded on current status and branch on affected-row count, never read-then-write; event recording happens inside the same transaction and only on row count 1.
- Contract: the two transition paths.

Slice 5: storefront read surface.

- Isolation (first): with two tenants each owning a domain and a published event, requests to tenant A's host never return tenant B's events on list or detail, enforced by RLS under the host-resolved tenant context.
- Feature: list returns only published events of the resolved host's tenant ordered by `start_at`; drafts and canceled events absent from the list and 404 on detail; unknown host handled per the Stage 2 resolution contract; locale negotiation matrix (explicit param beats Accept-Language beats tenant default; unsupported locale falls back; missing translation falls back to tenant default) with `Content-Language` asserted; detail embeds ticket types with money shape; no status or policy fields leak.
- Contract: storefront paths, StorefrontEventData and StorefrontTicketTypeData schemas.
- Unit: the locale negotiation resolver.

## Task breakdown

Ordered; each lands green across all suites and is independently mergeable unless noted.

1. Capability wiring: `events.view`, `events.manage`, and `events.publish` already exist in the Stage 3 registry and its template roles; this task adds the catalog Policies and Gates that consume them and extends the authorization matrix test to cover them against this stage's endpoints.
2. `EventCatalogServiceProvider` skeleton, context directories, route group registration; architecture suite recognizes the new context.
3. `venues` migration with RLS policy, model, factory, isolation tests (slice 1 red-to-green through the endpoints and contract).
4. `EventStatus` enum, `AsyncPaymentPolicyData`, `events` migration with RLS policy and CHECK constraints, model with translatable casts, factory, isolation tests.
5. Event admin endpoints: CreateEvent and UpdateEvent Actions with `EventCreated` and `EventUpdated` recording, Data objects, query-builder list, contract fragment (slice 2 complete).
6. Tenancy settlement currency: additive `tenants` migration adding the settlement currency column (Stage 2 verifiably does not create one) with the matching section 8.1 design document update, plus the read Action exposing it (in the Tenancy context; small cross-context task, coordinated with whoever owns Tenancy, mergeable alone, must land before slice 3).
7. `ticket_types` migration with RLS policy, model, factory, isolation tests.
8. Ticket type endpoints and Actions with currency validation and `EventUpdated` recording, contract fragment (slice 3 complete).
9. Concurrency tests for publish and cancel (red), then PublishEvent and CancelEvent Actions as conditional UPDATEs with event recording, transition endpoints, contract fragment (slice 4 complete; single task because the failing concurrency test and the implementation are one loop).
10. Storefront routes under the Host-resolved middleware group, locale negotiation resolver, storefront Data objects, list and detail endpoints, isolation coverage, contract fragment (slice 5 complete).
11. Regenerate TypeScript contract types, confirm zero drift in CI, update the master plan status table row for Stage 5a.

## Exit criteria

The master plan's Stage 5 exit line, restricted to this slice and expanded into testable checks:

1. A staff user with `events.manage` can create a venue, a GA event, and a ticket type entirely over `/v1`, and publish it with `events.publish`; the full sequence passes as a single feature test.
2. The same sequence with a virtual event (URL, no venue) passes; every invalid venue/virtual combination is rejected with a problem document.
3. A host-resolved storefront request lists and shows exactly the published events of exactly the resolved tenant; drafts and canceled events are invisible on every storefront path (list, detail), asserted per path.
4. Locale negotiation returns translated content per the section 12 precedence with tenant-default fallback, asserted by the negotiation matrix test.
5. Publish and cancel are conditional UPDATEs: the concurrency suite proves one winner and exactly one outbox event under parallel publish, and Larastan-visible code review confirms no read-then-write on the transition path.
6. All four catalog events appear in the outbox with correct envelopes, correlation IDs, and transaction boundaries.
7. Isolation suite covers `venues`, `events`, and `ticket_types` plus the storefront host-resolution paths; every new table shipped its RLS policy in its creating migration.
8. Every endpoint above exists in `docs/openapi/openapi.yaml` and every feature test response passes conformance; generated TypeScript is committed with the drift gate green.
9. Money appears on the wire only as `{amount, currency}`; the contract schemas encode this and a feature test asserts it on ticket type responses.
10. All mutating admin endpoints are capability-gated and activity-logged; the authorization matrix covers `events.view`, `events.manage`, and `events.publish` against this stage's endpoints.
11. `composer lint`, `composer analyse`, and all six suites green in CI.

Not part of this stage's exit (owned later): availability on the storefront (Stage 6), seated publishing (Stages 5b and 6), search and media (5c).

## Risks and open questions

- Settlement currency source: section 12 constrains ticket type currency to the tenant's settlement currency, but the section 8.1 tenant model has no settlement currency column (only `enabled_gateways` and `payout_schedule`) and Stage 2 creates tenants without one. Task 6 therefore owns the additive Tenancy migration and the section 8.1 design update as explicit deliverables, not contingencies; the residual open question is only the column's exact shape (a single ISO 4217 code is assumed here), to be confirmed before slice 3 starts.
- Storefront route prefix: `/v1/storefront/...` is proposed here as the seam between Host-resolved and header-resolved routing; api-conventions does not currently name it. Record the choice in the OpenAPI document and, if accepted, a one-line addition to api-conventions.
- Slugs: roadmap Phase 2 mentions a storefront event page resolved by "event slug", but the section 8.2 model has no slug and api-conventions mandates UUIDv7 route parameters. This plan ships UUID-addressed storefront endpoints; if human-readable URLs are required, slug becomes an additive column and a design update, not a route parameter convention change decided here.
- Ticket type name translatability: section 8.2 models `ticket_types.name` as a plain string while event content is translatable. Attendee-facing consistency may eventually want it translatable; changing later is an additive migration plus a wire change on storefront shapes, so deferring is cheap but worth a design decision.
- `async_payment_policy` shape is owned in spirit by Payments but stored by Catalog; the minimal shape here (`slow_methods_enabled`, `low_inventory_cutoff`) must be reviewed by whoever plans Stage 8a before this stage merges, since evolution is additive only.
- Venue mutations record no domain event because the section 9.3 registry has none; when Stage 5c's search index denormalizes venue fields into event documents, a venue rename will not propagate. Either 5c reindexes on a schedule or the registry gains a venue event then; flagged for the 5c plan.
- Deletion story: no DELETE endpoints exist, so a mistakenly created venue or a ticket type on a draft event cannot be removed and events can only be canceled. The design mandates no deletes, but whether venues and ticket types get delete or archive endpoints (at minimum for ticket types before first sale, while removal is still safe) is an open decision for a later stage.
- Cancel semantics: this plan allows cancel from draft as well as published and records `EventCanceled` with `prior_status` so consumers can ignore draft cancellations. If product semantics later distinguish "discarded draft" from "canceled announced event", that is a new event type, not a payload mutation.
- The CHECK constraint makes drafts venue-complete at creation. If the admin UX later wants incomplete drafts, relaxing a CHECK is an additive migration but the publish Action must then own the invariant; the conservative strict-at-create choice is deliberate.
- Editing published events is allowed (name fixes, description updates) and records `EventUpdated`; no field-level immutability is enforced after publish in this stage. Whether `start_at`, `timezone`, or currency-bearing children should lock after first sale is a Stage 6/7 question once sales exist.
