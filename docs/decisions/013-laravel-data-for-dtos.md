# laravel-data for DTOs and API Contracts

## Context and Problem Statement

Data crossing context and API boundaries needs typed, validated objects, and the TypeScript frontends need matching types without hand-maintained duplication.

## Considered Options

* spatie/laravel-data
* Valinor
* Plain arrays with form requests only

## Decision Outcome

Chosen option: "spatie/laravel-data", because one class defines the shape, validation, and transformation of a payload, doubles as the API resource, and exports TypeScript types via spatie/typescript-transformer into the shared frontend package. Valinor has stricter mapping semantics but no Laravel integration, so every request, resource, and TypeScript bridge would be hand-written glue.
