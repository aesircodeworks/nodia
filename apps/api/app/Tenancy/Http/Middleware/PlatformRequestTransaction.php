<?php

namespace App\Tenancy\Http\Middleware;

use App\Support\Tenancy\TenantTransaction;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The platform posture of system-design 4.1 and 4.3: the whole request
 * runs inside a transaction under SET LOCAL ROLE nodia_platform with
 * app.tenant_id set to the sentinel platform tenant.
 */
class PlatformRequestTransaction
{
    public function __construct(private readonly TenantTransaction $transaction) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $this->transaction->asPlatform(function () use ($request, $next): Response {
                $response = $next($request);

                // The routing pipeline renders exceptions thrown below this
                // middleware into responses before they reach it, so a failed
                // handler arrives here as a response carrying its exception
                // and the transaction must be aborted explicitly or partial
                // writes would commit behind an error response.
                if ($this->renderedException($response) !== null) {
                    throw new RenderedErrorRollback($response);
                }

                return $response;
            });
        } catch (RenderedErrorRollback $rollback) {
            return $rollback->response;
        }
    }

    private function renderedException(Response $response): ?Throwable
    {
        if ($response instanceof HttpResponse || $response instanceof JsonResponse) {
            return $response->exception;
        }

        return null;
    }
}
