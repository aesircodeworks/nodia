<?php

// Routes here are registered under /v1 with no auth and no tenancy
// posture group: the domain-verification endpoint is the edge proxy's
// on-demand TLS ask (system-design 16.3), called during the TLS handshake
// before any credential or tenant context can exist. Infrastructure must
// never route /v1/internal through the public edge. The lookup itself
// runs under the narrow nodia_resolver posture inside ResolveDomain.

use App\Tenancy\Http\Controllers\DomainVerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/internal/domain-verification', DomainVerificationController::class);
