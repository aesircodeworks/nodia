# Execution Journal: Stage 5a, Catalog Core and Publish

Durable record of execution runs for [stage-05a-catalog-core.md](../stage-05a-catalog-core.md). Append a new run header per run; never rewrite prior entries.

## Run: 2026-07-10

- Stage: 5a, Catalog Core and Publish
- Date: 2026-07-10
- Branch: `feat/api-implementation`
- Base commit: `0cea08482e2b0d94ec793fbeab0f2f3952ceed66`

Verified starting state: Stages 1-4 are Done. Stage 5a is Not started. No `app/EventCatalog` directory, no `venues`, `events`, or `ticket_types` migrations, no settlement currency column or Tenancy read Action, no catalog routes or Data objects. `events.view`, `events.manage`, and `events.publish` exist in the Stage 3 `Capability` enum. `ResolveTenantFromHost` and `ResolveTenantFromHeader` middleware exist from Stage 2. The outbox recording API from Stage 4 is available for the catalog producers.

### Task checklist

- [x] task-01: EventCatalog context skeleton, service provider, capability Policies and Gates, authorization matrix extension (plan tasks 1 and 2)
- [ ] task-02: `venues` table, model, endpoints, contract (plan task 3, slice 1)
- [ ] task-03: `EventStatus` enum, `AsyncPaymentPolicyData`, `events` table with CHECK constraints, translatable model, isolation tests (plan task 4)
- [ ] task-04: Event admin endpoints with `EventCreated` and `EventUpdated` outbox recording, contract fragment (plan task 5, slice 2)
- [ ] task-05: Tenancy settlement currency column, design doc update, read Action (plan task 6)
- [ ] task-06: `ticket_types` table, model, factory, isolation tests (plan task 7)
- [ ] task-07: Ticket type endpoints and Actions with currency validation and `EventUpdated` recording, contract fragment (plan task 8, slice 3)
- [ ] task-08: Publish and cancel: concurrency tests first, conditional-UPDATE Actions, transition endpoints, contract fragment (plan task 9, slice 4)
- [ ] task-09: Storefront read surface: host-resolved routes, locale negotiation resolver, storefront Data objects, isolation coverage, contract fragment (plan task 10, slice 5)
- [ ] task-10: Regenerate TypeScript contract types, confirm zero drift, update master plan status row (plan task 11)

### Review rounds

### Decisions and deviations

#### task-01: EventCatalog context skeleton, service provider, capability Gate wiring, authorization matrix extension (2026-07-10)

Landed the `App\EventCatalog` bounded context skeleton (plan task breakdown items 1 and 2):

- `app/EventCatalog/EventCatalogServiceProvider.php`, registered in `bootstrap/providers.php`, mounting two route groups (`tenancy.admin` for the admin surface, `tenancy.storefront` for the storefront surface) against `app/EventCatalog/Http/routes/admin.php` and `.../storefront.php`, both currently empty (docblock-only) files that later tasks (3, 5, 8, 9 for admin; 10 for storefront) populate with real routes as each slice's controller and Data objects land.
- `tests/Architecture/ContextBoundariesTest.php` already listed `EventCatalog` in its `$contexts` array ahead of this stage (verified before starting: the check was vacuously true with no `App\EventCatalog` namespace yet); no change needed there, and it is no longer vacuous now that the namespace exists. `tests/Architecture/PresetTest.php` gained `EventCatalogServiceProvider::class` in its `ignoring()` list, mirroring `TenancyServiceProvider`/`IdentityServiceProvider`'s existing precedent (a context's own provider lives at the context root per system-design 3.2, not `App\Providers`).
- `tests/Feature/EventCatalog/CatalogAuthorizationMatrixTest.php`: a new data-driven matrix covering all 14 admin endpoints named in the stage plan's endpoint table (venues, events, publish/cancel, ticket types) against their required capability (`events.view`, `events.manage`, `events.publish`), asserting `auth.unauthenticated` (401), `missing_capability` (403), and success (204) for a bearer holding the capability. `tests/Support/TenantStaff.php` is a new test-support helper (mirrors `Tests\Support\PlatformStaff` but for a tenant-scope membership) minting a staff bearer holding a given capability in a given tenant, reusable by every later stage-05a task's own feature tests.

**Deviation, reasoned through before writing any code, not discovered by a failing test: the matrix test registers ad hoc probe routes at the real endpoint paths and methods, scoped to the test itself, rather than landing permanent app routes.** None of `Venue`, `Event`, or `TicketType` exist yet (task breakdown items 3, 4, 7 build them), so no real controller or Data object could back a permanently mounted route today. Registering bare, permanent stub routes at the final paths was the acceptance line's other named option ("land route stubs"), but `tests/Contract/RouteSpecDriftTest.php` asserts every `/v1` route registered anywhere in the app has a matching operation in `docs/openapi/openapi.yaml` with no exclusion list, which would force writing OpenAPI paths (and, per `tests/Contract/DocumentedResponseCoverageTest.php`, an exerciser for every documented response) for 14 endpoints ahead of the contract each later task's own TDD loop is specifically responsible for producing — directly against the master plan's double-loop sequencing (contract lands with its endpoint, not before). `RouteSpecDriftTest.php`'s own comment sanctions the alternative used here: "Probe routes are registered inside the tests that use them and never reach the app's route table," the exact mechanism `tests/Feature/Identity/AuthorizationMatrixTest.php` already established in Stage 3. The matrix test's `beforeEach` therefore registers one probe route per endpoint row under the real `tenancy.admin` group with the real method, path, and `RequireCapability` parameter, verified to run through the full real pipeline (`auth:staff`, `ResolveTenantFromHeader`, `EnforceMfaCompliance`, `RequireCapability`) exactly as a shipped route would. This means the 401/403/allowed assertions are true end-to-end proof today, not deferred; only the controller body and its contract are deferred.

**Decision, matching the stage-03 precedent for the identical situation: no standalone Policy class ships this task.** `App\Identity\Authorization\CapabilityGate` (Stage 3) is already registered globally via `Gate::before` and evaluates any ability whose name matches a `Capability` case, independent of which context's route invokes it; `App\Http\Middleware\RequireCapability` is the existing, shared consumption point every context's admin routes already use. A per-model Policy class (`VenuePolicy`, `EventPolicy`, `TicketTypePolicy`) has nothing to attach to before `Venue`, `Event`, and `TicketType` exist (task breakdown items 3, 4, 7), so writing one now would be speculative, mirroring stage-03 task-06's own recorded decision ("no standalone Policy class ships this task ... every concrete Policy attaches to a model whose endpoints do not exist until task breakdown items 7 through 9"). `EventCatalogServiceProvider`'s docblock records this reasoning inline so a later task revisiting the "Policies" directory has the context.

**No event type registrations added.** The plan's Domain events section confirms all four catalog events are already reserved in the system-design 9.3 registry, so no registry change is needed there; but `App\Support\Outbox\EventTypeRegistry::register()` calls are added by convention in the same task that ships an event's first producer (Stage 4 precedent: `TenantCreated`/`DomainVerified` registered in `TenancyServiceProvider::boot()` alongside `CreateTenant`/`RegisterDomain`). Since `CreateEvent`, `UpdateEvent`, `PublishEvent`, and `CancelEvent` do not exist until task breakdown items 5 and 9, `EventCatalogServiceProvider::boot()` registers no event types yet, matching the stage plan's own description of this task's provider as having "(empty for now) event subscriptions."

Test evidence: `composer -d apps/api run test` (all six suites: Feature, Unit, Contract, Architecture, Isolation, Concurrency) passed 1117 tests, 4356 assertions, 0 failures, including the new `CatalogAuthorizationMatrixTest.php` (42 tests: 14 endpoints x 3 scenarios) and the now-non-vacuous `ContextBoundariesTest.php`/`PresetTest.php` architecture checks. `composer -d apps/api run lint` (Pint) passed after two auto-fixes (import ordering in `PresetTest.php`, an unused import in the new test) with no manual changes needed beyond re-running Pint. `composer -d apps/api run analyse` (Larastan) passed with 0 errors. `composer -d apps/api run types:generate` ran with no diff (no new Data classes this task).

No deviation from the plan's task breakdown beyond the two reasoned choices above (probe routes over permanent stubs; no standalone Policy class).
