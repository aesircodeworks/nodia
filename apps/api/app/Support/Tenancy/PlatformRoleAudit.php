<?php

namespace App\Support\Tenancy;

use App\Support\Database\Rls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The audit seam of system-design 4.3: every request executing under the
 * cross-tenant nodia_platform role is recorded, keyed by the request
 * correlation ID. Stage 2 records a structured log entry; Stage 3
 * upgrades this method's body to activity-log records without touching
 * the call site in PlatformRequestTransaction, which invokes it inside
 * the platform transaction so a record written to a tenant-scoped table
 * passes RLS with the sentinel tenant. Stage 3 must also decide how an
 * activity-log row survives a rolled-back request, which a log line does
 * for free.
 */
class PlatformRoleAudit
{
    public const MESSAGE = 'audit.platform_role.request';

    // Mirrors App\Http\Middleware\CorrelationId::HEADER; the architecture
    // suite's Laravel preset forbids referencing middleware from here.
    private const CORRELATION_HEADER = 'X-Correlation-Id';

    public function recordRequest(Request $request): void
    {
        Log::info(self::MESSAGE, [
            'role' => Rls::PLATFORM_ROLE,
            'correlation_id' => $request->headers->get(self::CORRELATION_HEADER),
            'method' => $request->getMethod(),
            'path' => '/'.ltrim($request->path(), '/'),
        ]);
    }
}
