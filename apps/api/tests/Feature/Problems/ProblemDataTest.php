<?php

use App\Support\Problems\ErrorCode;
use App\Support\Problems\ProblemData;
use App\Support\Problems\ValidationProblemData;

test('ProblemData serializes to the exact RFC 9457 snake_case wire shape', function () {
    $problem = ProblemData::fromErrorCode(
        ErrorCode::RequestNotFound,
        'The requested resource does not exist.',
        '0197c9a2-0000-7000-8000-000000000001',
    );

    expect($problem->toArray())->toBe([
        'type' => '/problems/request-not-found',
        'title' => 'Not found',
        'status' => 404,
        'detail' => 'The requested resource does not exist.',
        'code' => 'request.not_found',
        'correlation_id' => '0197c9a2-0000-7000-8000-000000000001',
    ]);
});

test('ValidationProblemData serializes to the problem shape extended with the errors map', function () {
    $problem = ValidationProblemData::fromErrors(
        ['name' => ['The name field is required.'], 'email' => ['The email field is required.', 'The email field must be a valid email address.']],
        'The request payload failed validation.',
        '0197c9a2-0000-7000-8000-000000000002',
    );

    expect($problem->toArray())->toEqual([
        'type' => '/problems/request-validation-failed',
        'title' => 'Validation failed',
        'status' => 422,
        'detail' => 'The request payload failed validation.',
        'code' => 'request.validation_failed',
        'correlation_id' => '0197c9a2-0000-7000-8000-000000000002',
        'errors' => [
            'name' => ['The name field is required.'],
            'email' => ['The email field is required.', 'The email field must be a valid email address.'],
        ],
    ]);
});

test('a problem built without a correlation id omits the extension member instead of sending null', function () {
    $problem = ProblemData::fromErrorCode(ErrorCode::HealthDegraded, 'One or more backing services failed their health check.');

    expect($problem->toArray())->toBe([
        'type' => '/problems/health-degraded',
        'title' => 'Service degraded',
        'status' => 503,
        'detail' => 'One or more backing services failed their health check.',
        'code' => 'health.degraded',
    ]);
});
