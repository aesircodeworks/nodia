<?php

namespace App\Support\Problems;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum ErrorCode: string
{
    case RequestNotFound = 'request.not_found';
    case RequestMethodNotAllowed = 'request.method_not_allowed';
    case RequestValidationFailed = 'request.validation_failed';
    case AuthUnauthenticated = 'auth.unauthenticated';
    case AuthForbidden = 'auth.forbidden';
    case RequestRateLimited = 'request.rate_limited';
    case ServerInternalError = 'server.internal_error';
    case HealthDegraded = 'health.degraded';

    public function status(): int
    {
        return match ($this) {
            self::RequestNotFound => 404,
            self::RequestMethodNotAllowed => 405,
            self::RequestValidationFailed => 422,
            self::AuthUnauthenticated => 401,
            self::AuthForbidden => 403,
            self::RequestRateLimited => 429,
            self::ServerInternalError => 500,
            self::HealthDegraded => 503,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::RequestNotFound => 'Not found',
            self::RequestMethodNotAllowed => 'Method not allowed',
            self::RequestValidationFailed => 'Validation failed',
            self::AuthUnauthenticated => 'Unauthenticated',
            self::AuthForbidden => 'Forbidden',
            self::RequestRateLimited => 'Too many requests',
            self::ServerInternalError => 'Internal server error',
            self::HealthDegraded => 'Service degraded',
        };
    }

    public function type(): string
    {
        return '/problems/'.str_replace(['.', '_'], '-', $this->value);
    }
}
