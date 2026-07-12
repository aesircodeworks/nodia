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

### Review rounds

### Decisions and deviations
