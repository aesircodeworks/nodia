<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OAuth Token Lifetimes
    |--------------------------------------------------------------------------
    |
    | IdentityServiceProvider always sets Passport::tokensExpireIn() and
    | Passport::refreshTokensExpireIn() explicitly from these values, never
    | leaving them on Passport's own one-year defaults (api-conventions:
    | Authentication and Tenant Context). The access token lifetime is
    | fixed at 15 minutes by the stage-03 plan; the refresh lifetime has no
    | mandated value there, so 30 days is a deliberate starting point,
    | recorded as a decision in the stage-03 execution journal and open to
    | revisit once the admin portal idle-timeout design note lands
    | (stage-03 plan, Risks).
    |
    */

    'access_token_ttl_minutes' => (int) env('PASSPORT_ACCESS_TOKEN_TTL_MINUTES', 15),

    'refresh_token_ttl_minutes' => (int) env('PASSPORT_REFRESH_TOKEN_TTL_MINUTES', 43_200),

];
