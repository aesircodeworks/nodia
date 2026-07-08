# Record Architecture Decisions

## Context and Problem Statement

Architectural decisions were embedded in the system design document, where their rationale and rejected alternatives get lost as the document evolves. We need a durable, reviewable log of significant decisions.

## Considered Options

- MADR minimal format in `docs/decisions/`
- MADR full format
- No ADRs, decisions live only in the SDD

## Decision Outcome

Chosen option: "MADR minimal format in `docs/decisions/`", because it captures context, options, and outcome with the least ceremony, keeping the barrier to recording a decision low.
