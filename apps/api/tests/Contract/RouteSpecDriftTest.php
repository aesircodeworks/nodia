<?php

use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Assert;
use Tests\Support\OpenApiSpec;

/**
 * Probe routes are registered inside the tests that use them and never reach
 * the app's route table, so these checks need no exclusion list.
 *
 * @return list<string>
 */
function registeredV1Operations(): array
{
    $operations = [];

    /** @var RegisteredRoute $route */
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'v1/')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $operations[] = strtolower($method).' /'.$route->uri();
        }
    }

    sort($operations);

    return $operations;
}

test('every /v1 route registered in the app has a matching operation in the spec', function () {
    $documented = OpenApiSpec::documentedOperations();

    foreach (registeredV1Operations() as $operation) {
        Assert::assertContains(
            $operation,
            $documented,
            "Route [{$operation}] is registered but has no operation in docs/openapi/openapi.yaml.",
        );
    }
});

test('every operation in the spec has a matching registered /v1 route', function () {
    $registered = registeredV1Operations();

    foreach (OpenApiSpec::documentedOperations() as $operation) {
        Assert::assertContains(
            $operation,
            $registered,
            "Operation [{$operation}] is documented in docs/openapi/openapi.yaml but no matching route is registered.",
        );
    }
});
