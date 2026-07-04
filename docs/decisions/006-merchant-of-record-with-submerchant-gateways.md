# Merchant of Record with Sub-Merchant Gateways

## Context and Problem Statement

Money must flow from buyers through the platform to organizers with correct fee splits, payouts, and refund liability, without pulling the platform into card-data (PCI) or KYC document handling.

## Considered Options

* Platform as merchant of record using gateways with marketplace/split-payment support (Stripe Connect, Adyen for Platforms, Pagar.me, Mercado Pago)
* Organizers as merchants using their own gateway accounts, platform invoices commission separately

## Decision Outcome

Chosen option: "Platform as merchant of record with sub-merchant gateways", because it gives the platform control of the checkout experience, automatic commission splits, and gateway-managed KYC and payouts, while gateway-hosted fields keep PCI scope at SAQ-A.

### Consequences

* Good, because organizer onboarding, payouts, and splits are delegated to the gateway.
* Bad, because the platform carries refund and chargeback liability and must maintain an append-only ledger as the source of truth for balances.
