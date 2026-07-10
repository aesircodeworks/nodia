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

            return ProblemData::fromErrorCode(
                $code,
                $e->getMessage() !== '' ? $e->getMessage() : $this->detailFor($code),
                $correlationId,
            )->toProblemResponse();
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
        };
    }
}
