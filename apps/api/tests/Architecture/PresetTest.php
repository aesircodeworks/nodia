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
use App\EventCatalog\Exceptions\SeatMapRequiredException;
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
use App\Inventory\Enums\EventSeatStatus;
use App\Inventory\Enums\HoldStatus;
use App\Inventory\Exceptions\EventNotSeatedException;
use App\Inventory\Exceptions\HoldEventNotFoundException;
use App\Inventory\Exceptions\HoldInventoryReleaseFailedException;
use App\Inventory\Exceptions\HoldNotCommittableException;
use App\Inventory\Exceptions\HoldNotExtendableException;
use App\Inventory\Exceptions\HoldNotFoundException;
use App\Inventory\Exceptions\HoldNotReleasableException;
use App\Inventory\Exceptions\InsufficientHoldInventoryException;
use App\Inventory\Exceptions\InsufficientInventoryException;
use App\Inventory\Exceptions\SalesWindowClosedException;
use App\Inventory\Exceptions\SeatNotModifiableException;
use App\Inventory\Exceptions\SeatSelectionInvalidException;
use App\Inventory\Exceptions\SeatUnavailableException;
use App\Inventory\Exceptions\TicketTypeInventoryNotFoundException;
use App\Inventory\Exceptions\TicketTypeNotInEventException;
use App\Inventory\InventoryServiceProvider;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PromoCodeDiscountType;
use App\Orders\Enums\TicketStatus;
use App\Orders\Exceptions\HoldAlreadyConvertedException;
use App\Orders\Exceptions\HoldExpiredException as OrderHoldExpiredException;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Orders\Exceptions\OrderNotCancelableException;
use App\Orders\Exceptions\OrderNotFoundException;
use App\Orders\Exceptions\OrderNotPaidException;
use App\Orders\Exceptions\PromoCodeCurrencyMismatchException;
use App\Orders\Exceptions\PromoCodeExhaustedException;
use App\Orders\Exceptions\PromoCodeImmutableFieldException;
use App\Orders\Exceptions\PromoCodeInvalidException;
use App\Orders\Exceptions\PromoCodeNotActiveException;
use App\Orders\OrdersServiceProvider;
use App\Payments\Enums\PaymentMethodConfirmation;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Exceptions\GatewayUnavailableException;
use App\Payments\Exceptions\IdempotencyKeyMissingException;
use App\Payments\Exceptions\IdempotencyKeyReuseMismatchException;
use App\Payments\Exceptions\OrderNotPayableException;
use App\Payments\Exceptions\PaymentMethodNotAvailableException;
use App\Payments\Exceptions\PaymentOrderNotFoundException;
use App\Payments\Exceptions\WebhookSignatureInvalidException;
use App\Payments\Exceptions\WebhookUnparseableException;
use App\Payments\Gateways\GatewayPaymentOutcome;
use App\Payments\Gateways\WebhookKind;
use App\Payments\PaymentsServiceProvider;
use App\Support\Media\Exceptions\MediaNotFoundException;
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
// App\Support\Media\Models\Media (stage-05c task-01) is the same story as
// App\Support\Audit\Models\ActivityLogEntry and App\Support\Outbox\
// Models: shared infrastructure with no single owning bounded context
// (EventCatalog and Tenancy both attach collections to it here), so it
// lives under App\Support rather than App\Models. MediaNotFoundException
// (stage-05c task-02) is the same story for App\Support\Problems'
// HasErrorCode exceptions: it guards the shared, no-single-owning-context
// DELETE /v1/media/{media} route (App\Http\Controllers\MediaController),
// so it lives under App\Support\Media\Exceptions rather than any one
// bounded context's own Exceptions directory.
// App\Inventory\Models\TicketTypeInventory (stage-06 task-02) lives with
// its own bounded context like every other context's Models directory
// already ignored above, not App\Models.
// InsufficientInventoryException (stage-06 task-02) lives in
// App\Inventory\Exceptions like every other HasErrorCode exception in
// this codebase, not App\Exceptions.
// HoldInventoryReleaseFailedException (stage-06 review) is the same
// story: an Inventory HasErrorCode guard exception living with its
// bounded context, not App\Exceptions.
// OrderStatus, TicketStatus, and PromoCodeDiscountType (stage-07 task-01)
// are Orders' own status registries and live with their bounded context
// like HoldStatus and EventStatus, not App\Enums.
arch()->preset()->laravel()->ignoring([
    ErrorCode::class,
    Capability::class,
    MembershipScope::class,
    MembershipAccessOutcome::class,
    OutboxDeliveryStatus::class,
    EventStatus::class,
    HoldStatus::class,
    EventSeatStatus::class,
    OrderStatus::class,
    TicketStatus::class,
    PromoCodeDiscountType::class,
    PaymentStatus::class,
    PaymentMethodConfirmation::class,
    GatewayPaymentOutcome::class,
    WebhookKind::class,
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
    SeatMapRequiredException::class,
    InsufficientInventoryException::class,
    HoldEventNotFoundException::class,
    TicketTypeNotInEventException::class,
    SalesWindowClosedException::class,
    InsufficientHoldInventoryException::class,
    HoldInventoryReleaseFailedException::class,
    HoldNotFoundException::class,
    HoldNotReleasableException::class,
    HoldNotExtendableException::class,
    HoldNotCommittableException::class,
    SeatSelectionInvalidException::class,
    SeatUnavailableException::class,
    EventNotSeatedException::class,
    SeatNotModifiableException::class,
    TicketTypeInventoryNotFoundException::class,
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
    'App\Inventory\Models',
    'App\Inventory\Http\Controllers',
    InventoryServiceProvider::class,
    'App\Orders\Models',
    'App\Orders\Http\Controllers',
    OrdersServiceProvider::class,
    'App\Payments\Models',
    'App\Payments\Http\Controllers',
    PaymentsServiceProvider::class,
    GatewayUnavailableException::class,
    WebhookSignatureInvalidException::class,
    WebhookUnparseableException::class,
    PaymentOrderNotFoundException::class,
    OrderNotPayableException::class,
    IdempotencyKeyMissingException::class,
    IdempotencyKeyReuseMismatchException::class,
    PaymentMethodNotAvailableException::class,
    OrderHoldExpiredException::class,
    HoldAlreadyConvertedException::class,
    OrderNotFoundException::class,
    InvalidOrderTransitionException::class,
    OrderNotCancelableException::class,
    OrderNotPaidException::class,
    PromoCodeInvalidException::class,
    PromoCodeNotActiveException::class,
    PromoCodeExhaustedException::class,
    PromoCodeCurrencyMismatchException::class,
    PromoCodeImmutableFieldException::class,
    'App\Support\Audit\Models',
    'App\Support\Outbox\Models',
    'App\Support\Media\Models',
    MediaNotFoundException::class,
]);
