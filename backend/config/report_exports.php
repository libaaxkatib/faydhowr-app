<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Report Exports Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk used to store generated report export files. Any
    | disk configured in config/filesystems.php may be used here.
    |
    */

    'default_disk' => env('REPORT_EXPORTS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Exports Directory
    |--------------------------------------------------------------------------
    |
    | The base directory on the disk where report export files are stored.
    | Each export is placed in its own subdirectory keyed by export id.
    |
    */

    'exports_directory' => env('REPORT_EXPORTS_DIRECTORY', 'reports/exports'),

];
