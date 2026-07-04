# Implementation Plan: [FEATURE]

**Branch**: `[###-feature-name]` | **Date**: [DATE] | **Spec**: [link]

**Input**: Feature specification from `/specs/[###-feature-name]/spec.md`

**Note**: This template is filled in by the `/speckit-plan` command. See `.specify/templates/plan-template.md` for the execution workflow.

## Summary

[Extract from feature spec: primary requirement + technical approach from research]

## Technical Context

<!--
  ACTION REQUIRED: Replace the content in this section with the technical details
  for the project. The structure here is presented in advisory capacity to guide
  the iteration process.
-->

**Language/Version**: [e.g., Python 3.11, Swift 5.9, Rust 1.75 or NEEDS CLARIFICATION]

**Primary Dependencies**: [e.g., FastAPI, UIKit, LLVM or NEEDS CLARIFICATION]

**Storage**: [if applicable, e.g., PostgreSQL, CoreData, files or N/A]

**Testing**: [e.g., pytest, XCTest, cargo test or NEEDS CLARIFICATION]

**Target Platform**: [e.g., Linux server, iOS 15+, WASM or NEEDS CLARIFICATION]

**Project Type**: [e.g., library/cli/web-service/mobile-app/compiler/desktop-app or NEEDS CLARIFICATION]

**Performance Goals**: [domain-specific, e.g., 1000 req/s, 10k lines/sec, 60 fps or NEEDS CLARIFICATION]

**Constraints**: [domain-specific, e.g., <200ms p95, <100MB memory, offline-capable or NEEDS CLARIFICATION]

**Scale/Scope**: [domain-specific, e.g., 10k users, 1M LOC, 50 screens or NEEDS CLARIFICATION]

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design. Gates derive from `.specify/memory/constitution.md`; cite the binding convention docs (api, data, event, ui) instead of restating their rules.*

- [ ] **Tenant isolation (I)**: every new tenant-scoped table has non-null `tenant_id` and its RLS policy in the creating migration; no tenant state in static/singleton state
- [ ] **Inventory invariants (II)**: invariant-guarding transitions are atomic conditional UPDATEs checked by affected-row count; no read-then-write on these paths; Redis display-only
- [ ] **Money (III)**: integer minor units paired with currency on the same row; ledger append-only; clients never compute totals
- [ ] **Events (IV)**: domain events recorded via the outbox in the same transaction as the state change; additive-only payload evolution; consumers idempotent by event ID
- [ ] **Context boundaries (V)**: cross-context interaction only through Actions and domain events; no foreign Models or table access
- [ ] **Contracts (VI)**: shapes defined as laravel-data objects; TypeScript generated into `packages/api-client`; OpenAPI contract merged with the endpoint
- [ ] **Framework-native (VII)**: no repository pattern or DDD layering inside contexts
- [ ] **Security floor (VIII)**: no card data; UUIDv7 only; capability-based authorization; activity log on staff/cross-tenant/financial actions; PII bounded and anonymizable
- [ ] **Fail fast / retry (IX)**: interactive paths never wait on async work; sweeper backstop for every async loss mode; idempotency keys on payment/refund creation
- [ ] **Testing gates (X)**: architecture, tenant isolation, and concurrency suites cover the feature; invariant-bearing code planned test-first
- [ ] **Cross-cutting**: OpenTelemetry with correlation ID propagation; i18n (no string literals) and WCAG 2.1 AA; additive migrations; Pint and Larastan pass

## Project Structure

### Documentation (this feature)

```text
specs/[###-feature]/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)
<!--
  ACTION REQUIRED: Replace the placeholder tree below with the concrete layout
  for this feature. Delete unused options and expand the chosen structure with
  real paths (e.g., apps/admin, packages/something). The delivered plan must
  not include Option labels.
-->

```text
# [REMOVE IF UNUSED] Option 1: Single project (DEFAULT)
src/
├── models/
├── services/
├── cli/
└── lib/

tests/
├── contract/
├── integration/
└── unit/

# [REMOVE IF UNUSED] Option 2: Web application (when "frontend" + "backend" detected)
backend/
├── src/
│   ├── models/
│   ├── services/
│   └── api/
└── tests/

frontend/
├── src/
│   ├── components/
│   ├── pages/
│   └── services/
└── tests/

# [REMOVE IF UNUSED] Option 3: Mobile + API (when "iOS/Android" detected)
api/
└── [same as backend above]

ios/ or android/
└── [platform-specific structure: feature modules, UI flows, platform tests]
```

**Structure Decision**: [Document the selected structure and reference the real
directories captured above]

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| [e.g., 4th project] | [current need] | [why 3 projects insufficient] |
| [e.g., Repository pattern] | [specific problem] | [why direct DB access insufficient] |
