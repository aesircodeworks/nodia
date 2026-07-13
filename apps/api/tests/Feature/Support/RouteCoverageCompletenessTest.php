<?php

declare(strict_types=1);

use App\Http\Middleware\RequireCapability;
use App\Support\Testing\RouteAuthorizationCoverage;
use App\Support\Testing\RouteIsolationCoverage;
use Illuminate\Support\Facades\Route;
use Tests\Support\OpenApiSpec;

/*
 * Stage-12 plan, Slice 6 / task breakdown item 14: coverage completeness
 * meta-tests. Each enumerates the live route table (Illuminate\Routing's
 * own registry, not a hand-copied list) so a route added after this file
 * is written is checked automatically, and requires every route to land
 * in exactly one of a coverage map or a reasoned exemption list
 * (system-design 14.1, 14.2). A route in neither fails the build; that is
 * the point, not an edge case: it is what makes a newly shipped,
 * accidentally uncovered route impossible to merge silently.
 *
 * Proven red against a seeded gap during development (recorded in
 * docs/plans/execution/stage-12-hardening.md, task T14): a synthetic route
 * registered with none of the three coverage maps updated failed all
 * three tests below with the exact "no coverage entry" message; removing
 * the synthetic route without touching the maps restored green.
 */

const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

/**
 * @return list<string>
 */
function registeredV1Routes(): array
{
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'v1/')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $routes[$method.' /'.$uri] = true;
        }
    }

    return array_keys($routes);
}

/**
 * @return list<string>
 */
function registeredMutatingV1Routes(): array
{
    return array_values(array_filter(
        registeredV1Routes(),
        fn (string $route): bool => in_array(strtok($route, ' '), MUTATING_METHODS, true),
    ));
}

it('maps every registered /v1 route to isolation coverage or a reasoned exemption', function (): void {
    $covered = RouteIsolationCoverage::covered();
    $exempt = RouteIsolationCoverage::exempt();

    expect(array_intersect_key($covered, $exempt))->toBe([]);

    foreach (registeredV1Routes() as $route) {
        expect(isset($covered[$route]) || isset($exempt[$route]))->toBeTrue(
            "Route [{$route}] has no isolation coverage entry and no exemption; add one to App\\Support\\Testing\\RouteIsolationCoverage.",
        );
    }
});

it('maps every registered /v1 route to an OpenAPI path', function (): void {
    $documented = [];

    foreach (OpenApiSpec::documentedOperations() as $operation) {
        [$method, $path] = explode(' ', $operation, 2);
        $documented[strtoupper($method).' '.$path] = true;
    }

    foreach (registeredV1Routes() as $route) {
        expect(isset($documented[$route]))->toBeTrue(
            "Route [{$route}] has no OpenAPI path; document it in docs/openapi/openapi.yaml.",
        );
    }
});

it('maps every mutating /v1 route to a capability in the authorization registry or a reasoned exemption', function (): void {
    $covered = RouteAuthorizationCoverage::covered();
    $exempt = RouteAuthorizationCoverage::exempt();

    expect(array_intersect_key($covered, $exempt))->toBe([]);

    foreach (registeredMutatingV1Routes() as $route) {
        expect(isset($covered[$route]) || isset($exempt[$route]))->toBeTrue(
            "Mutating route [{$route}] has no authorization coverage entry and no exemption; add one to App\\Support\\Testing\\RouteAuthorizationCoverage.",
        );
    }
});

it('keeps every statically gated authorization registry entry in sync with its RequireCapability middleware', function (): void {
    $router = app('router');
    $covered = RouteAuthorizationCoverage::covered();

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'v1/')) {
            continue;
        }

        foreach (array_intersect($route->methods(), MUTATING_METHODS) as $method) {
            $key = $method.' /'.$uri;
            $capability = null;

            foreach ($router->gatherRouteMiddleware($route) as $entry) {
                if (str_starts_with($entry, RequireCapability::class.':')) {
                    $capability = substr($entry, strlen(RequireCapability::class.':'));
                }
            }

            if ($capability === null) {
                // Not statically gated: either a dynamic in-controller
                // check or a reasoned exemption, both proven by the
                // completeness test above.
                continue;
            }

            expect($covered[$key] ?? null)->toBe(
                $capability,
                "Route [{$key}]'s RouteAuthorizationCoverage entry does not match its live RequireCapability middleware value [{$capability}].",
            );
        }
    }
});
