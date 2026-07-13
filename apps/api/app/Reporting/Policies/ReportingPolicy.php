<?php

namespace App\Reporting\Policies;

/**
 * Placeholder for Reporting's authorization surface (stage-11 plan, task
 * breakdown item 3). Every endpoint this stage adds is capability-gated
 * through App\Http\Middleware\RequireCapability on reports.view or
 * reports.export (stage-11 plan, Endpoints), the same route-level gate
 * every other context uses (PaymentsServiceProvider's own admin routes);
 * an export fetch or download outside the acting tenant is a 404 through
 * RLS tenant scoping alone, needing no bespoke per-resource rule. This
 * class carries no logic yet: it exists so app/Reporting matches every
 * other context's directory layout (system-design 3.2: each context owns
 * Models, Actions, Events, Http, Policies, Data) ahead of tasks 6, 9, 12,
 * and 16 landing the endpoints. Filled in only if an endpoint later needs
 * authorization beyond capability plus RLS tenant scoping, mirroring why
 * App\CheckIn\Policies\CheckInAssignmentPolicy exists: assignment-based
 * access beyond a plain capability check.
 */
final class ReportingPolicy {}
