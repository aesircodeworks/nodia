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

    /*
    |--------------------------------------------------------------------------
    | Staff Invitation Acceptance Token Lifetime
    |--------------------------------------------------------------------------
    |
    | POST /v1/memberships (InviteUser) issues a signed, time-limited token
    | mailed to the invitee (stage-03 plan, task breakdown item 9); POST
    | /v1/auth/staff/invitation/accept redeems it. The plan mandates
    | "time-limited" but no specific duration, the same open point the
    | refresh token TTL above already resolved by picking an explicit,
    | config-driven, testable value; a week gives a real invitee enough
    | time to check their inbox without the token becoming a long-lived
    | secret.
    |
    */

    'invitation_token_ttl_minutes' => (int) env('STAFF_INVITATION_TOKEN_TTL_MINUTES', 10_080),

];
