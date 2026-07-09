<?php

use App\Support\Problems\ErrorCode;

test('the registry holds exactly the initial eight codes', function () {
    expect(array_map(fn (ErrorCode $code) => $code->value, ErrorCode::cases()))->toBe([
        'request.not_found',
        'request.method_not_allowed',
        'request.validation_failed',
        'auth.unauthenticated',
        'auth.forbidden',
        'request.rate_limited',
        'server.internal_error',
        'health.degraded',
    ]);
});

test('every error code maps to its status, title, and type slug', function (ErrorCode $code, int $status, string $title, string $type) {
    expect($code->status())->toBe($status)
        ->and($code->title())->toBe($title)
        ->and($code->type())->toBe($type);
})->with([
    'request.not_found' => [ErrorCode::RequestNotFound, 404, 'Not found', '/problems/request-not-found'],
    'request.method_not_allowed' => [ErrorCode::RequestMethodNotAllowed, 405, 'Method not allowed', '/problems/request-method-not-allowed'],
    'request.validation_failed' => [ErrorCode::RequestValidationFailed, 422, 'Validation failed', '/problems/request-validation-failed'],
    'auth.unauthenticated' => [ErrorCode::AuthUnauthenticated, 401, 'Unauthenticated', '/problems/auth-unauthenticated'],
    'auth.forbidden' => [ErrorCode::AuthForbidden, 403, 'Forbidden', '/problems/auth-forbidden'],
    'request.rate_limited' => [ErrorCode::RequestRateLimited, 429, 'Too many requests', '/problems/request-rate-limited'],
    'server.internal_error' => [ErrorCode::ServerInternalError, 500, 'Internal server error', '/problems/server-internal-error'],
    'health.degraded' => [ErrorCode::HealthDegraded, 503, 'Service degraded', '/problems/health-degraded'],
]);

test('the type slug is derived mechanically from the code, dots and underscores to dashes', function () {
    foreach (ErrorCode::cases() as $code) {
        expect($code->type())->toBe('/problems/'.str_replace(['.', '_'], '-', $code->value));
    }
});
