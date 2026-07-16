<?php

return [

    'gateways' => [

        'manual' => [
            'webhook_secret' => env('PAYMENT_MANUAL_WEBHOOK_SECRET', 'test-webhook-secret'),
        ],

    ],

];
