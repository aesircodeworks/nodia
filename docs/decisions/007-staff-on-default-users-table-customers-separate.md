# Staff on the Default Users Table, Customers Separate

## Context and Problem Statement

The platform has two identity populations: staff (organizer and platform employees, platform-level identity, multi-tenant access) and customers (attendees, tenant-scoped, guest checkout, PII erasure). We must decide how they map onto tables and how far to follow Laravel's default user model.

## Considered Options

- Staff on the default `users` table with a `memberships` join to tenants; customers in a separate tenant-scoped `customers` table
- Dedicated `staff_users` table plus separate `customers` table
- Single `users` table with a type flag for both populations

## Decision Outcome

Chosen option: "Staff on the default `users` table, customers separate", because staff fit the framework's user conventions exactly (Passport, policies, notifications work out of the box), while customers have a different lifecycle (tenant-scoped uniqueness, nullable password for guests, anonymization) that does not belong in the same table. A type flag would force both populations through one schema and one credential space, which white-labeling forbids.
