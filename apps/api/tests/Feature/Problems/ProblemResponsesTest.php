<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

const PROBLEM_CORRELATION_ID = 'problem-correlation-id';

/**
 * @param  TestResponse<JsonResponse>  $response
 */
function expectProblemDocument(TestResponse $response, int $status, string $code, string $type, array $extraKeys = [], string $schema = 'Problem'): void
{
    $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('X-Correlation-Id', PROBLEM_CORRELATION_ID)
        ->assertMatchesProblemSchema($schema)
        ->assertJsonPath('type', $type)
        ->assertJsonPath('status', $status)
        ->assertJsonPath('code', $code)
        ->assertJsonPath('correlation_id', PROBLEM_CORRELATION_ID);

    expect($response->json('title'))->toBeString()->not->toBeEmpty()
        ->and($response->json('detail'))->toBeString()->not->toBeEmpty()
        ->and(array_keys($response->json()))->toEqualCanonicalizing(
            array_merge(['type', 'title', 'status', 'detail', 'code', 'correlation_id'], $extraKeys),
        );
}

test('an unknown /v1 route renders a 404 request.not_found problem document', function () {
    $response = $this->withHeader('X-Correlation-Id', PROBLEM_CORRELATION_ID)
        ->getJson('/v1/this-route-does-not-exist');

    expectProblemDocument($response, 404, 'request.not_found', '/problems/request-not-found');
});

test('a wrong verb on /v1/health renders a 405 request.method_not_allowed problem document', function () {
    $response = $this->withHeader('X-Correlation-Id', PROBLEM_CORRELATION_ID)
        ->deleteJson('/v1/health');

    expectProblemDocument($response, 405, 'request.method_not_allowed', '/problems/request-method-not-allowed');
});

test('a failed validation renders a 422 request.validation_failed problem document with the errors map', function () {
    Route::post('/v1/__probe/validation', function (Request $request) {
        $request->validate(['name' => ['required', 'string']]);

        return response()->noContent();
    });

    $response = $this->withHeader('X-Correlation-Id', PROBLEM_CORRELATION_ID)
        ->postJson('/v1/__probe/validation', []);

    expectProblemDocument($response, 422, 'request.validation_failed', '/problems/request-validation-failed', ['errors'], 'ValidationProblem');

    expect($response->json('errors'))->toBeArray()->toHaveKey('name')
        ->and($response->json('errors.name'))->toBeList()->not->toBeEmpty()
        ->and($response->json('errors.name.0'))->toBeString();
});

test('an unauthenticated request renders a 401 auth.unauthenticated problem document', function () {
    Route::get('/v1/__probe/authenticated', fn () => response()->noContent())->middleware('auth');

    $response = $this->withHeader('X-Correlation-Id', PROBLEM_CORRELATION_ID)
        ->getJson('/v1/__probe/authenticated');

    expectProblemDocument($response, 401, 'auth.unauthenticated', '/problems/auth-unauthenticated');
});

test('a denying gate renders a 403 auth.forbidden problem document', function () {
    Gate::define('problem-probe-denied', fn ($user = null) => false);

    Route::get('/v1/__probe/forbidden', function () {
        Gate::authorize('problem-probe-denied');

        return response()->noContent();
    });

    $response = $this->withHeader('X-Correlation-Id', PROBLEM_CORRELATION_ID)
        ->getJson('/v1/__probe/forbidden');

    expectProblemDocument($response, 403, 'auth.forbidden', '/problems/auth-forbidden');
});

test('a throttled request renders a 429 request.rate_limited problem document with Retry-After', function () {
    Route::get('/v1/__probe/throttled', fn () => response()->noContent())->middleware('throttle:1,1');

    $this->getJson('/v1/__probe/throttled')->assertNoContent();

    $response = $this->withHeader('X-Correlation-Id', PROBLEM_CORRELATION_ID)
        ->getJson('/v1/__probe/throttled');

    expectProblemDocument($response, 429, 'request.rate_limited', '/problems/request-rate-limited');

    expect($response->headers->has('Retry-After'))->toBeTrue();
});

test('an unhandled exception renders a 500 server.internal_error problem document leaking nothing with debug off', function () {
    config()->set('app.debug', false);

    Route::get('/v1/__probe/throwing', function () {
        throw new RuntimeException('sensitive internal failure detail');
    });

    $response = $this->withHeader('X-Correlation-Id', PROBLEM_CORRELATION_ID)
        ->getJson('/v1/__probe/throwing');

    expectProblemDocument($response, 500, 'server.internal_error', '/problems/server-internal-error');

    expect($response->getContent())
        ->not->toContain('sensitive internal failure detail')
        ->not->toContain('RuntimeException')
        ->not->toContain('trace');
});
