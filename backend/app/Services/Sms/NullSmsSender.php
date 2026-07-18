<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsSenderInterface;

class NullSmsSender implements SmsSenderInterface
{
    public function send(string $phone, string $message): void
    {
        // Intentionally discards the message (testing / disabled delivery).
    }
}
