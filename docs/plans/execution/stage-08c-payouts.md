# Stage 8c Execution Journal: Payouts and Sub-merchant Onboarding

## Run: 2026-07-12

- Stage: 8c (docs/plans/stage-08c-payouts.md)
- Branch: feat/api-implementation
- Base commit: 675871a5b9552a9e55213db4062fcdb7cc96de1a

### Task checklist

- [ ] T1 payments: extend GatewayAdapter with sub-merchant and payout operations, wire split-support flag, FakeGateway scenarios and webhook emitters (slice 1)
- [ ] T2 payments: submerchant_accounts migration with RLS, SubmerchantAccount model, SubmerchantStatus enum, factory, isolation tests (slice 2)
- [ ] T3 identity: register payouts.manage capability, financially privileged, template role wiring
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
