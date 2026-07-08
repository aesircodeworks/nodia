# Laravel Modular Monolith with Bounded Contexts

## Context and Problem Statement

The platform spans several distinct subdomains (tenancy, catalog, inventory, orders, payments, check-in) that need clear boundaries without the operational cost of distributed systems. We also want to stay close to Laravel conventions so the framework works for us rather than against us.

## Considered Options

- Modular monolith: one context folder per subdomain under `app/`, Laravel-native internals (Eloquent models, Actions, Jobs, Policies)
- Modular monolith with DDD layering (Domain/Application/Infrastructure, repositories, mapped entities)
- Microservices per subdomain

## Decision Outcome

Chosen option: "Modular monolith with Laravel-native internals", because context boundaries (no cross-context model imports, communication via Actions and domain events) give the isolation that matters, while repositories and entity mapping duplicate what Eloquent already provides. Microservices add distributed-system failure modes with no current scaling evidence.

### Consequences

- Good, because framework conventions reduce ceremony and onboarding cost.
- Good, because the outbox event contracts remain a seam for extracting a context later.
- Bad, because boundary discipline relies on architecture tests instead of compiler-enforced layers.
