# Spectator for OpenAPI Conformance Testing

## Context and Problem Statement

The contract pipeline needs every feature test to assert its HTTP response conforms to the hand-maintained `docs/openapi/openapi.yaml` (an OpenAPI 3.1.0 document) through one macro, and CI needs a gate that catches shape drift between the laravel-data classes and the YAML, not only mismatches in whatever responses tests happen to record. The master plan directs that if no response validator can catch that drift, the pipeline switches to generating the OpenAPI document from the Data classes and gating on a generated-versus-committed diff. Candidates were evaluated against current documentation and registry data on 2026-07-09; the API targets PHP 8.4 and Laravel 13.

## Considered Options

- hotmeteor/spectator
- osteel/openapi-httpfoundation-testing
- league/openapi-psr7-validator behind a bespoke macro
- Generating the OpenAPI document from the Data classes (dedoc/scramble, xolvionl/laravel-data-openapi-generator, basillangevin/laravel-data-json-schemas, or a bespoke generator)

## Decision Outcome

Chosen option: "hotmeteor/spectator" for response conformance, with the Data-class drift criterion met by schema strictness rules and a Contract suite gate rather than by generating the spec.

Findings at spike time (2026-07-09):

- hotmeteor/spectator v3.0.2 (released 2026-05-01) is actively maintained, requires PHP ^8.3 and Laravel >=12, and has supported OpenAPI 3.1 since its 2.0 rewrite (validation through cebe/php-openapi plus opis/json-schema). It adds `assertValidRequest` and `assertValidResponse` directly to Laravel's `TestResponse`, so the one-macro criterion is met by a thin `assertConformsToOpenApi` wrapper in the feature test base.
- league/openapi-psr7-validator 0.24 (released 2026-05-08) is actively maintained but validates OpenAPI 3.0.x only, parsing through the devizzent/cebe-php-openapi fork. The repository spec is 3.1.0, which disqualifies it, and osteel/openapi-httpfoundation-testing v0.14 (released 2025-12-04, pre-1.0 with a documented breaking-change caveat) with it, since it delegates validation to that library.
- No candidate satisfies the drift criterion by itself: all three validate recorded responses, so drift in a shape no test exercises escapes.
- The directed fallback, generating the document from the Data classes, has no viable maintained tooling today: dedoc/scramble generates OpenAPI 3.1 but its laravel-data support is a paid Scramble PRO feature; xolvionl/laravel-data-openapi-generator has been unmaintained since mid-2024 and is not published on Packagist; basillangevin/laravel-data-json-schemas (v1.3.1, active) emits JSON Schema 2019-09 for individual Data classes, not an OpenAPI document, and diffing its output against hand-authored 3.1 schemas would be brittle. A bespoke generator is real engineering this stage does not fund.

Because laravel-data objects are the only response serialization path (ADR 013), drift between a Data class and the YAML always materializes in the response of the endpoints that serialize it. The gate therefore closes the recorded-response gap with two enforced conventions instead of generation:

- Strict component schemas: every response schema declares `additionalProperties: false` and lists every always-present field in `required`, so a Data class emitting an extra, missing, or retyped field fails conformance in that endpoint's feature test. A Contract suite test walks the spec's response schemas and fails any that are not strict, so conformance assertions cannot pass vacuously.
- Coverage checks in the Contract suite: the spec must be a valid 3.1 document, every registered `/v1` route must have a spec path and every spec path a route, and every documented response of an operation must be exercised by a conformance-asserted feature test, so drift cannot hide in an undocumented route or an untested documented response.

Residual risk, accepted: within a single operation, optional-field variants and union branches are only as covered as the tests that exercise them. Revisit generation if a maintained laravel-data OpenAPI generator appears or a Scramble PRO license is approved; until then the YAML stays the hand-maintained, reviewed contract.
