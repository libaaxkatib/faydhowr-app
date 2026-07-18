<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsSenderInterface;
use Illuminate\Support\Facades\Log;

class LogSmsSender implements SmsSenderInterface
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS dispatched (log driver).', [
            'phone' => $phone,
            'message' => $message,
        ]);
    }
}
