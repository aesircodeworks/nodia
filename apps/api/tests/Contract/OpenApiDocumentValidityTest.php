<?php

use Tests\Support\OpenApiSpec;

test('docs/openapi/openapi.yaml declares OpenAPI 3.1', function () {
    expect(OpenApiSpec::document()->openapi)->toMatch('/^3\.1\.\d+$/');
});

test('docs/openapi/openapi.yaml validates against the official OpenAPI 3.1 meta-schema', function () {
    expect(OpenApiSpec::documentErrors())->toBe([]);
});
