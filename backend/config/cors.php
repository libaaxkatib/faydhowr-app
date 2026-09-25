<?php

/**
 * HRM Phase 7 (Security/Hardening audit, P7-07): previously absent, so
 * Laravel's own framework default applied - allowed_origins: ['*'] for the
 * entire api/* surface, undocumented and unpinned. This makes the allowed
 * origin(s) explicit and env-configurable instead of wide-open.
 *
 * CORS_ALLOWED_ORIGINS is a comma-separated list, matching the same shape
 * SANCTUM_STATEFUL_DOMAINS already uses. Defaults to the Web Panel's local
 * dev origins; set it to the real frontend domain(s) in production.
 */
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173')),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
