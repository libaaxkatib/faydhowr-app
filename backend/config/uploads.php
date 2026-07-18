<?php

/*
 * Unified File Upload Service (Sprint 23, API Design §14).
 * Limits are configuration-driven; there is no Settings UI in V1.
 */
return [

    'disk' => env('UPLOADS_DISK', 'local'),

    'directory' => 'uploads',

    'max_files_per_request' => 10,

    'max_file_bytes' => [
        'image' => 10 * 1024 * 1024,
        'document' => 20 * 1024 * 1024,
        'video' => 100 * 1024 * 1024,
    ],

    'staged_quota_bytes' => 500 * 1024 * 1024,

    'retention_days' => 7,

    'rate_limit_per_minute' => 20,

];
