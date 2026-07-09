<?php

it('finds the OpenAPI document that the contract suite will validate', function () {
    $specPath = base_path('../../docs/openapi/openapi.yaml');

    expect(file_exists($specPath))->toBeTrue();

    $spec = file_get_contents($specPath);

    expect($spec)->not->toBeFalse()
        ->and($spec)->toContain('openapi: 3.1');
});
