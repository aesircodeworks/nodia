# Stage 8d Execution Journal: Real Gateway Adapter

## Run: 2026-07-12

- Stage: 8d (docs/plans/stage-08d-real-gateway.md)
- Branch: feat/api-implementation
- Base commit: fe5f95cea3ec131282b1f4fafeeacc1a82b8044a

The launch gateway ADR does not exist yet (docs/decisions ends at 019), so only the gateway-agnostic slices 1 through 4 and the drift detector (plan tasks 1 through 5) are in scope for this run. Plan tasks 6 through 15 remain pending the ADR.

### Checklist

- [x] 8d-1: Adapter conformance suite extracted from FakeGateway tests (slice 1)
- [x] 8d-2: Recorded-fixture harness: loader, no-network guard, secret sanitizer guard, record command inert in CI (slice 2)
- [x] 8d-3: PendingGatewayAdapter and skeleton verifier, offer exclusion, gateway_not_configured problem code (slice 3)
- [x] 8d-4: KYC abstraction hardening: data-driven status mapping, needs_review quarantine, conditional-UPDATE transitions (slice 4)
- [x] 8d-5: Sandbox drift detector and operational doc for recording fixtures and rotating webhook secrets (slice 8 detector, task 5)

### Task 8d-1: adapter conformance suite (2026-07-12)

Extracted a shared, adapter-parameterized `GatewayAdapter` conformance
suite from `tests/Unit/Payments/FakeGatewayTest.php` (stage-08d plan,
Slice 1):

- `tests/Support/Payments/GatewayAdapterConformanceContext.php`: the
  per-adapter wiring the suite needs (a fresh-adapter factory, a payment
  request builder, a closure that arranges the given adapter instance to
  decline a refund for an unknown reference, a signed-webhook builder,
  and a signature-tamper closure).
- `tests/Support/Payments/conformance.php`: `gatewayAdapterConformanceSuite()`,
  asserting `createPayment` returns a `GatewayPaymentResult` whose
  `gatewayReference` is deterministic on the idempotency key (the
  payment id, per `GatewayPaymentRequest`'s docblock); `refund` on an
  unknown reference returns a declined `GatewayRefundResult`, never an
  exception; `parseWebhook` on a tampered signature throws
  `WebhookSignatureInvalidException`; capability flags are internally
  consistent (`asyncConfirmation` true if and only if some method is
  async).
- `tests/Unit/Payments/GatewayAdapterConformanceTest.php`: registers the
  suite against `FakeGateway`, unchanged, proving extraction rather than
  invention.
- `tests/Unit/Payments/GatewayRegistryTest.php`: unit tests for
  `GatewayRegistry::resolve()`, a new method throwing the existing
  typed `GatewayUnknownException` for an unbound slug (`get()` stays as
  the nullable lookup every current call site already wraps with its
  own `?? throw`; `resolve()` centralizes that pattern for future
  callers, including the conformance suite's own use if needed).
- `app/Payments/Gateways/FakeGateway.php`: added a `scenarios(): FakeGatewayScenarios`
  accessor so the conformance harness can script a decline against the
  same adapter instance it holds; not part of the `GatewayAdapter`
  interface, so it has no bearing on the conformance contract itself.

Deviation: the plan's phrasing "refund on an unknown reference returns
a typed failure" is tested via the context's
`unknownReferenceRefundRequest` closure arranging the decline on the
adapter instance under test, because `FakeGateway` is stateless and
carries no reference ledger to consult; each future adapter supplies
its own arrangement (a real gateway can likely just pass a
well-formed but nonexistent reference). The suite asserts the contract
shape (a declined `GatewayRefundResult`, never an exception), not how
each adapter recognizes "unknown."

Test evidence (apps/api):
- `php artisan test --filter='GatewayAdapterConformance|GatewayRegistryTest|FakeGatewayTest'`: 35 passed, 105 assertions.
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions (no interface or namespace boundary violation).
- `php artisan test tests/Unit/Payments`: 146 passed, 353 assertions (no regression in the Payments unit suite).
- `./vendor/bin/pint --test` (touched files): clean after one auto-fix (import ordering in `conformance.php`).
- `./vendor/bin/phpstan analyse` (touched files, `--memory-limit=1G`): 0 errors.

No Data class changed; `composer types:generate` not run.

Commit: a05dae2

### Task 8d-2: recorded-fixture harness with sanitizer and CI guards (2026-07-12)

Built the gateway-agnostic recorded-fixture harness (stage-08d plan,
Slice 2), all under `app/Payments/Support/Fixtures/` (a new bounded-context
Support namespace, following the convention already used for
`CircuitBreaker` and `OfferAssembler`):

- `GatewayFixtureLoader`: reads recorded exchanges from
  `tests/Fixtures/gateways/{slug}/*.json` (each a `{request: {method,
  url, body}, response: {status, body, headers}}` pair) and fakes the
  HTTP client with them. A request not covered by the fixture set, or a
  slug with no fixture directory at all, throws the new
  `GatewayFixtureNotCoveredException` instead of the fake falling
  through to a live network call.
- `GatewayFixtureSanitizer`: `scan()` walks a fixture directory and
  flags known-format credentials (`sk_live_`/`sk_test_`/`pk_live_`/
  `rk_live_`/AWS `AKIA...` shapes), bearer tokens, and PAN-like 13-19
  digit runs; `redact()` applies the same patterns to an in-memory
  exchange before it is written, so a freshly recorded fixture passes
  the guard on the first write.
- `GatewayFixtureRecorder` interface: what a per-gateway live-sandbox
  recorder implements; none are bound yet (`config('payments.fixture_recorders')`
  is empty until the launch gateway ADR lands and a real adapter exists
  to record against).
- `php artisan gateway:record-fixtures {slug} {scenario}`
  (`RecordGatewayFixturesCommand`): refuses to run when
  `config('payments.ci')` (backed by `env('CI')`) is set, refuses when no
  recorder is bound for the slug, otherwise records, sanitizes, and
  writes fixtures under `tests/Fixtures/gateways/{slug}/`. Recording
  stays a manual, local act; CI only ever replays.
- `tests/Fixtures/gateways/examplegw/create-payment.json`: a
  hand-written, already-sanitized example fixture proving the loader and
  the permanent sanitizer guard against a real (non-empty) fixture
  directory, since no real gateway fixtures exist pending the ADR.

Deviation: the plan's "record mode fails immediately when CI env var is
set" is implemented via `config('payments.ci')` (sourced from
`env('CI')`) rather than reading `getenv('CI')` directly in the command,
matching this codebase's existing convention of routing all environment
reads through `config/payments.php` (see `gateway_retry_after_seconds`,
`circuit_breaker.*` in the same file) and making the guard trivially
overridable in tests via `config(['payments.ci' => true])`.

Test evidence (apps/api):
- `php artisan test --filter='GatewayFixtureLoaderTest|GatewayFixtureSanitizerTest|RecordGatewayFixturesCommandTest'`: 10 passed, 13 assertions.
- `php artisan test tests/Unit/Payments tests/Feature/Payments`: 318 passed, 1306 assertions (no regression).
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions, after adding `GatewayFixtureNotCoveredException` to the preset test's explicit list of bounded-context Throwables (same pattern as every other `Exceptions` class in that list).
- `./vendor/bin/pint --test` (touched files): clean.
- `./vendor/bin/phpstan analyse` (touched files, `--memory-limit=1G`): 0 errors.

No Data class changed; `composer types:generate` not run.

Commit: 8ca0c25

### Task 8d-3: PendingGatewayAdapter and skeleton verifier (2026-07-12)

Built the skeleton every registered-but-not-yet-implemented gateway
resolves to (stage-08d plan, Slice 3):

- `app/Payments/Gateways/PendingGatewayAdapter.php`: implements
  `GatewayAdapter`; `capabilities()` declares empty `methods` and
  `currencies` (asyncConfirmation and splitSupport both false), so
  `OfferAssembler` never contributes a method to any checkout offer
  (it skips a gateway whose capabilities do not support the order
  currency, before even reaching the empty method list). Every
  operation except `parseWebhook` throws the new
  `GatewayNotConfiguredException`. `parseWebhook` is the one exception:
  it always throws `WebhookSignatureInvalidException`, matching the
  plan's "the skeleton verifier rejects everything" so ingestion for
  its slug fails closed before persistence with `webhook_signature_invalid`,
  never with `gateway_not_configured`.
- `app/Payments/Exceptions/GatewayNotConfiguredException.php`: new
  `HasErrorCode` exception, code `gateway_not_configured`, mapped to
  409 (a permanent configuration gap, not the 503 transient-transport
  meaning of `gateway_unavailable`; no Retry-After).
- `app/Support/Problems/ErrorCode.php`: added `GatewayNotConfigured`.
- `config/payments.php`: `gateways.pending.enabled`, sourced from
  `PAYMENTS_PENDING_GATEWAY_ENABLED` (default false); `PaymentsServiceProvider`
  registers `PendingGatewayAdapter` under slug `pending` only when the
  flag is on, so a fresh environment never surfaces a gateway that can
  do nothing.
- `app/Payments/Actions/InitiatePayment.php`: `offeredMethod()` gained
  a third fallback pass after the existing offer and
  submerchant-inactive passes: if the requested method matches no
  offer entry at all, and the tenant has an enabled gateway resolving
  to an adapter whose capabilities support nothing (empty methods and
  currencies), initiation throws `GatewayNotConfiguredException` naming
  that gateway instead of the generic `PaymentMethodNotAvailableException`.
  This is the concrete shape "initiating a payment that somehow names
  it" takes: the client never names a gateway directly (only a
  `method` string), so the defensive check surfaces the more specific,
  actionable cause (the tenant's configured gateway is a stub) instead
  of a generic 422 when that stub is the reason nothing was offered.
- `docs/openapi/openapi.yaml`: `PaymentInitiationConflictProblem`
  (409) gained `gateway_not_configured` alongside the existing
  `order_not_payable`, `idempotency_key_reuse_mismatch`,
  `submerchant_not_active` codes; the `initiatePayment` 409 response
  description updated to match.
- `tests/Architecture/PresetTest.php`: added `GatewayNotConfiguredException`
  to the Payments context's explicit Throwables list (same pattern as
  every other exception in that list).

No Data class changed; `composer types:generate` not run.

Deviation worth flagging: reproducing the "initiating a payment that
somehow names it" scenario from the plan required understanding that
`GatewayRegistry` (bound `scoped`) behaves as a singleton for the
duration of a test's app lifecycle outside Octane (`scoped()` only
resets via Octane's per-request `forgetScopedInstances()`, which
nothing calls in plain `php artisan test`). Feature tests that flip
`payments.gateways.pending.enabled` at runtime must call
`app()->forgetScopedInstances()` immediately after, or the registry
resolved earlier in the same test process keeps its stale adapter set.
This is a test-authoring note, not a production behavior change (a
real request boots a fresh registry per Octane worker cycle).

Test evidence (apps/api):
- `php artisan test --filter='PendingGatewayAdapterTest|PendingGatewayWebhookIngestionTest|PendingGatewayOfferAndInitiationTest'`: 7 passed, 36 assertions.
- `php artisan test tests/Unit/Payments tests/Feature/Payments`: 325 passed, 1309 assertions (no regression).
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `./vendor/bin/pint --test` (touched files): clean.
- `./vendor/bin/phpstan analyse` (touched files, `--memory-limit=1G`): 0 errors.

Commit: 2396500

### Task 8d-4: KYC abstraction hardening with needs_review quarantine (2026-07-12)

Hardened the Stage 8c sub-merchant onboarding port so status
normalization is data-driven per adapter and an unrecognized gateway
status quarantines instead of throwing or being silently dropped
(stage-08d plan, Slice 4):

- `app/Payments/Enums/SubmerchantStatus.php`: added `NeedsReview =
  'needs_review'`, a platform-normalized state, not a gateway one.
- `app/Payments/Gateways/SubmerchantStatusMap.php`: new value object
  wrapping `array<string, SubmerchantStatus>`; `resolve()` returns
  `NeedsReview` for any raw status absent from the map;
  `identity()` builds the map whose raw vocabulary is the enum's own
  values, FakeGateway's default. Each adapter owns its own map
  instance, so a second adapter with a disjoint vocabulary plugs in by
  constructing a different map, never by touching this class or its
  caller.
- `app/Payments/Gateways/FakeGateway.php`: constructor gained an
  optional `SubmerchantStatusMap` (defaults to `identity()`);
  `normalizeSubmerchantWebhook()` now resolves the raw `status` string
  through the map instead of `SubmerchantStatus::tryFrom()`, so an
  unmapped raw status quarantines to `needs_review` rather than the
  event being dropped (`tryFrom` returning null previously discarded it
  entirely, which the plan explicitly rules out). Added
  `submerchantStatusWebhookRaw()` so tests can script an adapter's raw
  wire vocabulary (including strings a real gateway would send but the
  map has no entry for) through the same webhook path a real payload
  takes; `submerchantStatusWebhook()` (the SubmerchantStatus-typed
  helper already used by every 8c test) now delegates to it.
- `app/Payments/Actions/TransitionSubmerchantAccount.php`: extended the
  `SOURCES` transition matrix so `needs_review` is reachable from the
  same non-terminal sources as `action_required` (pending, under_review,
  action_required), and forward-transitions out of `needs_review` the
  same way `action_required` does (to under_review, action_required,
  active, rejected). `needs_review` is never reachable from `active`,
  `rejected`, or `disabled`, so a stale or malformed webhook can never
  regress a completed or terminal onboarding into review; this is
  enforced by the same conditional-UPDATE-by-affected-row-count
  mechanism as every other transition, not a special case.
- `docs/openapi/openapi.yaml`: `needs_review` added to the
  `SubmerchantAccountData.status` enum.
- `composer types:generate`: regenerated (`SubmerchantStatus` enum
  changed); `packages/api-client/src/generated/index.ts` and
  `typescript-transformer-manifest.json` committed.

Tests (failing first, per the double loop):
- `tests/Unit/Payments/SubmerchantStatusNormalizationTest.php`: a
  second adapter identity (`altgw`), built as a `FakeGateway` instance
  configured with an entirely disjoint raw vocabulary map
  (`verified`/`in_review`/`action_needed`/`declined`/`new_application`
  instead of the enum's own values), exercised through
  `StartSubmerchantOnboarding`, `IngestGatewayWebhook` (the webhook
  path), `RefreshSubmerchantStatus`, and the `SubmerchantAccount`
  model: a recognized raw status maps to the correct normalized enum
  value; a raw status absent from the map quarantines to
  `needs_review`; the same raw string (`verified`) resolves differently
  across the alt map and the identity map, proving the mapping is
  per-adapter data, not a shared hardcoded switch.
- `tests/Unit/Payments/TransitionSubmerchantAccountTest.php`: extended
  the existing full-matrix legal/illegal transition table with every
  `needs_review` source and target pair, including the illegal cases
  proving `active`, `rejected`, and `disabled` can never regress into
  `needs_review`.
- `tests/Feature/Payments/SubmerchantOnboardingTest.php`: a webhook
  carrying a raw status no adapter map recognizes lands the account on
  `needs_review` on the wire (`GET /v1/submerchant-accounts/{id}`), and
  the raw status string never appears anywhere in the response body
  (asserted with `assertConformsToOpenApi()` plus a literal
  not-contains check on the raw string).
- `tests/Concurrency/SubmerchantAccountTransitionContentionTest.php`:
  new case racing a stale duplicate webhook (an earlier `under_review`
  status arriving late) against a refresh confirming an
  already-`active` account; because `active` is never a listed source
  for `under_review`, the conditional UPDATE's affected-row-count guard
  lets the stale contender affect zero rows regardless of which
  transaction's `WHERE status = ...` predicate is evaluated first, so
  the account is `active` after both contenders finish under
  `ParallelRunner`, proving the "stale webhook cannot regress a
  completed onboarding" invariant under real concurrent Postgres access,
  not just deterministically in a single-threaded unit test.

No new tenant-scoped table (needs_review is an enum member on the
existing `submerchant_accounts.status` column, backed by the same
merged migration); no new isolation test required.

Test evidence (apps/api):
- `php artisan test --filter='SubmerchantStatusNormalizationTest|TransitionSubmerchantAccountTest|SubmerchantOnboardingTest|SubmerchantAccountTransitionContentionTest'`: 87 passed, 251 assertions.
- `php artisan test tests/Unit/Payments tests/Feature/Payments`: 342 passed, 1355 assertions (no regression from the 325/1309 baseline recorded after task 8d-3).
- `php artisan test --testsuite=Isolation`: 270 passed, 539 assertions.
- `php artisan test --testsuite=Concurrency`: 44 passed, 192 assertions.
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `./vendor/bin/pint --test` (touched files, after one pint-applied
  fix to the feature test's import ordering): clean.
- `./vendor/bin/phpstan analyse` (touched files, `--memory-limit=1G`):
  0 errors.

No deviation from the plan.

### Task 8d-5: sandbox drift detector and operational doc (2026-07-12)

Built the plan Slice 8 drift detector as the gateway-agnostic
manually-triggered piece (task 5 does not include the ADR-pending real
adapter/recorder implementation, only the detector skeleton and doc):

- `app/Payments/Support/Fixtures/GatewayFixtureDriftDetector.php`:
  `detect(gateway, scenario, GatewayFixtureRecorder $recorder)` replays
  the scenario through the recorder's `record()` and diffs each returned
  exchange, in order, against the committed fixture files matching
  `{scenario}*.json` under `tests/Fixtures/gateways/{gateway}/`. Reports
  a missing-fixture drift entry if no fixture exists yet for the
  scenario, an exchange-count-mismatch entry if the live sandbox
  returned a different number of exchanges, or a per-exchange diff entry
  for any recorded/actual mismatch (compared via a recursive key-sorted
  JSON normalization, matching `GatewayFixtureLoader`'s existing request
  matcher convention).
- `app/Console/Commands/CheckGatewayFixtureDriftCommand.php`:
  `php artisan gateway:check-fixture-drift {slug} {scenario}`, the
  manual trigger. Resolves the bound `GatewayFixtureRecorder` for the
  slug from `config('payments.fixture_recorders')` (same lookup as the
  record command), fails with a clear error if none is bound, otherwise
  runs the detector and prints each drift entry's reason. This command
  is inherently network-touching (it calls the recorder's live sandbox
  request), so unlike the record command it carries no explicit
  `payments.ci` guard of its own; it is never wired into the CI-per-PR
  pipeline, matching the plan's "not CI-per-PR... manually triggered (and
  optionally nightly)" instruction, and no recorder is bound in any
  environment yet pending the ADR, so there is nothing for CI to
  accidentally invoke.
- `app/Payments/Support/Fixtures/README.md`: the operational doc living
  beside the harness, covering obtaining sandbox credentials (secret
  store, never committed, external KYC lead time called out), recording
  fixtures with `gateway:record-fixtures` (export credentials, run the
  command, diff and eyeball for secrets the sanitizer's known-format
  patterns might miss, run the sanitizer guard test before committing),
  checking for drift with the new `gateway:check-fixture-drift` command,
  and rotating the webhook signing secret (add the new secret alongside
  the old one so the verifier accepts both, confirm live deliveries
  verify against the new one, then revoke the old one; documents the
  brief 401 window if the chosen gateway cannot hold two secrets active
  at once, per the plan's Risks section).

Tests (failing first, per the double loop):
- `tests/Unit/Payments/GatewayFixtureDriftDetectorTest.php`: no drift
  when a fake recorder's returned exchange matches the committed
  `examplegw/create-payment.json` fixture exactly; drift detected when
  the recorder's exchange has a mutated response body field (the task's
  required proof: mutate a fixture's counterpart and assert detection);
  drift detected when the recorder returns a different number of
  exchanges than were recorded; drift detected when no fixture exists
  yet for the scenario being checked.
- `tests/Feature/Payments/CheckGatewayFixtureDriftCommandTest.php`: the
  command fails when no recorder is bound for the given slug (no real
  recorder exists yet pending the ADR, so this is the only case
  exercisable without live network access).

No Data class changed; `composer types:generate` not run. No new
tenant-scoped table; no isolation or concurrency test required (the
detector and command touch no database state).

Test evidence (apps/api):
- `php artisan test --filter='GatewayFixtureDriftDetectorTest|CheckGatewayFixtureDriftCommandTest'`: 5 passed, 7 assertions.
- `php artisan test tests/Unit/Payments tests/Feature/Payments`: 347 passed, 1362 assertions (no regression from the 342/1355 baseline recorded after task 8d-4).
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `./vendor/bin/pint --test` (touched files): clean.
- `./vendor/bin/phpstan analyse` (touched files, `--memory-limit=1G`): 0 errors.

No deviation from the plan. This closes out plan tasks 1 through 5 (the
gateway-agnostic slices); tasks 6 through 15 remain pending the launch
gateway ADR.

Commit: b5758ed

### Gate

Run at Sun Jul 12 13:44:51 -03 2026, full local quality gates after stage
8d implementation work:

- `composer -d apps/api run lint` (Pint): passed on the first pass.
- `composer -d apps/api run analyse` (Larastan): 1 error found
  (`ProblemRenderer::detailFor` had no match arm for the
  `GatewayNotConfigured` error code introduced in task 8d-3, so an
  UnhandledMatchError would fire instead of the intended 409 problem
  response). Fixed by adding the missing arm; commit `1d79018`,
  `fix(payments): map GatewayNotConfigured error code to a problem
  detail`. Re-run: 0 errors.
- `php artisan test --parallel`: failed with widespread `SQLSTATE[42P01]:
  Undefined table: migrations` errors across unrelated feature tests,
  consistent with parallel workers racing over shared database
  migration state rather than a real regression; one genuine failure
  surfaced underneath the noise (`ErrorCodeTest::the registry holds
  exactly the known codes`, missing the new `gateway_not_configured`
  code and its status/title/type dataset row). Fell back to a
  non-parallel run per the parallelism-induced-failure exception.
  Fixed the test's expected registry list and status/title dataset;
  commit `1d79018` also carries this fix (bundled with the analyse fix
  since both touch the same `GatewayNotConfigured` gap).
  `composer -d apps/api run test` hit the default 300s composer
  process timeout on the full suite; re-ran with `php artisan test`
  directly (no composer wrapper) to remove that ceiling. Final result:
  2733 passed, 10981 assertions, 0 failures.
- `composer -d apps/api run types:generate`: ran clean; `git status
  --short packages/api-client/src/generated` empty, no contract drift.
- `pnpm typecheck`: skipped, no TypeScript changed in this diff.

### Review rounds

#### Review round 1 (2026-07-12 13:51 -03, Codex)

Six findings (1 blocking, 5 important):

1. (blocking) `tests/Unit/Problems/ErrorCodeTest.php` -- registry test missing
   `gateway_not_configured`. No change needed: already fixed and committed in
   `ee1a0df` / `1d79018` before this round; the registry list (line 102) and the
   status/title/type dataset row (line 202) are both present and the test passes
   on a clean checkout. The finding described an earlier unstaged-worktree state.
2. (important) `app/Payments/Actions/InitiatePayment.php` `offeredMethod()` --
   any enabled empty-capability (skeleton) adapter forced `gateway_not_configured`
   even when a genuinely configured gateway was also enabled but did not serve the
   requested method. Fixed: `gateway_not_configured` now fires only when *every*
   enabled gateway is an unconfigured skeleton; if any configured gateway is
   present the method is genuinely off-offer and the generic 422
   `payment_method_not_available` is returned. Added a feature test asserting a
   `['fake','pending']` tenant requesting `sepa` gets 422, not 409.
3. (important) `tests/Support/Payments/conformance.php` -- idempotency test only
   compared references, never verified the gateway idempotency key was
   transmitted. Fixed: added an `assertIdempotencyKeyTransmitted` closure to the
   conformance context, called from the suite; the FakeGateway binding asserts the
   server-generated key (payment id) reached the gateway via the derived
   reference. An HTTP-backed adapter supplies the same closure asserting the
   Idempotency-Key header on the recorded outbound request.
4. (important) `app/Payments/Support/Fixtures/GatewayFixtureLoader.php` -- fixture
   matching ignored request headers, so the harness could not assert
   Idempotency-Key (or other required header) transmission. Fixed: added header
   matching to `matches()` (every fixture-declared header must be present with a
   matching value); added an `Idempotency-Key` header to the examplegw fixture and
   a loader test proving a request lacking it fails as not-covered.
5. (important) `app/Payments/Support/Fixtures/GatewayFixtureDriftDetector.php` --
   recorders return raw exchanges while committed fixtures are stored sanitized,
   so redacted credential values registered as false-positive drift. Fixed: the
   detector now redacts each live exchange through `GatewayFixtureSanitizer`
   before diffing; added a unit test proving a raw bearer token in the live
   response matches the redacted committed fixture with no drift.
6. (important) `app/Console/Commands/RecordGatewayFixturesCommand.php` --
   refreshing a scenario left stale higher-index fixtures behind. Fixed: the
   command deletes existing `{scenario}-*.json` files before writing the fresh
   set; added a feature test recording two exchanges over five stale files and
   asserting only the two fresh files (plus an unrelated scenario) survive.

Verification: `php artisan test --filter="GatewayFixtureLoader|GatewayFixtureDriftDetector|GatewayAdapterConformance|ErrorCode|RecordGatewayFixtures"`
(111 passed) and `--filter=PendingGatewayOfferAndInitiation` (3 passed);
`composer -d apps/api run lint` passed.

#### Review round 2 (2026-07-12 14:08 -03, Codex)

Two important findings:

1. (important) `app/Payments/Actions/StartSubmerchantOnboarding.php` -- onboarding
   a registered-but-unconfigured skeleton gateway rethrows
   `GatewayNotConfiguredException`, which the global map renders as a 409 with code
   `gateway_not_configured`, but the `SubmerchantOnboardingConflictProblem` schema
   only allowed `gateway_not_enabled` and `submerchant_already_onboarded`, so the
   live response drifted from the contract. Fixed by aligning the contract with the
   already-correct behavior (mirroring the payment-initiation 409, which documents
   `gateway_not_configured`): added the code to the schema enum and both 409
   descriptions in `docs/openapi/openapi.yaml`. Added a feature test onboarding a
   `pending` gateway asserting 409 `gateway_not_configured`, `assertConformsToOpenApi`,
   and that no stranded pending row survives the rolled-back gateway failure.
2. (important) `app/Payments/Support/Fixtures/GatewayFixtureLoader.php` -- the fake
   returned the first matching exchange for every request, so a fixture set recording
   several exchanges for the same request signature (poll pending then active, retry
   fail then succeed) replayed the first response forever and stranded async
   scenarios. Fixed: per-signature replay cursors advance through matching exchanges
   in recorded order and clamp to the last once exhausted; a single-exchange signature
   still answers every call unchanged. Added a loader test recording two exchanges for
   one GET and asserting `pending`, then `active`, then `active` (clamp).

Verification: `php artisan test tests/Unit/Payments/GatewayFixtureLoaderTest.php
tests/Feature/Payments/SubmerchantOnboardingTest.php` (24 passed) and
`tests/Contract/DocumentedResponseCoverageTest.php` (385 passed);
`composer -d apps/api run lint` passed.

### Decisions and deviations
