<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sentinel Platform Tenant
    |--------------------------------------------------------------------------
    |
    | Platform-scope rows in shared tables carry this tenant id instead of
    | NULL (data-conventions, Tenancy), and platform-posture transactions
    | run with it as their app.tenant_id. The value is a fixed constant,
    | never environment-configured: the tenants migration inserts this
    | exact row, so a per-environment override would desynchronize runtime
    | context from the persisted sentinel. UUIDv7-shaped with a zero
    | timestamp, it sorts before every generated tenant id.
    |
    */

    'platform_tenant_id' => '00000000-0000-7000-8000-000000000000',

];
