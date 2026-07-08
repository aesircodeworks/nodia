# Separate Check-in PWA

## Context and Problem Statement

Check-in happens at venue doors with unreliable connectivity, on shared devices, by staff who need exactly one workflow: scan, validate, next. Embedding this in the admin portal couples an offline-first, kiosk-style tool to a large online dashboard, and a native mobile app is planned.

## Considered Options

- Dedicated offline-first PWA (`apps/checkin`), future native mobile app against the same API
- Check-in screens inside the admin portal

## Decision Outcome

Chosen option: "Dedicated offline-first PWA", because the check-in client is fully static and service-worker-first (manifest sync, local QR validation, IndexedDB scan queue), which the SSR admin portal cannot cleanly provide, and a separate app keeps the door workflow minimal on low-end devices. The Check-in context's manifest and sync endpoints are client-agnostic, so the future mobile app reuses them unchanged.
