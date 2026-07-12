# Stage 8d Execution Journal: Real Gateway Adapter

## Run: 2026-07-12

- Stage: 8d (docs/plans/stage-08d-real-gateway.md)
- Branch: feat/api-implementation
- Base commit: fe5f95cea3ec131282b1f4fafeeacc1a82b8044a

The launch gateway ADR does not exist yet (docs/decisions ends at 019), so only the gateway-agnostic slices 1 through 4 and the drift detector (plan tasks 1 through 5) are in scope for this run. Plan tasks 6 through 15 remain pending the ADR.

### Checklist

- [x] 8d-1: Adapter conformance suite extracted from FakeGateway tests (slice 1)
- [x] 8d-2: Recorded-fixture harness: loader, no-network guard, secret sanitizer guard, record command inert in CI (slice 2)
- [ ] 8d-3: PendingGatewayAdapter and skeleton verifier, offer exclusion, gateway_not_configured problem code (slice 3)
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

Commit: (recorded below)

### Review rounds

### Decisions and deviations
