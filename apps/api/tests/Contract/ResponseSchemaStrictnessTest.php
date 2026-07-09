<?php

use Tests\Support\OpenApiSpec;

/**
 * ADR 019: response conformance assertions only catch Data-class drift when the
 * spec schemas are strict, so every response schema must forbid undeclared
 * properties and require its always-present fields. A schema that satisfies
 * neither branch would let assertConformsToOpenApi pass vacuously.
 */
function assertSchemaIsStrict(stdClass $schema, string $operation): void
{
    $schema = OpenApiSpec::resolveSchema($schema);

    if (($schema->additionalProperties ?? null) === false) {
        expect($schema->required ?? [])->not->toBeEmpty(
            "Response schema for [{$operation}] declares no required fields.",
        );

        return;
    }

    if (($schema->unevaluatedProperties ?? null) === false) {
        $required = $schema->required ?? [];

        foreach ($schema->allOf ?? [] as $branch) {
            $required = [...$required, ...(OpenApiSpec::resolveSchema($branch)->required ?? [])];
        }

        expect($required)->not->toBeEmpty(
            "Response schema for [{$operation}] declares no required fields across its composition.",
        );

        return;
    }

    test()->fail(
        "Response schema for [{$operation}] is not strict: it must declare additionalProperties: false".
        ' or unevaluatedProperties: false so undeclared fields fail conformance.',
    );
}

test('every documented response schema is strict, so conformance assertions catch Data-class drift', function () {
    $schemas = OpenApiSpec::documentedResponseSchemas();

    expect($schemas)->not->toBeEmpty();

    foreach ($schemas as $operation => $schema) {
        assertSchemaIsStrict($schema, $operation);
    }
});
