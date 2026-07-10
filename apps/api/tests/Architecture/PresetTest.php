<?php

use App\Identity\Capability;
use App\Identity\Enums\MembershipAccessOutcome;
use App\Identity\Enums\MembershipScope;
use App\Identity\Exceptions\InvalidCredentialsException;
use App\Identity\Exceptions\InvalidMembershipScopeException;
use App\Identity\Exceptions\InvalidRefreshTokenException;
use App\Identity\Exceptions\MissingCapabilityException;
use App\Identity\Exceptions\RefreshTokenReusedException;
use App\Identity\Exceptions\RoleInUseException;
use App\Identity\Exceptions\RoleNameTakenException;
use App\Identity\Exceptions\RoleNotEditableException;
use App\Identity\Exceptions\RoleNotFoundException;
use App\Identity\Exceptions\UnknownCapabilityException;
use App\Identity\IdentityServiceProvider;
use App\Support\Money\CurrencyMismatchException;
use App\Support\Problems\ErrorCode;
use App\Support\Tenancy\InvalidTenantIdException;
use App\Tenancy\Exceptions\DefaultLocaleNotSupportedException;
use App\Tenancy\Exceptions\DomainAlreadyRegisteredException;
use App\Tenancy\Exceptions\InvalidDomainNameException;
use App\Tenancy\Exceptions\InvalidGatewayConfigurationException;
use App\Tenancy\Exceptions\InvalidTenantHeaderException;
use App\Tenancy\Exceptions\MissingTenantHeaderException;
use App\Tenancy\Exceptions\TenantAccessDeniedException;
use App\Tenancy\Exceptions\TenantDomainIsPrimaryException;
use App\Tenancy\Exceptions\TenantDomainNotFoundException;
use App\Tenancy\Exceptions\TenantNotFoundException;
use App\Tenancy\Exceptions\UnknownDomainException;
use App\Tenancy\Exceptions\UnknownHostException;
use App\Tenancy\Http\Middleware\RenderedErrorRollback;
use App\Tenancy\TenancyServiceProvider;

arch()->preset()->php();
arch()->preset()->security();

// The error code registry lives in App\Support\Problems per the Stage 1 plan,
// not in App\Enums where the preset expects enums, and guard exceptions live
// with the value objects and wrappers they protect (App\Support\Money per
// ADR 018, App\Support\Tenancy) or in their bounded context's Exceptions
// directory, not in App\Exceptions where the preset expects Throwables.
// Eloquent models and controllers live in each bounded context's Models and
// Http\Controllers directories (system-design 8 and 3.2,
// ContextBoundariesTest), not in App\Models and App\Http\Controllers where
// the preset expects them, and each context ships its own service provider
// at the context root (system-design 3.2), not in App\Providers. Capability
// and MembershipScope are enums the preset expects only under App\Enums
// (stage-03 plan, Data model); they live with the bounded context whose
// registry they are instead, like ErrorCode lives with the problem-document
// renderer rather than App\Enums. MembershipAccessOutcome (stage-03
// task-05) is the same story: Identity's own internal resolution result,
// never part of the wire contract, so it stays with the Action that
// returns it.
arch()->preset()->laravel()->ignoring([
    ErrorCode::class,
    Capability::class,
    MembershipScope::class,
    MembershipAccessOutcome::class,
    CurrencyMismatchException::class,
    InvalidTenantIdException::class,
    InvalidCredentialsException::class,
    InvalidMembershipScopeException::class,
    InvalidRefreshTokenException::class,
    RefreshTokenReusedException::class,
    MissingCapabilityException::class,
    RoleNotEditableException::class,
    RoleInUseException::class,
    RoleNameTakenException::class,
    RoleNotFoundException::class,
    UnknownCapabilityException::class,
    DefaultLocaleNotSupportedException::class,
    DomainAlreadyRegisteredException::class,
    InvalidDomainNameException::class,
    InvalidGatewayConfigurationException::class,
    TenantDomainIsPrimaryException::class,
    TenantDomainNotFoundException::class,
    TenantNotFoundException::class,
    UnknownDomainException::class,
    UnknownHostException::class,
    MissingTenantHeaderException::class,
    InvalidTenantHeaderException::class,
    TenantAccessDeniedException::class,
    RenderedErrorRollback::class,
    TenancyServiceProvider::class,
    IdentityServiceProvider::class,
    'App\Tenancy\Models',
    'App\Tenancy\Http\Controllers',
    'App\Identity\Models',
    'App\Identity\Http\Controllers',
]);
