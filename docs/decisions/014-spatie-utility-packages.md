# Spatie Utility Packages for Commodity Concerns

## Context and Problem Statement

The design includes several commodity concerns (file attachments, audit trail, translated content, API list filtering) that an earlier revision specified as bespoke tables and code. Building these ourselves spends effort where the domain adds no value.

## Considered Options

* Adopt spatie packages: laravel-medialibrary, laravel-activitylog, laravel-translatable, laravel-query-builder
* Bespoke implementations (custom media, audit_log, and translations tables; hand-rolled filtering)

## Decision Outcome

Chosen option: "Adopt the spatie packages", because each is the maintained ecosystem standard for its concern: medialibrary handles branding assets, event images, and ticket PDFs on S3-compatible storage; activitylog replaces the custom audit table; translatable replaces the event translations table with locale-keyed JSON columns; query-builder standardizes filtering, sorting, and includes on admin endpoints. Published migrations are adjusted for UUID keys and non-null `tenant_id` so RLS applies to their tables too.
