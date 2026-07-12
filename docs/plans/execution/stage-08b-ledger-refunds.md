# Stage 8b Execution Journal: Ledger and Refunds

## Run 1

- Stage: 8b (docs/plans/stage-08b-ledger-refunds.md)
- Date: 2026-07-11 22:31 -03
- Branch: feat/api-implementation
- Base commit: 7ac125359472bdc79618e0ce75ec0bd38221d54e

### Task checklist

- [ ] T1: coordination verification against Stage 8a deliverables
- [ ] T2: tenants commission migration, enums, TenantData extension, admin endpoint, types (slice 1)
- [ ] T3: confirmation Action persists the breakdown via the wired commission resolver (slice 2)
- [ ] T4: ledger_entries migration with RLS and append-only trigger, model, enums, entry-set builder (slice 3)
- [ ] T5: ordered-consumption helper extension with a subscriber-supplied ordering key
- [ ] T6: ledger projection consumer for PaymentConfirmed with deferral, duplicate-delivery, replay coverage (slice 4)
- [ ] T7: refunds migration with RLS, model, status enum, payments refunded reservation columns
- [ ] T8: CreateRefund Action and POST /v1/payments/{payment}/refunds (slice 5)
- [ ] T9: refund executor, FakeGateway refund scenarios, completion webhook normalization, reconciliation sweeper (slice 6)
- [ ] T10: Orders MarkTicketsRefunded Action recording TicketRefunded
- [ ] T11: Orders refund transition Actions and state-machine table test extension, design 7.1 amendment
- [ ] T12: completion transaction with Orders transitions, RefundCompleted, ledger legs (slice 7)
- [ ] T13: read endpoints: refund show and list, ledger entries, ledger balances (slice 8)
- [ ] T14: balance invariant scenario harness and status table updates (slice 9)

### Task entries

#### T1: coordination verification (2026-07-11 22:31 -03)

Verified the Stage 8a coordination items landed as the 8b plan specifies, so nothing needs additive patching here:

- `PaymentConfirmedPayload` carries `fee` as a `Money` object (app/Payments/Events/PaymentConfirmedPayload.php).
- `ConfirmPayment` persists `fee_amount` and `commission_amount` on the payment row in the same transaction that records `PaymentConfirmed`, with commission through `Payments/Support/CommissionResolver`, which returns zero pending this stage's tenant configuration (its docstring names Stage 8b as the wiring point).
- The `payments` creating migration ships `fee_amount` and `commission_amount` bigint columns with non-negative checks.

No code change required. T1 done.

#### T2: tenant commission configuration (2026-07-11 22:50 -03)

Slice 1 landed test-first: feature tests on PATCH /v1/tenants/{tenant} (update both fields, defaults on read, invalid policy and out-of-range bps rejected with request.validation_failed) and unit tests for the RefundCommissionPolicy enum and CommissionCalculator (bps of gross, round half up pinned at boundaries) were written and observed failing before any implementation. Additive tenants migration adds commission_bps (default 0) and refund_commission_policy (default retained), no format CHECK per the settlement_currency precedent. TenantData and UpdateTenantData extended, UpdateBranding applies the fields, OpenAPI Tenant and TenantUpdateRequest schemas updated, TypeScript regenerated. Tenant model carries attribute defaults so freshly created models serialize without a re-read (the two POST wire-shape tests caught this). Evidence: CommissionCalculatorTest 11/11, TenantEndpointsTest 28/28, Contract suite 340/340, Tenancy filter 260/260, api-client tsc clean.

#### T3: confirmation persists the breakdown (2026-07-11 23:05 -03)

Slice 2 test-first: PaymentStateMachineTest gained a failing test proving the confirming statement persists the tenant-configured commission (250 bps of a 5000 gross = 125), observed failing at commission 0 before the change; WebhookProcessingTest gained the FakeGateway feature path with a 300 bps tenant. CommissionResolver now injects TenantContext, the new Tenancy ResolveTenantCommissionConfig Action, and CommissionCalculator; ConfirmPayment is untouched (the seam was built for this in 8a). The pre-existing zero-commission tests keep passing as the default-config case. Evidence: PaymentStateMachineTest 9/9, WebhookProcessingTest 9/9, PaymentScenarioMatrixTest and InitiatePaymentSyncTest 16/16. Commit 1226452.

#### T4: ledger_entries and the entry-set builder (2026-07-11 23:30 -03)

Slice 3 test-first: LedgerEntriesIsolationTest and LedgerEntriesTest (append-only trigger on UPDATE and DELETE, amount > 0 check, duplicate insertOrIgnore no-op through the (source_event_id, account) unique, four balanced payment legs, both refund-policy leg sets, zero-leg omission, unbalanced and currency-mismatch rejection) were written and observed failing before the migration existed. Migration ships the table, checks, indexes, RLS, and the append-only trigger (create or replace, since migrate:fresh drops tables but not functions). New: LedgerAccount, LedgerDirection, LedgerLeg, LedgerEntrySetBuilder, UnbalancedLedgerEntrySetException, LedgerEntry model.

Deviation: the isolation fixture uses two dedicated persistent tenants and per-test row ids instead of the standard TenantFixture seed/clean cycle, because the append-only trigger blocks DELETE for every role and the suite's honest downgraded connection has no TRUNCATE privilege; rows accumulate for the process exactly like activity_log's, and TenantFixture::clean would otherwise fail on the tenants FK. Evidence: LedgerEntriesIsolationTest 6/6, UnscopedTablesSweepTest 2/2, LedgerEntriesTest and CommissionCalculatorTest 26/26.

#### T5: ordered-consumption ordering-key extension (2026-07-11 23:45 -03)

Test-first: three failing tests appended to OrderedConsumptionTest through a new KeyedOrderedTestSubscriber fixture (cross-aggregate deferral on a shared payload key, no blocking across different keys, envelope-aggregate fallback when the payload lacks the field). New KeyedOrderedOutboxSubscriber interface with orderingKeyPayloadPath(); OrderedConsumption::isReady and hasUnprocessedPredecessor accept the optional key path and compare the jsonb payload field with bound parameters; ProcessOutboxDelivery derives the path from the handler. Evidence: OrderedConsumptionTest plus delivery dispatch and redis tests 15/15, OrderedOutboxConsumptionContentionTest 1/1.

#### T6: ledger projection for PaymentConfirmed (2026-07-12 00:05 -03)

Slice 4 test-first: LedgerProjectionTest written and observed failing before the consumer existed. Covers the payment_id ordering key, duplicate delivery producing exactly one four-leg set from row facts (10000/300/250/9450), deferral inside the stability window, the HTTP purchase through FakeGateway ending with four balanced entries after the sweeper re-enqueues the deferred sync delivery (the projection is the first ordered production consumer, so the sync-queue test path exercises defer plus sweep exactly as production would), and a replay rebuild equal row for row on the natural key after truncating the incremental ledger. ProjectLedgerEntries registered in PaymentsServiceProvider for PaymentConfirmed; RefundCompleted joins in T12. Evidence: LedgerProjectionTest 5/5; Payments, Outbox, and Orders filters 467/467.

#### T7: refunds table and payments reservation columns (2026-07-12 00:20 -03)

Test-first: RefundsIsolationTest with RefundFixture written and observed failing before the migration existed (standard two-tenant probes plus platform read). New refunds migration with the (tenant_id, idempotency_key) replay anchor, amount > 0 and commission >= 0 checks, and RLS in the same migration; additive payments migration adds refunded_amount and refunded_commission_amount with bounds CHECKs as defense behind the conditional-UPDATE reservation guard arriving in T8. RefundStatus enum, Refund model, RefundFactory. Evidence: RefundsIsolationTest 5/5, PaymentStateMachineTest and LedgerProjectionTest 14/14.

#### T8: CreateRefund Action and POST /v1/payments/{payment}/refunds (2026-07-12 01:10 -03)

Slice 5, all tests written and observed failing first. Feature (CreateRefundTest, 16 tests): full-refund default with reservation and RefundInitiated, proportional and retained-policy commission, missing key 400, replay 200 with the identical body, body and cross-payment key-reuse mismatches, unknown and cross-tenant payment 404, unconfirmed payment and non-refundable order 409, exceeds-refundable and currency-mismatch and foreign-tickets 422, validation 422, capability denial, MFA denial. Unit (CreateRefundActionTest, 5 tests): RefundInitiated rides the creating transaction and rolls back with it; round-half-up proportional commission at the 33.5 boundary; cap by the un-returned remainder; conditional-UPDATE reservation; full-remaining default. Concurrency: 6 parallel 2000-unit refunds against a 5000 payment admit exactly 2, reservation and refund-row sum both 4000 (assertion made order-independent after a flaky array_count_values key-order comparison, the only flake in three reruns).

Landed: CreateRefund with the savepoint-isolated insert falling back to replay (mirroring InitiatePayment, including its round-3 cross-scope key-reuse rule), six new error codes registered in ErrorCode and its registry test, RefundInitiated event and payload, Payments admin route group (first use of tenancy.admin in this context) with orders.refund capability, MFA enforcement, and RecordActivityAudit; Orders ResolveOrderRefundContext cross-context read; OpenAPI path plus Refund, RefundCreateRequest, and three problem schemas; contract exercisers for all eight documented responses with the refunds cleanup slotted before payments in the contract teardown; TypeScript regenerated.

Deviations: the MFA denial code is the existing mfa_enforcement_required from EnforceMfaCompliance rather than the plan's mfa_required wording (the stage-03 mechanism the plan says to reuse); replays return 200 with the original body per the InitiatePayment precedent rather than the plan's literal "original 201".

Evidence: CreateRefundTest and CreateRefundActionTest 21/21, RefundReservationContentionTest 3 consecutive passes, Contract suite 348/348, ErrorCodeTest 89/89, Pint clean, api-client tsc clean.

#### T10: Orders MarkTicketsRefunded (2026-07-12 01:35 -03)

Test-first (MarkTicketsRefundedTest, observed failing before the Action existed): selection voiding with one TicketRefunded per ticket, null-selection full void, idempotent second pass affecting zero rows and recording nothing, and void-plus-events rolling back together. Each void is its own issued-to-refunded conditional UPDATE. TicketRefundedPayload gained the additive optional refund_id (the class had no producer yet, so no historical shapes exist); OrdersServiceProvider registers the TicketRefunded type, shipping the first producer exactly as the Stage 7 docblocks anticipated.

#### T11: Orders refund transition Actions (2026-07-12 01:35 -03)

The Stage 7 state-machine table test was extended first and observed failing: four new valid arcs (paid to partially_refunded, paid to refunded, partially_refunded to partially_refunded, partially_refunded to refunded) with the invalid-pair generator re-deriving every other ordered pair. MarkOrderPartiallyRefunded and MarkOrderRefunded are single conditional UPDATEs guarded on the multi-from set, checked by affected-row count. The system-design 7.1 diagram gained the two partially_refunded edges in the same change, and Stage 7's "no transition action targets a refund state" pin was updated to assert the amended set (the pin existed precisely to be consciously flipped here). Evidence: OrderStateMachineTest and MarkTicketsRefundedTest 62/62.

### Review rounds

### Decisions and deviations
