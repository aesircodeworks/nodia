<?php

use App\Support\Problems\ErrorCode;

test('the registry holds exactly the known codes', function () {
    expect(array_map(fn (ErrorCode $code) => $code->value, ErrorCode::cases()))->toBe([
        'request.not_found',
        'request.method_not_allowed',
        'request.validation_failed',
        'auth.unauthenticated',
        'auth.forbidden',
        'request.rate_limited',
        'server.internal_error',
        'health.degraded',
        'invalid_query_parameter',
        'tenant_not_found',
        'default_locale_not_supported',
        'tenant_domain_not_found',
        'domain_already_registered',
        'tenant_domain_is_primary',
        'unknown_host',
        'missing_tenant_header',
        'invalid_tenant_header',
        'tenant_access_denied',
        'unknown_domain',
        'invalid_credentials',
        'invalid_refresh_token',
        'refresh_token_reused',
        'missing_capability',
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
    'invalid_query_parameter' => [ErrorCode::InvalidQueryParameter, 400, 'Invalid query parameter', '/problems/invalid-query-parameter'],
    'tenant_not_found' => [ErrorCode::TenantNotFound, 404, 'Tenant not found', '/problems/tenant-not-found'],
    'default_locale_not_supported' => [ErrorCode::DefaultLocaleNotSupported, 422, 'Default locale not supported', '/problems/default-locale-not-supported'],
    'tenant_domain_not_found' => [ErrorCode::TenantDomainNotFound, 404, 'Tenant domain not found', '/problems/tenant-domain-not-found'],
    'domain_already_registered' => [ErrorCode::DomainAlreadyRegistered, 409, 'Domain already registered', '/problems/domain-already-registered'],
    'tenant_domain_is_primary' => [ErrorCode::TenantDomainIsPrimary, 409, 'Tenant domain is primary', '/problems/tenant-domain-is-primary'],
    'unknown_host' => [ErrorCode::UnknownHost, 404, 'Unknown host', '/problems/unknown-host'],
    'missing_tenant_header' => [ErrorCode::MissingTenantHeader, 400, 'Missing tenant header', '/problems/missing-tenant-header'],
    'invalid_tenant_header' => [ErrorCode::InvalidTenantHeader, 400, 'Invalid tenant header', '/problems/invalid-tenant-header'],
    'tenant_access_denied' => [ErrorCode::TenantAccessDenied, 403, 'Tenant access denied', '/problems/tenant-access-denied'],
    'unknown_domain' => [ErrorCode::UnknownDomain, 404, 'Unknown domain', '/problems/unknown-domain'],
    'invalid_credentials' => [ErrorCode::InvalidCredentials, 401, 'Invalid credentials', '/problems/invalid-credentials'],
    'invalid_refresh_token' => [ErrorCode::InvalidRefreshToken, 401, 'Invalid refresh token', '/problems/invalid-refresh-token'],
    'refresh_token_reused' => [ErrorCode::RefreshTokenReused, 401, 'Refresh token reused', '/problems/refresh-token-reused'],
    'missing_capability' => [ErrorCode::MissingCapability, 403, 'Missing capability', '/problems/missing-capability'],
]);

test('the type slug is derived mechanically from the code, dots and underscores to dashes', function () {
    foreach (ErrorCode::cases() as $code) {
        expect($code->type())->toBe('/problems/'.str_replace(['.', '_'], '-', $code->value));
    }
});
