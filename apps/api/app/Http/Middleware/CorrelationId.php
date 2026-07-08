<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $correlationId = $request->headers->get(self::HEADER) ?: Str::uuid7()->toString();

        $request->headers->set(self::HEADER, $correlationId);

        // Octane flushes shared log context between requests via its FlushLogContext
        // listener (registered through prepareApplicationForNextOperation on
        // RequestReceived), so this id cannot leak into a later request on the same worker.
        Log::withContext(['correlation_id' => $correlationId]);

        $response = $next($request);

        $response->headers->set(self::HEADER, $correlationId);

        Log::info('request.handled', [
            'method' => $request->getMethod(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ]);

        return $response;
    }
}
