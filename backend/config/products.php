<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product Images
    |--------------------------------------------------------------------------
    |
    | Catalog product images are stored through Laravel's filesystem abstraction.
    | Switch disks (e.g. s3) without changing application code.
    |
    */

    'images' => [
        'disk' => env('PRODUCT_IMAGES_DISK', 'public'),
        'directory' => env('PRODUCT_IMAGES_DIRECTORY', 'products'),
        'max_kilobytes' => (int) env('PRODUCT_IMAGES_MAX_KB', 5120),
        'allowed_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

];
