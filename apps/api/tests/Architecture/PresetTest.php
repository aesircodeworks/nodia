<?php

use App\EventCatalog\Enums\EventStatus;
use App\EventCatalog\EventCatalogServiceProvider;
use App\EventCatalog\Exceptions\CurrencyMismatchException as TicketTypeCurrencyMismatchException;
use App\EventCatalog\Exceptions\EventImmutableException;
use App\EventCatalog\Exceptions\EventNotCancelableException;
use App\EventCatalog\Exceptions\EventNotFoundException;
use App\EventCatalog\Exceptions\EventNotPublishableException;
use App\EventCatalog\Exceptions\InvalidEventVenueConfigurationException;
use App\EventCatalog\Exceptions\SeatMapConflictException;
use App\EventCatalog\Exceptions\SeatMapDuplicateSeatsException;
use App\EventCatalog\Exceptions\SeatMapInUseException;
use App\EventCatalog\Exceptions\SeatMapNameTakenException;
use App\EventCatalog\Exceptions\SeatMapNotFoundException;
use App\EventCatalog\Exceptions\SeatMapVenueMismatchException;
use App\EventCatalog\Exceptions\SeatMapVirtualEventException;
use App\EventCatalog\Exceptions\TicketTypeNotFoundException;
use App\EventCatalog\Exceptions\VenueNotFoundException;
use App\Identity\Capability;
use App\Identity\Enums\MembershipAccessOutcome;
use App\Identity\Enums\MembershipScope;
use App\Identity\Exceptions\ClaimTokenExpiredException;
use App\Identity\Exceptions\ClaimTokenInvalidException;
use App\Identity\Exceptions\CustomerAlreadyClaimedException;
use App\Identity\Exceptions\CustomerEmailTakenException;
use App\Identity\Exceptions\InvalidCredentialsException;
use App\Identity\Exceptions\InvalidMembershipScopeException;
use App\Identity\Exceptions\InvalidRefreshTokenException;
use App\Identity\Exceptions\InvitationTokenExpiredException;
use App\Identity\Exceptions\InvitationTokenInvalidException;
use App\Identity\Exceptions\LastOwnerRemovalException;
use App\Identity\Exceptions\MembershipExistsException;
use App\Identity\Exceptions\MembershipNotFoundException;
use App\Identity\Exceptions\MfaAlreadyEnrolledException;
use App\Identity\Exceptions\MfaCodeInvalidException;
use App\Identity\Exceptions\MfaEnforcedForRoleException;
use App\Identity\Exceptions\MfaEnforcementRequiredException;
use App\Identity\Exceptions\MfaNotEnrolledException;
use App\Identity\Exceptions\MfaRequiredException;
use App\Identity\Exceptions\MissingCapabilityException;
use App\Identity\Exceptions\PasswordResetTokenExpiredException;
use App\Identity\Exceptions\PasswordResetTokenInvalidException;
use App\Identity\Exceptions\RefreshTokenReusedException;
use App\Identity\Exceptions\RoleInUseException;
use App\Identity\Exceptions\RoleNameTakenException;
use App\Identity\Exceptions\RoleNotEditableException;
use App\Identity\Exceptions\RoleNotFoundException;
use App\Identity\Exceptions\TenantMismatchException;
use App\Identity\Exceptions\UnknownCapabilityException;
use App\Identity\IdentityServiceProvider;
use App\Identity\Mail\CustomerClaimMail;
use App\Identity\Mail\PasswordResetMail;
use App\Identity\Mail\StaffInvitationMail;
use App\Support\Money\CurrencyMismatchException;
use App\Support\Outbox\Enums\OutboxDeliveryStatus;
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
// returns it. StaffInvitationMail (stage-03 task-09) lives under
// App\Identity\Mail, not the top-level App\Mail the preset expects: that
// namespace also requires every class in it to implement ShouldQueue,
// which would route a plain send() call through the queue instead of
// sending it synchronously (Illuminate\Mail\Mailer::sendMailable()), the
// opposite of the stage plan's "no queue dependency" mandate.
// App\Support\Audit\Models\ActivityLogEntry (stage-03 task-15) is a model
// with no owning bounded context, the audit trail cross-cutting every
// context writes to; it lives under App\Support like every other shared
// primitive (Money, Rls, TenantContext), not App\Models.
// App\Support\Outbox\Models (stage-04 tasks 3 and 6) is the same story for
// the transactional outbox: shared infrastructure every context records
// into (system-design 9.1), not App\Models. OutboxDeliveryStatus is the
// status enum for outbox_deliveries and lives with that infrastructure
// rather than App\Enums (data-conventions; stage-04 plan Data model).
// App\Support\Outbox\Jobs (stage-04 task 7) holds the delivery job next to
// the outbox machinery rather than the top-level App\Jobs the preset
// expects: the job is outbox infrastructure, not a domain action queue.
// EventStatus (stage-05a task-03) is events.status's registry and lives
// with EventCatalog like Capability and MembershipScope live with their
// own contexts, not App\Enums. InvalidEventVenueConfigurationException
// (stage-05a task-03) guards the exactly-one-of-venue-or-url application
// invariant and is not meant to reach the HTTP boundary, mirroring
// InvalidMembershipScopeException's own precedent for the identical
// situation. SeatMapDuplicateSeatsException, SeatMapNameTakenException
// (stage-05b task-02), SeatMapNotFoundException (stage-05b task-03),
// SeatMapConflictException (stage-05b task-04), and SeatMapInUseException
// (stage-05b task-06) live in App\EventCatalog\Exceptions like every
// other HasErrorCode exception in this context, not App\Exceptions.
arch()->preset()->laravel()->ignoring([
    ErrorCode::class,
    Capability::class,
    MembershipScope::class,
    MembershipAccessOutcome::class,
    OutboxDeliveryStatus::class,
    EventStatus::class,
    'App\Support\Outbox\Jobs',
    CurrencyMismatchException::class,
    InvalidEventVenueConfigurationException::class,
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
    MembershipExistsException::class,
    MembershipNotFoundException::class,
    LastOwnerRemovalException::class,
    InvitationTokenInvalidException::class,
    InvitationTokenExpiredException::class,
    MfaRequiredException::class,
    MfaCodeInvalidException::class,
    MfaAlreadyEnrolledException::class,
    MfaNotEnrolledException::class,
    MfaEnforcedForRoleException::class,
    MfaEnforcementRequiredException::class,
    TenantMismatchException::class,
    CustomerEmailTakenException::class,
    CustomerAlreadyClaimedException::class,
    ClaimTokenInvalidException::class,
    ClaimTokenExpiredException::class,
    PasswordResetTokenInvalidException::class,
    PasswordResetTokenExpiredException::class,
    StaffInvitationMail::class,
    CustomerClaimMail::class,
    PasswordResetMail::class,
    DefaultLocaleNotSupportedException::class,
    DomainAlreadyRegisteredException::class,
    InvalidDomainNameException::class,
    InvalidGatewayConfigurationException::class,
    TenantDomainIsPrimaryException::class,
    TenantDomainNotFoundException::class,
    TenantNotFoundException::class,
    UnknownDomainException::class,
    UnknownHostException::class,
    VenueNotFoundException::class,
    EventNotFoundException::class,
    EventImmutableException::class,
    EventNotPublishableException::class,
    EventNotCancelableException::class,
    TicketTypeNotFoundException::class,
    TicketTypeCurrencyMismatchException::class,
    SeatMapDuplicateSeatsException::class,
    SeatMapNameTakenException::class,
    SeatMapNotFoundException::class,
    SeatMapConflictException::class,
    SeatMapVenueMismatchException::class,
    SeatMapVirtualEventException::class,
    SeatMapInUseException::class,
    MissingTenantHeaderException::class,
    InvalidTenantHeaderException::class,
    TenantAccessDeniedException::class,
    RenderedErrorRollback::class,
    TenancyServiceProvider::class,
    IdentityServiceProvider::class,
    EventCatalogServiceProvider::class,
    'App\Tenancy\Models',
    'App\Tenancy\Http\Controllers',
    'App\Identity\Models',
    'App\Identity\Http\Controllers',
    'App\EventCatalog\Models',
    'App\EventCatalog\Http\Controllers',
    'App\Support\Audit\Models',
    'App\Support\Outbox\Models',
]);
