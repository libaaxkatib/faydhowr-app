<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    | Disk used for HR attachments such as client-company agreements. Defaults to
    | the private local disk; point it at an S3-compatible disk in production
    | (HR_ATTACHMENTS_DISK=s3) once storage has been approved and configured.
    */
    'hr_attachments_disk' => env('HR_ATTACHMENTS_DISK', 'local'),

    /*
    | Employee documents. New uploads are written to `hr_documents_disk`; every document row records the disk it was
    | written to, so documents uploaded earlier stay reachable after this setting changes. The default, 'local', is
    | today's behaviour: nothing changes until a deployment sets HR_DOCUMENTS_DISK (for example to `r2_documents`).
    |
    | `hr_documents_mirror_disk` is the recovery copy written by `php artisan hr:documents-mirror` (null = no mirror
    | configured; the mirror commands then refuse to run). The mirror is never the source of truth and the commands never
    | delete from either disk.
    */
    'hr_documents_disk' => env('HR_DOCUMENTS_DISK', 'local'),
    'hr_documents_mirror_disk' => env('HR_DOCUMENTS_MIRROR_DISK'),
    // Re-read each new upload after writing it and compare its SHA-256 before the row is saved.
    'hr_documents_verify_readback' => env('HR_DOCUMENTS_VERIFY_READBACK', true),
    // Hash a document with a recorded checksum before sending it; a mismatch is never served.
    'hr_documents_verify_on_download' => env('HR_DOCUMENTS_VERIFY_ON_DOWNLOAD', true),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Private S3-compatible store (Cloudflare R2) for employee documents. NOT used unless HR_DOCUMENTS_DISK=r2_documents.
        // Needs a bucket-scoped API token supplied only through the environment. Objects are never public: no `url`, private
        // visibility, and every download goes through the application's own authorization.
        //
        // The two checksum settings stop the AWS SDK from adding its default CRC32 checksum headers to every request; those
        // headers are a known source of incompatibility with S3-compatible stores. Integrity does not depend on them: the
        // application compares size and SHA-256 itself (see EmployeeDocumentStorage). Confirm against the real bucket during
        // the approved rehearsal before relying on this.
        'r2_documents' => [
            'driver' => 's3',
            'key' => env('R2_DOCUMENTS_ACCESS_KEY_ID'),
            'secret' => env('R2_DOCUMENTS_SECRET_ACCESS_KEY'),
            'region' => env('R2_DOCUMENTS_REGION', 'auto'),
            'bucket' => env('R2_DOCUMENTS_BUCKET'),
            'endpoint' => env('R2_DOCUMENTS_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        // Recovery copy of the documents on a Railway volume (mount it, then set HR_DOCUMENTS_MIRROR_ROOT to the mount path).
        // Used only when HR_DOCUMENTS_MIRROR_DISK=hr_documents_mirror_volume.
        'hr_documents_mirror_volume' => [
            'driver' => 'local',
            'root' => env('HR_DOCUMENTS_MIRROR_ROOT', storage_path('app/mirror')),
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
