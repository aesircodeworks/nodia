<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limiter Tiers
    |--------------------------------------------------------------------------
    |
    | Named limiters registered by InventoryServiceProvider (stage-10
    | plan, Endpoints "Rate limiting tiers"): `browse` (availability,
    | seats, event reads) is deliberately generous; `queue_entry` and
    | `queue_poll` (moderate, per IP) attach to the waiting-room routes
    | once that task lands; `hold_creation` is the strictest tier and is
    | checked both per IP and, when a customer bearer token is present,
    | per customer, so section 10's "hold creation stricter than browse"
    | requirement holds under either key.
    |
    | Each tier backs Illuminate\Cache\RateLimiter, which stores its
    | counters in config('cache.default') (Redis in every real
    | environment via CACHE_STORE=redis; the array store only in tests,
    | per phpunit.xml). This is deliberately the plain named-limiter
    | machinery Stage 1 already renders as request.rate_limited with
    | Retry-After (api-conventions Errors) rather than a bespoke
    | limiter, so no rendering is reimplemented here. Window expiry for
    | both stores keys off Carbon::now() (Illuminate\Cache\ArrayStore
    | and the RateLimiter's own InteractsWithTime trait), so travelTo /
    | freezeTime resets a window with no real sleeping in tests, the
    | same "fake clock governs tests" discipline App\Payments\Support\
    | CircuitBreaker documents for its own cache-backed state.
    |
    */

    'rate_limits' => [

        'browse' => [
            'max_attempts' => (int) env('ONSALE_BROWSE_RATE_MAX_ATTEMPTS', 120),
            'decay_seconds' => (int) env('ONSALE_BROWSE_RATE_DECAY_SECONDS', 60),
        ],

        'queue_entry' => [
            'max_attempts' => (int) env('ONSALE_QUEUE_ENTRY_RATE_MAX_ATTEMPTS', 20),
            'decay_seconds' => (int) env('ONSALE_QUEUE_ENTRY_RATE_DECAY_SECONDS', 60),
        ],

        'queue_poll' => [
            'max_attempts' => (int) env('ONSALE_QUEUE_POLL_RATE_MAX_ATTEMPTS', 30),
            'decay_seconds' => (int) env('ONSALE_QUEUE_POLL_RATE_DECAY_SECONDS', 60),
        ],

        'hold_creation' => [
            'ip' => [
                'max_attempts' => (int) env('ONSALE_HOLD_CREATION_IP_RATE_MAX_ATTEMPTS', 10),
                'decay_seconds' => (int) env('ONSALE_HOLD_CREATION_IP_RATE_DECAY_SECONDS', 60),
            ],
            'customer' => [
                'max_attempts' => (int) env('ONSALE_HOLD_CREATION_CUSTOMER_RATE_MAX_ATTEMPTS', 20),
                'decay_seconds' => (int) env('ONSALE_HOLD_CREATION_CUSTOMER_RATE_DECAY_SECONDS', 60),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Admission Rate Default
    |--------------------------------------------------------------------------
    |
    | The gatekeeper's per-event admission budget when
    | events.on_sale_policy.admission_rate_per_minute is null (stage-10
    | plan, "events.on_sale_policy (new column, EventCatalog context)").
    | Not yet consumed: the gatekeeper lands in a later stage-10 task.
    |
    */

    'admission_rate_per_minute_default' => (int) env('ONSALE_ADMISSION_RATE_PER_MINUTE_DEFAULT', 60),

    /*
    |--------------------------------------------------------------------------
    | Admission Token
    |--------------------------------------------------------------------------
    |
    | HMAC-SHA256 signing for the short-lived X-Admission-Token (stage-10
    | plan, "Admission token"): TTL default 5 minutes, verified
    | statelessly against the current and previous keys so signing keys
    | rotate without invalidating in-flight tokens. Not yet consumed:
    | token issuance and verification land in a later stage-10 task.
    |
    */

    'admission_token' => [
        'ttl_seconds' => (int) env('ONSALE_ADMISSION_TOKEN_TTL_SECONDS', 300),
        'current_key_id' => env('ONSALE_ADMISSION_TOKEN_KEY_ID', 'default'),
        'current_key_secret' => env('ONSALE_ADMISSION_TOKEN_SIGNING_KEY'),
        'previous_key_id' => env('ONSALE_ADMISSION_TOKEN_PREVIOUS_KEY_ID'),
        'previous_key_secret' => env('ONSALE_ADMISSION_TOKEN_PREVIOUS_SIGNING_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Read Cache TTLs
    |--------------------------------------------------------------------------
    |
    | Second-level TTLs for the Redis-fronted availability and seats
    | reads (stage-10 plan, GET .../availability and .../seats: "so
    | browse traffic never touches the inventory tables during an
    | on-sale"). Freshness is judged against cached_at plus this TTL
    | through the framework clock, never a bare Redis TTL (stage-10 plan
    | Risks "Fake clock versus Redis TTL"). Not yet consumed: the read
    | cache lands in a later stage-10 task.
    |
    */

    'cache' => [
        'availability_ttl_seconds' => (int) env('ONSALE_AVAILABILITY_CACHE_TTL_SECONDS', 2),
        'seats_ttl_seconds' => (int) env('ONSALE_SEATS_CACHE_TTL_SECONDS', 2),
    ],

];
