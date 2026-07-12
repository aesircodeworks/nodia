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
- [ ] 8d-4: KYC abstraction hardening: data-driven status mapping, needs_review quarantine, conditional-UPDATE transitions (slice 4)
- [ ] 8d-5: Sandbox drift detector and operational doc for recording fixtures and rotating webhook secrets (slice 8 detector, task 5)

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

### Review rounds

### Decisions and deviations
