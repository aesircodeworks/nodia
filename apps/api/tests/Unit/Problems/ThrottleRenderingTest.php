<?php

use App\Support\Problems\ProblemRenderer;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

/*
 * Stage-10 plan, TDD sequencing Slice 2 (Unit, first): the Stage 1
 * problem-document handler already renders throttle exceptions as
 * request.rate_limited with Retry-After (proven on a throttled probe
 * route by tests/Feature/Problems/ProblemResponsesTest.php); this test
 * asserts the same rendering directly against ProblemRenderer, so the
 * on-sale rate limiter tiers can rely on it without reimplementing
 * anything (stage-10 plan, Endpoints "Rate limiting tiers").
 */

test('a ThrottleRequestsException renders as a 429 request.rate_limited problem document carrying Retry-After', function () {
    $request = Request::create('/v1/storefront/holds', 'POST');
    $exception = new ThrottleRequestsException('Too Many Attempts.', null, [
        'Retry-After' => '30',
        'X-RateLimit-Limit' => '10',
        'X-RateLimit-Remaining' => '0',
    ]);

    $response = app(ProblemRenderer::class)->render($exception, $request);

    expect($response)->not->toBeNull();
    expect($response->getStatusCode())->toBe(429);
    expect($response->headers->get('Content-Type'))->toBe('application/problem+json');
    expect($response->headers->get('Retry-After'))->toBe('30');

    expect($response->getData(true))->toMatchArray([
        'type' => '/problems/request-rate-limited',
        'status' => 429,
        'code' => 'request.rate_limited',
    ]);
});

test('ProblemRenderer ignores non-/v1 requests, leaving the throttle exception unrendered', function () {
    $request = Request::create('/up', 'GET');
    $exception = new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => '30']);

    expect(app(ProblemRenderer::class)->render($exception, $request))->toBeNull();
});
