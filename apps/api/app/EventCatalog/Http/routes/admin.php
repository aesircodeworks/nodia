<?php

// Registered under the tenancy.admin group (Passport staff bearer plus
// X-Tenant-Id membership validation) via EventCatalogServiceProvider,
// mounted under /v1 (stage-05a plan, task breakdown item 2). Empty for
// now: tests/Feature/EventCatalog/CatalogAuthorizationMatrixTest.php
// already proves events.view, events.manage, and events.publish gate
// this stage's admin endpoint table through test-scoped probe routes
// (RouteSpecDriftTest exempts those), so no premature OpenAPI
// documentation is forced ahead of each endpoint's own contract. Task
// breakdown items 3, 5, 8, and 9 populate this file with the real
// venues, events, and ticket-types routes as each slice's controller and
// Data objects land.
