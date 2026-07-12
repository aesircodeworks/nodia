# Stage 8c Execution Journal: Payouts and Sub-merchant Onboarding

## Run: 2026-07-12

- Stage: 8c (docs/plans/stage-08c-payouts.md)
- Branch: feat/api-implementation
- Base commit: 675871a5b9552a9e55213db4062fcdb7cc96de1a

### Task checklist

- [x] T1 payments: extend GatewayAdapter with sub-merchant and payout operations, wire split-support flag, FakeGateway scenarios and webhook emitters (slice 1)
- [x] T2 payments: submerchant_accounts migration with RLS, SubmerchantAccount model, SubmerchantStatus enum, factory, isolation tests (slice 2)
- [x] T3 identity: register payouts.manage capability, financially privileged, template role wiring
- [x] T4 payments: StartSubmerchantOnboarding action, POST/list/detail endpoints, Data objects, OpenAPI, error codes, duplicate-start concurrency test (slice 2)
- [x] T5 payments: sub-merchant webhook normalization, transition Action, refresh endpoint, concurrency and duplicate-delivery tests (slice 3)
- [x] T6 payments: checkout offer and initiation gating on active sub-merchant, submerchant_not_active code (slice 4)
- [ ] T7 payments: payouts migration with RLS, Payout model, PayoutStatus enum, factory, isolation tests (slice 5)
- [x] T8 payments: RecordGatewayPayout action, payout webhook normalization, PayoutExecuted event, outbox recording, concurrency test (slice 5)
- [ ] T9 payments: payout read endpoints with cursor pagination, Data objects, OpenAPI (slice 5)
- [ ] T10 payments: ProjectLedgerEntries subscription to PayoutExecuted, balanced entry pair, replay rebuild test (slice 6)
- [ ] T11 payments: ReconcilePayouts scheduled command, missed-payout creation, discrepancy flagging, activity log (slice 6)
- [ ] T12 payments: stage exit end-to-end loop test across scripted failure modes; flip status table to Done (slice 7)

### Review rounds

### Decisions and deviations

#### T1: GatewayAdapter sub-merchant/payout operations, FakeGateway scenarios (2026-07-12)

Landed:

- `App\Payments\Enums\SubmerchantStatus` and `App\Payments\Enums\PayoutStatus`, added now (rather than deferred to T2/T7) because `GatewayAdapter::createSubmerchant`/`fetchSubmerchantStatus`/`listPayouts` need a status vocabulary to return; T2 and T7 reuse these enums for the `submerchant_accounts.status` and `payouts.status` columns rather than defining their own. Recorded as a deviation from the checklist's task grouping, not from the plan's data model (the enum members match the plan's transition tables exactly).
- `App\Payments\Gateways\SubmerchantRegistrationRequest`, `GatewaySubmerchantResult`, `GatewayPayoutRecord` value objects (the gateway seam never leaks a gateway-specific shape).
- `GatewayAdapter::createSubmerchant`, `fetchSubmerchantStatus`, `listPayouts(?CarbonImmutable $since = null)`.
- `FakeGateway` implementations: `createSubmerchant` defaults to `pending` with a deterministic reference and onboarding URL; `fetchSubmerchantStatus` reads a scripted per-reference result, defaulting to `pending`; `listPayouts` reads a scripted list, optionally filtered by `executedAt` since a given instant.
- `FakeGatewayScenarios` gained `scriptSubmerchantCreation` (FIFO queue), `scriptSubmerchantStatus`/`submerchantStatusFor` (per-reference), `scriptPayouts`/`payouts`.
- Webhook emitters `submerchantStatusWebhook` (carries `requirements`), `payoutCreatedWebhook`, `payoutStatusWebhook`, all HMAC-signed through the existing `sign()` helper and reusing an `eventId` argument to script duplicate deliveries, exactly like the Stage 8a payment/refund emitters.
- `GatewayCapabilities::splitSupport` wired into `App\Payments\Support\OfferAssembler::assemble`/`allows` via a new `array<string, bool> $submerchantActiveByGateway` parameter (default `[]`, missing gateway treated as not active): a gateway whose capability reports `splitSupport` is withheld from the offer unless the caller marks it active. `FakeGateway::capabilities()->splitSupport` now reads `config('payments.gateways.fake.split_support')` (new config key, default `false`) instead of the previous hardcoded `false`, so a future slice/test can flip it. Full checkout gating against a real `submerchant_accounts` status is Slice 4 (T6); this slice only makes the flag consequential in the pure assembler function, per the plan's own text for Slice 1.
- Architecture test `tests/Architecture/GatewayAdapterExtensionTest.php`: the Payments gateway seam (`App\Payments\Gateways`) is only used within `App\Payments`, and the three new value objects are classes living in that namespace.
- `tests/Architecture/PresetTest.php` updated to ignore the two new enums, following the existing precedent for `PaymentStatus`/`RefundStatus` (status registries live with their bounded context, not `App\Enums`).

Test evidence (from `apps/api`):

- `php artisan test --filter=FakeGatewayTest`: 25 passed, 78 assertions.
- `php artisan test --filter=OfferAssemblerTest`: 8 passed, 10 assertions.
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `php artisan test --testsuite=Unit`: 845 passed, 2033 assertions (full unit regression, confirms no other suite broke).
- `vendor/bin/pint --test` on all touched/new files: passed.

No Data class changed, so `composer types:generate` was not run for this slice.

Deviation from the strict outside-in TDD order: this is a pure Payments-internal slice with no endpoint, no contract, and no isolation/concurrency table per the plan's own Slice 1 description ("No endpoints; pure Payments-internal surface"), so the double loop collapses to unit tests plus one architecture test, as the task instructions specified.

#### T2: submerchant_accounts table, model, isolation tests (2026-07-12)

Landed:

- Migration `database/migrations/2026_07_12_000047_create_submerchant_accounts_table.php`: `submerchant_accounts` table, unique `(tenant_id, gateway)`, partial unique index `submerchant_accounts_gateway_reference_idx` on `(gateway, gateway_account_reference)` where not null, index `(tenant_id, status)`, RLS policy applied via `Rls::applyTenantPolicies` in the same migration.
- `App\Payments\Models\SubmerchantAccount` (`HasUuids`, `HasFactory`), casting `status` to `SubmerchantStatus`, `requirements` to array, `activated_at` to datetime.
- `App\Payments\Enums\SubmerchantStatus` already existed from T1 (same six members the plan specifies); added the missing `#[TypeScript]` attribute here since this is the task that makes it a wire-facing status column, matching `PaymentStatus`/`RefundStatus` convention. Ran `composer types:generate` and committed the regenerated `packages/api-client/src/generated` output.
- `Database\Factories\Payments\Models\SubmerchantAccountFactory`, no default `tenant_id` (mirrors `PaymentFactory`/`RefundFactory`: callers pass a real id).
- Failing-first isolation tests: `tests/Isolation/SubmerchantAccountsIsolationTest.php` plus `tests/Isolation/Support/SubmerchantAccountFixture.php` (built directly on `TenantFixture`, no payment/order dependency), covering tenant-scoped SELECT, cross-tenant UPDATE/DELETE returning zero rows, cross-tenant insert rejection, and platform-role cross-tenant read, mirroring `RefundsIsolationTest`.

Test evidence (from `apps/api`):

- `php artisan test --filter=SubmerchantAccountsIsolationTest`: 5 passed, 6 assertions.
- `php artisan test --testsuite=Isolation`: 265 passed, 533 assertions (full isolation regression, confirms no other table's policy broke).
- `vendor/bin/pint` on all touched/new files: passed.
- `composer types:generate`: ran and committed regenerated output (SubmerchantStatus gained the `#[TypeScript]` attribute).

No deviation from the plan's data model; the enum-creation timing deviation was already recorded under T1.

#### T3: payouts.manage capability, financially privileged, template role wiring (2026-07-12)

Landed:

- `App\Identity\Capability::PayoutsManage = 'payouts.manage'`, added to the registry immediately after `PayoutsView`; `isFinanciallyPrivileged()` extended to mark it true, alongside `OrdersRefund`, `PayoutsView`, `LedgerView`.
- `SeedTemplateRoles::templates()`: `payouts.manage` added to the `Owner` and `Finance` templates (the two roles already holding `payouts.view`), no other template touched.
- Failing-first unit tests: `tests/Unit/Identity/CapabilityTest.php` (registry exact-membership test and the financially-privileged-set test both updated to include `payouts.manage`, verified failing against the pre-change enum before the enum edit landed), `tests/Unit/Identity/SeedTemplateRolesTest.php` (renamed the Finance assertion to `grants the Finance template every financially privileged capability` and added `PayoutsManage` to its expectation; `grants the Owner template every capability except tenants.manage` needed no edit since it already derives its expectation from `Capability::cases()` minus `TenantsManage`).

Test evidence (from `apps/api`):

- `php artisan test --filter=CapabilityTest`: confirmed failing (2 failures) against the test-first commit state, then passing after the implementation edit: 14 passed, 14 assertions.
- `php artisan test --filter=SeedTemplateRolesTest`: 5 passed, 8 assertions.
- `php artisan test --filter=AuthorizationMatrixTest`: 102 passed, 722 assertions.
- `php artisan test --filter=MfaEnforcementPolicyTest`: 5 passed, 5 assertions.
- `php artisan test --testsuite=Unit`: 845 passed, 2034 assertions (full unit regression).
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `vendor/bin/pint --test` on all touched files: passed.

No Data class changed, so `composer types:generate` was not run. No deviation from the plan: this is exactly the small, capability-registry-only task the plan describes, unblocking T4, T5, and T9.

#### T4: StartSubmerchantOnboarding action, POST/list/detail endpoints, OpenAPI, error codes (2026-07-12)

This task was already substantially implemented and left uncommitted in the working tree at the start of this run (`git status` showed the action, controller, Data objects, exceptions, OpenAPI paths, and all three test files already present but untracked); this entry verifies, closes the remaining gap, and commits it.

Landed:

- `App\Payments\Actions\StartSubmerchantOnboarding`: reads `enabled_gateways` through `App\Tenancy\Actions\ResolveEnabledGateways` (never the tenant model directly), inserts the `pending` row first so a losing concurrent start never reaches the gateway, catches `UniqueConstraintViolationException` on the `(tenant_id, gateway)` constraint to raise `SubmerchantAlreadyOnboardedException` with the existing row's id, checks the circuit breaker before calling the gateway and records success/failure on it, then updates the row with `GatewayAdapter::createSubmerchant`'s result (status, reference, onboarding URL, requirements, `activated_at` when immediately active).
- `App\Tenancy\Actions\ResolveTenantPayoutSchedule`, mirroring `ResolveTenantSettlementCurrency`'s cross-context read pattern, feeding `payout_schedule` into the gateway registration request.
- `StartSubmerchantOnboardingData` (request) and `SubmerchantAccountData` (response, `#[TypeScript]`) laravel-data objects; `App\Payments\Http\Controllers\SubmerchantAccountController` with `store`/`index`/`show`, `index` on `Spatie\QueryBuilder` with `filter[gateway]`, `filter[status]` (exact) and `-created_at` default sort, unknown filters rejected by the existing query-builder-exception handling.
- New error codes on the shared `ErrorCode` enum: `gateway_unknown` (422), `gateway_not_enabled` (409), `submerchant_already_onboarded` (409); `App\Support\Problems\HasProblemExtensions` interface added so `SubmerchantAlreadyOnboardedException` can attach `existing_id` to the problem document without every other exception gaining an unused hook (`ProblemRenderer::render` now checks for the interface and merges its extensions in).
- Routes: `POST /v1/submerchant-accounts` behind `payouts.manage` plus `RecordActivityAudit` (activity log entry asserted in the feature test); `GET /v1/submerchant-accounts` and `GET /v1/submerchant-accounts/{submerchant_account}` behind `payouts.view`.
- OpenAPI: all four submerchant-account paths (the fourth, refresh, is Slice 3's; only POST/list/detail ship here) with `SubmerchantAccount`, `StartSubmerchantOnboardingRequest`, `SubmerchantAccountPage`, and the two new problem schemas.
- `FakeGatewayScenarios::recordSubmerchantCreationCall`/`submerchantCreationCallCountFor`, called from `FakeGateway::createSubmerchant`, giving the unit and concurrency tests a way to assert the gateway was called exactly once per successful start without relying on shared in-process state across `ParallelRunner`'s forked processes (the concurrency test asserts on the database row count and non-null `gateway_account_reference` instead, per its own docblock).
- Tests: `tests/Feature/Payments/SubmerchantOnboardingTest.php` (201 pending and immediate-active paths, all four stable error codes, validation, missing `X-Tenant-Id`, capability and MFA denial, list with every filter, unknown-filter rejection, detail shape, 404 for absent and cross-tenant ids); `tests/Unit/Payments/StartSubmerchantOnboardingActionTest.php` (insert-then-call ordering, each error path calls the gateway zero or one times as expected, `existing_id` on the conflict); `tests/Concurrency/SubmerchantOnboardingContentionTest.php` (4-way parallel start: exactly one row, one `gateway_account_reference`, three losers).
- Filled the one real gap found during verification: `tests/Contract/DocumentedResponseCoverageTest.php` had no exercisers registered for any of the fifteen new documented responses (`DocumentedResponseCoverageTest::documented_response_is_exercised_with_conformance_asserted` failed for all fifteen). Added `contractPayoutsManageBearer`, `contractPayoutsViewBearer`, their shared `contractFinanciallyPrivilegedBearer` helper, and `contractSubmerchantAccount`, then one exerciser per documented status code, following the existing `contractRefundBearer`/`contractCreateRefund` precedent. Also added a `submerchant_accounts` cleanup line to the suite's per-tenant `afterEach` teardown (missing it broke tenant deletion with a foreign-key violation, since `submerchant_accounts.tenant_id` carries no cascade, the same pattern already followed for `refunds` and `payments`).

Test evidence (from `apps/api`):

- `php artisan test --filter=SubmerchantOnboardingTest`: 16 passed, 65 assertions.
- `php artisan test --filter=StartSubmerchantOnboardingActionTest`: 4 passed, 10 assertions.
- `php artisan test --filter=SubmerchantOnboardingContentionTest`: 1 passed, 4 assertions.
- `php artisan test --testsuite=Contract`: 377 passed, 2417 assertions (full contract regression, confirms the fifteen new exercisers pass and nothing else broke).
- `php artisan test --testsuite=Feature --filter=Payments`: 115 passed, 659 assertions.
- `php artisan test --testsuite=Unit`: 852 passed, 2056 assertions.
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `php artisan test --testsuite=Concurrency`: 41 passed, 185 assertions.
- `vendor/bin/pint --test` on all touched/new files: passed.
- `composer types:generate`: ran and committed regenerated output (`Capability::PayoutsManage`, the three new `ErrorCode` members, `StartSubmerchantOnboardingData`, `SubmerchantAccountData`).

No deviation from the plan's endpoint or error-code shapes. Commit: `982adc5` (`feat(payments): add submerchant onboarding start and read endpoints`).

#### T5: sub-merchant webhook normalization, transition Action, refresh endpoint (2026-07-12)

Landed:

- `App\Payments\Gateways\NormalizedSubmerchantEvent` (gatewayAccountReference, `SubmerchantStatus`, requirements list), a sibling to `NormalizedPaymentEvent` rather than a shared union: the sub-merchant payload shape and its resolution key (`gateway_account_reference`, not a payment/refund reference) are unrelated, and keeping it separate left every existing payment/refund webhook test untouched. `GatewayAdapter::normalizeSubmerchantWebhook(array $payload): ?NormalizedSubmerchantEvent` added to the interface (single implementer, `FakeGateway`); it only recognizes `submerchant.status_changed` payloads and returns null for everything else, so it never intercepts a payment/refund event routed through the pre-existing `normalizeWebhook`.
- `App\Payments\Actions\TransitionSubmerchantAccount`: the full `SubmerchantStatus` matrix from the stage plan's Data model section, encoded as a `target => list<legal sources>` map (six targets, the two toggle pairs, and the `rejected -> pending` retry), applied as one conditional `UPDATE ... WHERE id = ? AND status IN (...)` checked by affected-row count. `activated_at` is stamped only on a transition landing on `active`; `requirements` is always overwritten with whatever the caller passed (defaulting `[]`), so a webhook that resolves `action_required`'s requirements and a later `active` webhook that clears them both land correctly without a separate code path. The out-of-order forward-skip case (active arriving while still pending) needs no special casing: `pending` is already a listed source for `active`.
- `App\Payments\Actions\RefreshSubmerchantStatus`: the manual fallback, same circuit-breaker success/failure recording pattern as `StartSubmerchantOnboarding`, calls `fetchSubmerchantStatus` and feeds the result straight into `TransitionSubmerchantAccount`. A row with no `gateway_account_reference` yet (gateway never acknowledged) is returned unchanged rather than erroring, since there is nothing to refresh.
- `App\Payments\Jobs\ProcessGatewayWebhook` gained an `applySubmerchant` branch, entered only when `normalizeWebhook` returns null and `normalizeSubmerchantWebhook` returns non-null: resolves the account by `(gateway, gateway_account_reference)` under the platform role with the same `platform_role_use` activity-log call the payment/refund branches already make (system-design 4.3), then applies the transition inside a tenant-scoped transaction. A zero-row transition whose account already carries the reported status is treated as processed (duplicate); anything else zero-row is ignored. This reuses the job's existing top-of-`handle()` guard (`$row->status !== Received` returns immediately) for the duplicate-delivery invariant, exactly as the pre-existing payment path does: a second delivery of the same gateway event id re-enqueues the same row but the job no-ops before doing any work, so only one `platform_role_use` entry and one state change ever land, with no new dedup logic needed.
- `SubmerchantAccountController::refresh` and `POST /v1/submerchant-accounts/{submerchant_account}/refresh`, gated on `payouts.manage` plus `RecordActivityAudit`, alongside the existing `store` route. The controller wraps the response in an explicit `response()->json(..., 200)`: laravel-data's default `ResponsableData::calculateResponseStatus` returns 201 for any POST, which is correct for `store` but wrong for a POST that fetches-and-transitions rather than creates, so `refresh` overrides it explicitly (caught by a first failing conformance/status-code run against the OpenAPI-documented 200).
- OpenAPI: the refresh path added after the existing detail path, 200/401/403/404/503 responses documented; `DocumentedResponseCoverageTest` gained the five matching exercisers (`post /v1/submerchant-accounts/{submerchant_account}/refresh {200,401,403,404,503}`), following the T4 precedent of adding exercisers as part of the same slice rather than leaving contract coverage to a separate pass.
- Tests: `tests/Unit/Payments/TransitionSubmerchantAccountTest.php` (13 legal transitions and 17 illegal ones data-driven over the full matrix, plus one explicit out-of-order forward-skip case); `tests/Feature/Payments/SubmerchantOnboardingWebhookTest.php` (pending-to-active with `activated_at`, `action_required` requirements, `rejected`, the `rejected`-to-`pending` retry, the platform_role_use audit count, duplicate delivery producing one state change and one activity entry, an unmatched-reference webhook ignored, and the refresh endpoint's 200/404/403 paths); `tests/Concurrency/SubmerchantAccountTransitionContentionTest.php` (a webhook delivery to `active` racing a refresh to a scripted `rejected` result on the same account via `ParallelRunner::runEach`, asserting the account lands on exactly one of the two targets).

Test evidence (from `apps/api`):

- `php artisan test --filter=TransitionSubmerchantAccountTest`: 31 passed, 80 assertions.
- `php artisan test --filter=SubmerchantOnboardingWebhookTest`: 10 passed, 30 assertions.
- `php artisan test --filter=SubmerchantAccountTransitionContentionTest`: 1 passed, 2 assertions (run three times in a row to check for flakiness; all three green).
- `php artisan test --testsuite=Feature --filter=Payments`: 125 passed, 689 assertions.
- `php artisan test --testsuite=Unit`: 883 passed, 2136 assertions.
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `php artisan test --testsuite=Concurrency`: 42 passed, 187 assertions.
- `php artisan test --testsuite=Contract`: 382 passed, 2449 assertions (full contract regression, confirms the five new exercisers pass and nothing else broke).
- `vendor/bin/pint --test` on all touched/new files: passed (after one auto-fix run on the new feature test file's import ordering).

No Data class changed in this slice (the transition and refresh actions work directly with the model and existing `SubmerchantAccountData`), so `composer types:generate` was not run. No deviation from the plan's transition matrix, endpoint shape, or error codes (`request.not_found`, `gateway_unavailable` were already registered).

#### T6: checkout offer and initiation gating on active sub-merchant (2026-07-12)

Landed:

- `App\Payments\Actions\BuildPaymentMethodOffer` now resolves an `array<string, bool>` sub-merchant-active map (queries `SubmerchantAccount` for the tenant, scoped to the enabled gateways in play, `status === Active`) and passes it to `OfferAssembler::assemble` alongside a new `bool $requireActiveSubmerchant = true` parameter (both the assembler's flag and this action's own new `__invoke` parameter of the same name), completing the wiring the Slice 1 (T1) assembler change left for this slice: a gateway whose capability reports `splitSupport` is now genuinely withheld from a live checkout offer unless its sub-merchant account is `active`.
- `App\Payments\Actions\InitiatePayment::offeredMethod` distinguishes the two ways a method can be absent from the offer: a first pass with normal gating finds the method and proceeds as before; on a miss, a second pass with `requireActiveSubmerchant: false` checks whether the method exists once sub-merchant gating is ignored, and if so throws the new `SubmerchantNotActiveException` (409) instead of falling through to the existing `PaymentMethodNotAvailableException` (422). This keeps the offer endpoint and the initiation action sharing one code path (`BuildPaymentMethodOffer`) rather than duplicating the capability-and-currency logic, per the plan's own framing that initiation gating extends the offer's gating.
- New `ErrorCode::SubmerchantNotActive = 'submerchant_not_active'` (409, title "Submerchant not active") and `App\Payments\Exceptions\SubmerchantNotActiveException` (`HasErrorCode`), following the existing `GatewayNotEnabledException` shape exactly.
- OpenAPI: `submerchant_not_active` added to the existing `PaymentInitiationConflictProblem` schema's `code` enum (alongside `order_not_payable` and `idempotency_key_reuse_mismatch`) and to the 409 response description on `POST /v1/storefront/orders/{order}/payments`; no new response entry, so `DocumentedResponseCoverageTest` needed no new exerciser (an existing 409 exerciser already asserts conformance against the widened enum).
- Tests (feature-first): `tests/Feature/Payments/SubmerchantOfferGatingTest.php` toggles a fake-gateway sub-merchant account through `pending` (offer excludes), `active` (offer includes; initiation succeeds), and `disabled` (offer excludes again), plus a no-account-at-all case, and asserts initiation against a `pending` account renders `submerchant_not_active`; this suite turns on `payments.gateways.fake.split_support` for its duration (restored to `false` in `afterEach`, matching the T1 config key) since `splitSupport` is what makes the gating apply. `tests/Unit/Payments/OfferAssemblerTest.php`'s composition tests from T1 already cover the assembler's pure-function behavior (no gap found requiring new unit tests there); `tests/Unit/Problems/ErrorCodeTest.php` extended with the new code's registry membership, status, title, and type-slug rows.
- `tests/Architecture/PresetTest.php` updated to add `SubmerchantNotActiveException` to the list of `HasErrorCode` exceptions the Laravel preset should not flag as living outside `App\Exceptions` (same precedent as every other Payments exception).

Test evidence (from `apps/api`):

- `php artisan test --filter=SubmerchantOfferGatingTest`: 6 passed, 29 assertions.
- `php artisan test --testsuite=Feature --filter=Payments`: 131 passed, 718 assertions (full Payments feature regression, confirms the offer and initiation paths in earlier slices still hold under the new gating).
- `php artisan test --testsuite=Unit`: 884 passed, 2140 assertions.
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `php artisan test --testsuite=Contract`: 382 passed, 2449 assertions.
- `php artisan test --testsuite=Concurrency`: 42 passed, 187 assertions (full regression; no new concurrency test needed, this slice adds no new invariant-guarding transition).
- `vendor/bin/pint --test` on all touched/new files: passed.
- `composer types:generate`: ran and committed regenerated output (`ErrorCode::SubmerchantNotActive`).

Deviation: the plan's error-code text says "409 gateway_not_enabled semantics extended by a distinct code submerchant_not_active" — read as "the same conflict-response shape as the existing 409 gateway conflicts, under a new code," not as reusing `GatewayNotEnabled` itself; `GatewayNotEnabled` guards a different invariant (the gateway identifier isn't in `enabled_gateways` at all, checked at onboarding time) and conflating the two would blur two distinct failure causes behind one code. No other deviation from the plan's gating description or endpoint shapes. Commit: `f010e75` (`feat(payments): gate checkout offer and initiation on active sub-merchant`).

#### T7: payouts table, model, enum, isolation tests (2026-07-12)

Landed:

- `App\Payments\Enums\PayoutStatus` already existed from T1's `GatewayAdapter` extension work (committed in `23d7781`), with the exact five cases the stage plan's Data model section specifies (`pending`, `in_transit`, `paid`, `failed`, `canceled`); no change needed there.
- `database/migrations/2026_07_12_000048_create_payouts_table.php`: `payouts` table per the Data model section exactly — `amount`/`currency` bare pair (data-conventions Money exception for a row that is itself a single monetary fact), unique `(gateway, gateway_reference)` as the webhook/poller idempotence anchor, indexes on `(tenant_id, status)` and `(tenant_id, created_at)`, `Rls::applyTenantPolicies('payouts')` in the same migration (data-conventions, ADR 003). No `discrepancy_amount` currency column needed since it shares the row's own `currency`.
- `App\Payments\Models\Payout`: UUIDv7 PK via `HasUuids`, `money` virtual attribute cast through `MoneyCast::class.':amount'` mirroring `Payment`'s single-monetary-fact pattern, `status` cast to `PayoutStatus`, `executed_at`/`reconciled_at` datetime casts, `Fillable` list matching the schema.
- `database/factories/Payments/Models/PayoutFactory`: `gateway` defaults `fake`, `gateway_reference` a random UUIDv7, `money` a `5000 USD` default, `status` `Pending`; `tenant_id` left for callers to pass explicitly, mirroring `PaymentFactory` and `SubmerchantAccountFactory`.
- Tests first: `tests/Isolation/Support/PayoutFixture.php` (two-tenant fixture, one pending payout each, mirroring `SubmerchantAccountFixture`'s shape exactly) and `tests/Isolation/PayoutsIsolationTest.php` (tenant sees only its own payout, cross-tenant update and delete affect zero rows, an insert claiming another tenant's id is rejected by the RLS policy, the platform role reads across tenants). Confirmed failing first against the pre-model state (`Class "App\Payments\Models\Payout" not found`), then green after the migration, model, and factory landed.

Test evidence (from `apps/api`):

- `php artisan test --filter=PayoutsIsolationTest`: 5 passed, 6 assertions (confirmed failing beforehand with the model missing).
- `php artisan test --testsuite=Isolation`: 270 passed, 539 assertions (full isolation regression).
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `vendor/bin/pint --test` on all new files: passed.

No Data class changed in this slice (no request/response objects, no endpoints — those are later Slice 5 tasks), so `composer types:generate` was not run. No deviation from the plan's `payouts` schema, indexes, or constraints.

#### T8: RecordGatewayPayout, payout webhook normalization, PayoutExecuted event (2026-07-12)

Landed:

- `App\Payments\Gateways\NormalizedPayoutEvent` (gatewayAccountReference, gatewayReference, `PayoutStatus`, nullable `Money` amount, nullable executedAt), a sibling to `NormalizedSubmerchantEvent` rather than a shared union, following the same precedent set in T5. `amount` is non-null only for a `payout.created` payload, the sole moment a mirror row can be inserted; a `payout.status_changed` payload always carries null, since it only ever transitions a row that must already exist. `GatewayAdapter::normalizePayoutWebhook(array $payload): ?NormalizedPayoutEvent` added to the interface (single implementer, `FakeGateway`).
- Deviation from T1's already-shipped `FakeGateway::payoutCreatedWebhook`/`payoutStatusWebhook` signatures: both gained a required `$gatewayAccountReference` parameter (before the existing `$gatewayReference`), because the stage plan's Domain events section is explicit that payout callbacks "carry no tenant context" and `ProcessGatewayWebhook` "resolves the tenant by looking up the sub-merchant account via (gateway, gateway_account_reference)" — T1's emitters had no field to carry that reference. Updated the pre-existing `FakeGatewayTest` call sites in the same commit (signature change, not new behavior); no production caller existed yet since T8 is the first task to call these two emitters from a webhook-processing path.
- `App\Payments\Actions\RecordGatewayPayout`: upserts a `payouts` row by `(gateway, gateway_reference)`. No existing row: inserts from a `payout.created` event (the only event carrying `amount`) inside its own `DB::transaction` so a losing concurrent insert's `UniqueConstraintViolationException` rolls back to a savepoint rather than poisoning the caller's outer tenant transaction (mirroring `StartSubmerchantOnboarding`'s insert-then-catch precedent from T4), then falls through to the transition path against the winner's row; a `payout.status_changed` event with no existing row and no amount to insert returns null (the reconciliation poller, T11, is the backstop for a missed creation webhook). Existing row: applies the `PayoutStatus` transition matrix (`pending`→`in_transit`/`paid`/`failed`/`canceled`, `in_transit`→`paid`/`failed`/`canceled`, all three terminals closed) as a conditional `UPDATE ... WHERE id = ? AND status IN (...)` checked by affected-row count. The out-of-order forward-skip case (paid arriving before in_transit) needs no special casing: `pending` is already a listed source for `paid`. `PayoutExecuted` is recorded (via `OutboxRecorder`) only inside the branch landing on `paid`, whether reached by direct insert or by transition, so a payout that skips straight to paid still gets the event exactly once; `failed`/`canceled` transitions record nothing, matching the plan's "failed payouts record no domain event."
- `App\Payments\Events\PayoutExecuted` and `PayoutExecutedPayload` (`#[Hidden]`, `payout_id`, `gateway`, `gateway_reference`, `amount`/`currency` as the money wire shape, `executed_at`), following the `PaymentConfirmed`/`PaymentConfirmedPayload` pattern exactly. Registered `PayoutExecuted` in `PaymentsServiceProvider::boot()`'s `EventTypeRegistry` (no subscriber wired yet; `ProjectLedgerEntries`'s subscription is T10).
- `App\Payments\Jobs\ProcessGatewayWebhook` gained an `applyPayout` branch, entered only when `normalizeWebhook` and `normalizeSubmerchantWebhook` both return null and `normalizePayoutWebhook` returns non-null: resolves the sub-merchant account by `(gateway, gateway_account_reference)` under the platform role with the same `platform_role_use` activity-log call the payment/refund/sub-merchant branches already make, then calls `RecordGatewayPayout` inside a tenant-scoped transaction. A null result whose payout row already carries the reported status is treated as a duplicate (processed); anything else null (no sub-merchant match, or a status-changed event racing ahead of its creation event) is ignored. Duplicate delivery needs no new dedup logic: the job's existing top-of-`handle()` guard on the raw row's own status is what makes a second delivery of the same gateway event id a no-op, exactly as the payment/refund/sub-merchant paths already rely on.
- No endpoint and no OpenAPI change: `POST /v1/webhooks/{gateway}` already documents the generic webhook path from Stage 8a; payout read endpoints are T9.
- Tests: `tests/Unit/Payments/RecordGatewayPayoutTest.php` (insert from created with no event, duplicate created no-ops, status-changed with no row and no amount returns null, `pending`→`in_transit`, forward-skip `pending`→`paid` with `executed_at` and one `PayoutExecuted` recorded with the expected payload shape, a late `in_transit` after `paid` affects zero rows and does not re-record the event, `failed` and `canceled` record no event, an illegal transition off a terminal status affects zero rows); `tests/Feature/Payments/PayoutWebhookTest.php` (created-then-paid over HTTP against the fake gateway, out-of-order paid-before-in_transit, `failed`/`canceled` paths recording no event, one `platform_role_use` audit entry, duplicate delivery producing one row/one transition/one event, an unmatched gateway-account-reference webhook ignored); `tests/Concurrency/PayoutTransitionContentionTest.php` (two parallel `ProcessGatewayWebhook` workers processing two distinct gateway event ids both encoding the same paid transition, following `WebhookProcessingContentionTest`'s precedent of bypassing raw-ingest dedup to exercise the transition-level guard; asserts exactly one payout row, one `paid` status, one `PayoutExecuted` event; run three times in a row to check for flakiness, all green).

Test evidence (from `apps/api`):

- `php artisan test --filter=RecordGatewayPayoutTest`: 9 passed, 23 assertions.
- `php artisan test --filter=FakeGatewayTest`: 28 passed, 91 assertions.
- `php artisan test --filter=PayoutWebhookTest`: 8 passed, 33 assertions.
- `php artisan test --filter=PayoutTransitionContentionTest`: 1 passed, 3 assertions (run three times in a row; all green).
- `php artisan test --testsuite=Feature --filter=Payments`: 139 passed, 751 assertions (full Payments feature regression).
- `php artisan test --testsuite=Unit`: 896 passed, 2176 assertions.
- `php artisan test --testsuite=Architecture`: 40 passed, 97 assertions.
- `php artisan test --testsuite=Concurrency`: 43 passed, 188 assertions.
- `php artisan test --testsuite=Contract`: 382 passed, 2449 assertions (no new documented responses in this slice, so no new exercisers; confirms nothing regressed).
- `vendor/bin/pint --test` on all touched/new files: passed.

No Data class visible outside the outbox payload boundary changed (`PayoutExecutedPayload` is `#[Hidden]`, an internal outbox payload, not an API contract), so `composer types:generate` was not run. No deviation from the plan's transition matrix, event envelope, or payload fields; the only deviation is the T1 emitter signature widening recorded above, which was necessary to satisfy the plan's own tenant-resolution requirement for payout webhooks and was not yet exercised by any other task.
