<?php

namespace App\Support\Tenancy;

use App\Support\Audit\ActivityLogger;
use App\Support\Audit\Models\ActivityLogEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The audit seam of system-design 4.3: every request executing under the
 * cross-tenant nodia_platform role is recorded, keyed by the request
 * correlation ID. Stage 2 recorded a structured log entry; Stage 3
 * (task breakdown item 15) upgrades this method's body to a real
 * activity_log row via App\Support\Audit\ActivityLogger, closing the flag
 * Stage 2's exit line and this class's own docblock both left open.
 *
 * The call site in PlatformRequestTransaction changes from the original
 * plan's expectation of "without touching the call site": a row inserted
 * inside the same transaction as the request handler would roll back with
 * it, whereas a log line survived a rollback for free (the "decide how an
 * activity-log row survives a rolled-back request" question this
 * docblock's previous revision flagged but left open). The fix is a
 * separate, already-committed platform transaction that records this
 * entry before the handler's own transaction ever opens, so the row is
 * durable regardless of what happens afterward; recordRequest() itself
 * still does no transaction work of its own; TenantContext already
 * carries the sentinel platform tenant by the time it runs, matching
 * ActivityLogger's own no-SET-LOCAL-of-its-own precedent.
 */
class PlatformRoleAudit
{
    public const string EVENT = 'platform_role_use';

    // Mirrors App\Http\Middleware\CorrelationId::HEADER; the architecture
    // suite's Laravel preset forbids referencing middleware from here.
    private const CORRELATION_HEADER = 'X-Correlation-Id';

    public function __construct(private readonly ActivityLogger $logger) {}

    public function recordRequest(Request $request): ActivityLogEntry
    {
        $causer = $request->user('staff');

        return $this->logger->record(
            description: sprintf('%s %s', $request->getMethod(), '/'.ltrim($request->path(), '/')),
            causer: $causer instanceof Model ? $causer : null,
            event: self::EVENT,
            properties: ['correlation_id' => $request->headers->get(self::CORRELATION_HEADER)],
        );
    }
}
