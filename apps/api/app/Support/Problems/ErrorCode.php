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
    case InvalidQueryParameter = 'invalid_query_parameter';
    case TenantNotFound = 'tenant_not_found';
    case DefaultLocaleNotSupported = 'default_locale_not_supported';
    case TenantDomainNotFound = 'tenant_domain_not_found';
    case DomainAlreadyRegistered = 'domain_already_registered';
    case TenantDomainIsPrimary = 'tenant_domain_is_primary';
    case UnknownHost = 'unknown_host';
    case MissingTenantHeader = 'missing_tenant_header';
    case InvalidTenantHeader = 'invalid_tenant_header';
    case TenantAccessDenied = 'tenant_access_denied';
    case UnknownDomain = 'unknown_domain';
    case InvalidCredentials = 'invalid_credentials';
    case InvalidRefreshToken = 'invalid_refresh_token';
    case RefreshTokenReused = 'refresh_token_reused';
    case MissingCapability = 'missing_capability';
    case RoleNotEditable = 'role_not_editable';
    case RoleInUse = 'role_in_use';
    case RoleNameTaken = 'role_name_taken';
    case UnknownCapability = 'unknown_capability';

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
            self::InvalidQueryParameter => 400,
            self::TenantNotFound => 404,
            self::DefaultLocaleNotSupported => 422,
            self::TenantDomainNotFound => 404,
            self::DomainAlreadyRegistered => 409,
            self::TenantDomainIsPrimary => 409,
            self::UnknownHost => 404,
            self::MissingTenantHeader => 400,
            self::InvalidTenantHeader => 400,
            self::TenantAccessDenied => 403,
            self::UnknownDomain => 404,
            self::InvalidCredentials => 401,
            self::InvalidRefreshToken => 401,
            self::RefreshTokenReused => 401,
            self::MissingCapability => 403,
            self::RoleNotEditable => 409,
            self::RoleInUse => 409,
            self::RoleNameTaken => 409,
            self::UnknownCapability => 422,
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
            self::InvalidQueryParameter => 'Invalid query parameter',
            self::TenantNotFound => 'Tenant not found',
            self::DefaultLocaleNotSupported => 'Default locale not supported',
            self::TenantDomainNotFound => 'Tenant domain not found',
            self::DomainAlreadyRegistered => 'Domain already registered',
            self::TenantDomainIsPrimary => 'Tenant domain is primary',
            self::UnknownHost => 'Unknown host',
            self::MissingTenantHeader => 'Missing tenant header',
            self::InvalidTenantHeader => 'Invalid tenant header',
            self::TenantAccessDenied => 'Tenant access denied',
            self::UnknownDomain => 'Unknown domain',
            self::InvalidCredentials => 'Invalid credentials',
            self::InvalidRefreshToken => 'Invalid refresh token',
            self::RefreshTokenReused => 'Refresh token reused',
            self::MissingCapability => 'Missing capability',
            self::RoleNotEditable => 'Role not editable',
            self::RoleInUse => 'Role in use',
            self::RoleNameTaken => 'Role name taken',
            self::UnknownCapability => 'Unknown capability',
        };
    }

    public function type(): string
    {
        return '/problems/'.str_replace(['.', '_'], '-', $this->value);
    }
}
