# Tenant-Selected Gateways Without Region Restriction

## Context and Problem Statement

An earlier revision routed payments by tenant region (BR, US, EU), with each region defining which gateway adapters a tenant could enable. This added a routing layer and a `region` attribute whose only job was to restrict choice.

## Considered Options

- Tenants enable any gateway the platform provides an adapter for
- Region-based gateway routing

## Decision Outcome

Chosen option: "Tenants enable any available gateway", because the real constraints (supported payment methods, supported currencies, split-payment support) are already expressed as capability flags on each adapter, so a region layer restricts without adding safety. A tenant simply cannot be offered a method its enabled gateways or currency do not support.

### Consequences

- Good, because the tenant model loses a `region` attribute and the routing rules disappear.
- Bad, because nothing prevents a tenant from enabling a gateway that is a poor fit commercially; that becomes an onboarding-guidance concern.
