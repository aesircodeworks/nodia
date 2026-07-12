# Stage 8c Execution Journal: Payouts and Sub-merchant Onboarding

## Run: 2026-07-12

- Stage: 8c (docs/plans/stage-08c-payouts.md)
- Branch: feat/api-implementation
- Base commit: 675871a5b9552a9e55213db4062fcdb7cc96de1a

### Task checklist

- [x] T1 payments: extend GatewayAdapter with sub-merchant and payout operations, wire split-support flag, FakeGateway scenarios and webhook emitters (slice 1)
- [x] T2 payments: submerchant_accounts migration with RLS, SubmerchantAccount model, SubmerchantStatus enum, factory, isolation tests (slice 2)
- [x] T3 identity: register payouts.manage capability, financially privileged, template role wiring
- [ ] T4 payments: StartSubmerchantOnboarding action, POST/list/detail endpoints, Data objects, OpenAPI, error codes, duplicate-start concurrency test (slice 2)
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
