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
- [ ] T5 payments: sub-merchant webhook normalization, transition Action, refresh endpoint, concurrency and duplicate-delivery tests (slice 3)
- [ ] T6 payments: checkout offer and initiation gating on active sub-merchant, submerchant_not_active code (slice 4)
- [ ] T7 payments: payouts migration with RLS, Payout model, PayoutStatus enum, factory, isolation tests (slice 5)
- [ ] T8 payments: RecordGatewayPayout action, payout webhook normalization, PayoutExecuted event, outbox recording, concurrency test (slice 5)
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
