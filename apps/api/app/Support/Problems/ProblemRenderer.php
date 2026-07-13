<?php

namespace App\Support\Problems;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProblemRenderer
{
    // Mirrors App\Http\Middleware\CorrelationId::HEADER; the architecture
    // suite's Laravel preset forbids referencing middleware from here.
    private const CORRELATION_HEADER = 'X-Correlation-Id';

    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('v1/*')) {
            return null;
        }

        if ($e instanceof HttpResponseException) {
            return null;
        }

        $correlationId = $request->headers->get(self::CORRELATION_HEADER);

        if ($e instanceof ValidationException) {
            return ValidationProblemData::fromErrors(
                $e->errors(),
                $this->detailFor(ErrorCode::RequestValidationFailed),
                $correlationId,
            )->toProblemResponse();
        }

        if ($e instanceof AuthenticationException) {
            return $this->problemResponse(ErrorCode::AuthUnauthenticated, $correlationId);
        }

        if ($e instanceof HasErrorCode) {
            $code = $e->errorCode();
            $detail = $e->getMessage() !== '' ? $e->getMessage() : $this->detailFor($code);

            if ($e instanceof HasValidationErrors) {
                return ValidationProblemData::fromErrors($e->errors(), $detail, $correlationId, $code)->toProblemResponse();
            }

            $headers = $e instanceof HasProblemHeaders ? $e->problemHeaders() : [];
            $problem = ProblemData::fromErrorCode($code, $detail, $correlationId);

            if ($e instanceof HasProblemExtensions) {
                return response()->json(
                    [...$problem->toArray(), ...$e->problemExtensions()],
                    $code->status(),
                    $headers + ['Content-Type' => 'application/problem+json'],
                );
            }

            return $problem->toProblemResponse($headers);
        }

        // The query-builder allowlist exceptions are vendor classes, so they
        // cannot implement HasErrorCode; mapped here before the generic
        // HttpExceptionInterface arm swallows their 400.
        if ($e instanceof InvalidQuery) {
            return ProblemData::fromErrorCode(
                ErrorCode::InvalidQueryParameter,
                $e->getMessage() !== '' ? $e->getMessage() : $this->detailFor(ErrorCode::InvalidQueryParameter),
                $correlationId,
            )->toProblemResponse();
        }

        if ($e instanceof HttpExceptionInterface) {
            $code = match ($e->getStatusCode()) {
                404 => ErrorCode::RequestNotFound,
                405 => ErrorCode::RequestMethodNotAllowed,
                401 => ErrorCode::AuthUnauthenticated,
                403 => ErrorCode::AuthForbidden,
                413 => ErrorCode::PayloadTooLarge,
                429 => ErrorCode::RequestRateLimited,
                default => ErrorCode::ServerInternalError,
            };

            return $this->problemResponse($code, $correlationId, $e->getHeaders());
        }

        return $this->problemResponse(ErrorCode::ServerInternalError, $correlationId);
    }

    /**
     * @param  array<string, string|string[]>  $headers
     */
    private function problemResponse(ErrorCode $code, ?string $correlationId, array $headers = []): JsonResponse
    {
        return ProblemData::fromErrorCode($code, $this->detailFor($code), $correlationId)
            ->toProblemResponse($headers);
    }

    private function detailFor(ErrorCode $code): string
    {
        return match ($code) {
            ErrorCode::RequestNotFound => 'The requested resource does not exist.',
            ErrorCode::RequestMethodNotAllowed => 'The HTTP method is not allowed for this resource.',
            ErrorCode::RequestValidationFailed => 'The request payload failed validation.',
            ErrorCode::AuthUnauthenticated => 'Authentication is required to access this resource.',
            ErrorCode::AuthForbidden => 'You are not allowed to perform this action.',
            ErrorCode::RequestRateLimited => 'Too many requests were sent; retry after the interval in the Retry-After header.',
            ErrorCode::PayloadTooLarge => 'The request body exceeds the maximum size this server accepts.',
            ErrorCode::ServerInternalError => 'An unexpected error occurred; quote the correlation ID when contacting support.',
            ErrorCode::HealthDegraded => 'One or more backing services failed their health check.',
            ErrorCode::InvalidQueryParameter => 'The request carries a filter or sort parameter outside the endpoint allowlist.',
            ErrorCode::TenantNotFound => 'No tenant has this id.',
            ErrorCode::DefaultLocaleNotSupported => 'The default locale is not one of the supported locales.',
            ErrorCode::TenantDomainNotFound => 'No tenant domain has this id.',
            ErrorCode::DomainAlreadyRegistered => 'The domain is already registered to a tenant.',
            ErrorCode::TenantDomainIsPrimary => "The operation conflicts with the tenant's primary domain.",
            ErrorCode::UnknownHost => 'No tenant serves this host.',
            ErrorCode::MissingTenantHeader => 'The request must carry the acting tenant in the X-Tenant-Id header.',
            ErrorCode::InvalidTenantHeader => 'The X-Tenant-Id header must be a UUID.',
            ErrorCode::TenantAccessDenied => 'You do not have access to this tenant.',
            ErrorCode::UnknownDomain => 'No tenant has this domain registered.',
            ErrorCode::InvalidCredentials => 'The email or password is incorrect.',
            ErrorCode::InvalidRefreshToken => 'The refresh token is invalid, expired, or unknown.',
            ErrorCode::RefreshTokenReused => 'This refresh token was already used; every token issued from its login has been revoked.',
            ErrorCode::MissingCapability => 'The acting membership does not hold the capability this action requires.',
            ErrorCode::RoleNotEditable => 'Global template roles cannot be modified or deleted.',
            ErrorCode::RoleInUse => 'This role is assigned to at least one membership and cannot be deleted.',
            ErrorCode::RoleNameTaken => 'A role with this name already exists in the tenant.',
            ErrorCode::UnknownCapability => 'The capabilities list includes a name that is not in the capability registry.',
            ErrorCode::MembershipExists => 'This user already has a membership in the tenant.',
            ErrorCode::LastOwnerRemoval => 'This membership is the tenant\'s only Owner and cannot be demoted or removed.',
            ErrorCode::InvitationTokenInvalid => 'The invitation token is malformed, tampered with, or unknown.',
            ErrorCode::InvitationTokenExpired => 'The invitation token has expired.',
            ErrorCode::MfaRequired => 'This account has MFA enabled; a valid TOTP or recovery code is required.',
            ErrorCode::MfaCodeInvalid => 'The MFA code is invalid.',
            ErrorCode::MfaAlreadyEnrolled => 'MFA is already enrolled and confirmed for this account.',
            ErrorCode::MfaNotEnrolled => 'MFA enrollment has not been started for this account.',
            ErrorCode::MfaEnforcedForRole => 'MFA cannot be disabled while the acting membership requires it.',
            ErrorCode::MfaEnforcementRequired => 'This membership requires confirmed MFA before it can perform this action.',
            ErrorCode::TenantMismatch => 'This token belongs to a different tenant than the one resolved for this request.',
            ErrorCode::CustomerEmailTaken => 'This email is already registered in this tenant.',
            ErrorCode::CustomerAlreadyClaimed => 'This account has already been claimed.',
            ErrorCode::ClaimTokenInvalid => 'The claim token is malformed, tampered with, or unknown.',
            ErrorCode::ClaimTokenExpired => 'The claim token has expired.',
            ErrorCode::PasswordResetTokenInvalid => 'The password reset token is unknown, malformed, or already used.',
            ErrorCode::PasswordResetTokenExpired => 'The password reset token has expired.',
            ErrorCode::CatalogEventImmutable => 'This event is canceled and can no longer be modified.',
            ErrorCode::CatalogCurrencyMismatch => "The ticket type's currency does not match the tenant's settlement currency.",
            ErrorCode::CatalogEventNotPublishable => 'Only a draft event can be published.',
            ErrorCode::CatalogEventNotCancelable => 'This event is already canceled.',
            ErrorCode::CatalogSeatMapDuplicateSeats => 'The seats array contains two or more seats sharing the same section, row, and number.',
            ErrorCode::CatalogSeatMapNameTaken => 'A seat map with this name already exists for the venue.',
            ErrorCode::CatalogSeatMapConflict => 'A seat collided with an existing section, row, and number on this seat map.',
            ErrorCode::CatalogSeatMapVenueMismatch => "The seat map does not exist or does not belong to the event's venue.",
            ErrorCode::CatalogSeatMapVirtualEvent => 'A virtual event cannot have a seat_map_id.',
            ErrorCode::CatalogSeatMapInUse => 'This seat map is referenced by at least one event and cannot be deleted.',
            ErrorCode::CatalogSeatMapRequired => 'This event has a requires_seat ticket type but no seat_map_id.',
            ErrorCode::InsufficientInventory => 'There is not enough inventory available for this ticket type.',
            ErrorCode::EventNotFound => 'No published event has this id for the resolved tenant.',
            ErrorCode::TicketTypeNotInEvent => 'This ticket type does not belong to the given event.',
            ErrorCode::SalesWindowClosed => "This ticket type's sales window is not currently open.",
            ErrorCode::HoldNotFound => 'No hold has this id for the resolved tenant.',
            ErrorCode::HoldNotReleasable => 'This hold is committed and cannot be released.',
            ErrorCode::HoldNotExtendable => 'This hold is expired, released, or committed and cannot be extended.',
            ErrorCode::HoldNotCommittable => 'This hold is expired, released, or already committed and cannot be committed.',
            ErrorCode::SeatSelectionInvalid => 'The seat_ids do not match the requested items: a requires_seat item is missing seats, the seat count does not match its quantity, or seats were given for a GA item.',
            ErrorCode::SeatUnavailable => 'One or more of the requested seats are held, sold, blocked, or do not belong to the given event and ticket type.',
            ErrorCode::EventNotSeated => 'This event has no seat map; there is no seat inventory to read.',
            ErrorCode::SeatNotModifiable => 'One or more of the requested seats could not be transitioned by this operation.',
            ErrorCode::CheckoutHoldExpired => 'This hold has expired and can no longer be converted to an order.',
            ErrorCode::HoldAlreadyConverted => 'An order already references this hold.',
            ErrorCode::OrderNotFound => 'No order has this id for the resolved tenant.',
            ErrorCode::OrderNotCancelable => 'Only a pending order can be canceled.',
            ErrorCode::OrderNotPaid => 'This order has no issued tickets to resend.',
            ErrorCode::InvalidOrderTransition => 'The order is not in a state this transition applies to.',
            ErrorCode::PromoCodeInvalid => 'No promo code with this code exists for the resolved tenant.',
            ErrorCode::PromoCodeNotActive => 'This promo code is outside its validity window.',
            ErrorCode::PromoCodeExhausted => 'This promo code has reached its usage limit.',
            ErrorCode::PromoCodeCurrencyMismatch => "This promo code's currency does not match the order currency.",
            ErrorCode::PromoCodeImmutableField => 'This field cannot be changed once the promo code has been used.',
            ErrorCode::IdempotencyKeyMissing => 'This endpoint requires an Idempotency-Key header.',
            ErrorCode::IdempotencyKeyReuseMismatch => 'This Idempotency-Key was already used with a different request payload.',
            ErrorCode::OrderNotPayable => 'This order is not in a state that accepts payment.',
            ErrorCode::PaymentMethodNotAvailable => 'This payment method is not currently offered for this order.',
            ErrorCode::PaymentDeclined => 'The gateway declined this payment; the order and its hold remain intact for a retry.',
            ErrorCode::GatewayUnavailable => 'The payment gateway is temporarily unavailable; retry after the interval in the Retry-After header.',
            ErrorCode::GatewayNotConfigured => 'This gateway has no credentials configured for the resolved tenant.',
            ErrorCode::WebhookSignatureInvalid => 'The webhook signature failed verification.',
            ErrorCode::WebhookUnparseable => 'The webhook body could not be parsed into a gateway event.',
            ErrorCode::RefundPaymentNotFound => 'No payment has this id for the resolved tenant.',
            ErrorCode::RefundNotFound => 'No refund has this id for the resolved tenant.',
            ErrorCode::PaymentNotRefundable => 'This payment is not confirmed or its order is not in a refundable status.',
            ErrorCode::RefundAmountExceedsRefundable => 'The requested amount exceeds the payment\'s remaining refundable amount.',
            ErrorCode::RefundCurrencyMismatch => 'The requested amount currency does not match the payment currency.',
            ErrorCode::RefundTicketsNotInOrder => 'One or more of the requested ticket_ids do not belong to this order.',
            ErrorCode::GatewayUnknown => 'No adapter is registered for this gateway.',
            ErrorCode::GatewayNotEnabled => "This gateway is not in the tenant's enabled_gateways.",
            ErrorCode::SubmerchantAlreadyOnboarded => 'A sub-merchant account for this tenant and gateway already exists.',
            ErrorCode::SubmerchantNotActive => 'This gateway has no active sub-merchant account for the resolved tenant.',
            ErrorCode::CheckinNotAssigned => 'The acting user is not assigned to check in this event.',
            ErrorCode::QrSignatureInvalid => 'The QR signature could not be verified against any of the event\'s signing keys.',
            ErrorCode::QrKeyRevoked => 'The QR was signed with a key that has since been revoked.',
            ErrorCode::TicketRotationStale => 'The QR carries a rotation counter older than the ticket\'s current one.',
            ErrorCode::ScannedAtInFuture => 'The scanned_at timestamp is further in the future than this endpoint tolerates.',
            ErrorCode::CheckInTicketNotFound => 'No ticket has this id for the resolved event.',
            ErrorCode::CheckInTicketCanceled => 'This ticket belongs to a canceled order and cannot be checked in.',
            ErrorCode::CheckInTicketRefunded => 'This ticket has been refunded and cannot be checked in.',
            ErrorCode::TicketAlreadyCheckedIn => 'This ticket has already been checked in.',
            ErrorCode::BatchTooLarge => 'The batch exceeds the maximum number of scans this endpoint accepts.',
            ErrorCode::UserNotMember => 'The target user does not have a membership in this tenant.',
            ErrorCode::AlreadyAssigned => 'This user is already assigned to check in this event.',
            ErrorCode::AssignmentNotFound => 'No check-in assignment has this id for the resolved tenant.',
            ErrorCode::CustomerRequired => 'This hold includes a ticket type with a per-customer purchase limit, which requires an authenticated customer.',
            ErrorCode::PurchaseLimitExceeded => 'This customer has reached the per-customer purchase limit for this ticket type.',
            ErrorCode::QueueNotActive => 'This event is not flagged high-demand; it has no active waiting room.',
            ErrorCode::ChallengeRequired => 'This event requires a challenge_response to join its waiting room.',
            ErrorCode::ChallengeFailed => 'The supplied challenge_response was rejected.',
            ErrorCode::QueueEntryNotFound => 'No queue entry has this id for the resolved tenant.',
            ErrorCode::AdmissionRequired => 'This event is flagged high-demand and requires a valid X-Admission-Token header.',
            ErrorCode::AdmissionInvalid => 'The presented X-Admission-Token is not valid for this event, tenant, and time.',
            ErrorCode::ExportNotReady => 'This export has not completed yet; retry once its status is completed.',
            ErrorCode::ExportFailed => 'This export failed and has no file to download.',
            ErrorCode::CustomerAlreadyAnonymized => 'This customer has already been anonymized.',
            ErrorCode::DataSubjectRequestAlreadyOpen => 'An open data subject request of this type already exists for this customer.',
        };
    }
}
