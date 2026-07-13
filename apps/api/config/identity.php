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

    /*
    |--------------------------------------------------------------------------
    | MFA Recovery Codes
    |--------------------------------------------------------------------------
    |
    | POST /v1/auth/mfa/enrollment/confirm issues this many single-use
    | recovery codes exactly once (stage-03 plan, MFA endpoint table). The
    | plan does not mandate a count; 8 mirrors the common
    | Google-Authenticator-ecosystem default (GitHub, Google) without being
    | so many that a compromised list stays exploitable for long, recorded
    | as a decision in the stage-03 execution journal.
    |
    */

    'mfa_recovery_code_count' => (int) env('MFA_RECOVERY_CODE_COUNT', 8),

    /*
    |--------------------------------------------------------------------------
    | Customer Claim Token Lifetime
    |--------------------------------------------------------------------------
    |
    | POST /v1/auth/customer/claim issues this token, mailed to a guest
    | customer's own address (stage-03 plan, task breakdown item 13);
    | POST /v1/auth/customer/claim/confirm redeems it. The plan mandates a
    | time-limited token but no specific duration, the same open point
    | the invitation token TTL above already resolved by picking an
    | explicit, config-driven, testable value; a day gives a real
    | attendee enough time to check their inbox without the token
    | becoming a long-lived secret, shorter than the week-long staff
    | invitation window since claiming an existing guest account is a
    | lighter-weight action than onboarding a new staff member.
    |
    */

    'claim_token_ttl_minutes' => (int) env('CUSTOMER_CLAIM_TOKEN_TTL_MINUTES', 1_440),

    /*
    |--------------------------------------------------------------------------
    | Staff Password Reset Token Lifetime
    |--------------------------------------------------------------------------
    |
    | POST /v1/auth/staff/password/reset issues this token, mailed to the
    | staff user's own address (stage-03 plan, task breakdown item 16);
    | POST /v1/auth/staff/password/reset/confirm redeems it, single-use,
    | unlike the invitation and claim tokens above. The plan mandates a
    | time-limited token but no specific duration; an hour is deliberately
    | shorter than either sibling window, recorded as a decision in the
    | stage-03 execution journal: a forgotten-password reset is a more
    | security-sensitive action against an already-existing credential
    | than either onboarding flow above, so the token should not remain a
    | valid, high-privilege secret in an inbox for long.
    |
    */

    'reset_token_ttl_minutes' => (int) env('STAFF_PASSWORD_RESET_TOKEN_TTL_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Data Subject Export Download URL
    |--------------------------------------------------------------------------
    |
    | How long GET /v1/data-subject-requests/{data_subject_request}'s
    | signed download_url stays valid (stage-12 plan, Endpoints: "a
    | time-limited signed URL to the medialibrary attachment"), passed
    | straight to Spatie\MediaLibrary\MediaCollections\Models\Media::
    | getTemporaryUrl(), mirroring config/reporting.php's identical
    | export_download_url_ttl_minutes precedent for GET /v1/exports/
    | {export}/download rather than sharing that Reporting-owned config
    | key across contexts.
    |
    */

    'data_subject_export_download_url_ttl_minutes' => (int) env('DATA_SUBJECT_EXPORT_DOWNLOAD_URL_TTL_MINUTES', 15),

];
