# Next.js Frontends in a Monorepo

## Context and Problem Statement

The platform needs a buyer-facing storefront (SEO, per-tenant branding on custom domains) and an organizer admin portal, sharing an API contract and a white-label component library with the check-in PWA.

## Considered Options

- React with Next.js apps in a monorepo with the API and shared packages
- Frontend apps in separate repositories
- Server-rendered Blade or Inertia inside the Laravel app

## Decision Outcome

Chosen option: "Next.js apps in a monorepo", because the storefront needs SSR for SEO and per-domain branding, the admin portal benefits from the same stack, and a monorepo lets generated API types and the shared UI package flow to all frontends in one change. Inertia would couple frontend deployment to the API and complicate the multi-domain storefront.
