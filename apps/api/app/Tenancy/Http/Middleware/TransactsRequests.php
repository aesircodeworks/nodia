<?php

namespace App\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Shared by the request-transaction middleware of every posture. The
 * routing pipeline renders exceptions thrown below a route middleware into
 * responses before the middleware sees them, so a failed handler arrives
 * here as a response carrying its exception and the transaction must be
 * aborted explicitly or partial writes would commit behind an error
 * response.
 */
trait TransactsRequests
{
    /**
     * $posture opens the tenant transaction (asTenant, asPlatform, ...)
     * around the given request handler.
     *
     * @param  Closure(Closure(): Response): Response  $posture
     */
    private function transactRequest(Closure $posture, Request $request, Closure $next): Response
    {
        try {
            return $posture(function () use ($request, $next): Response {
                $response = $next($request);

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
