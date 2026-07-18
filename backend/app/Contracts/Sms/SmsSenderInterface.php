<?php

namespace App\Contracts\Sms;

interface SmsSenderInterface
{
    /**
     * Send an SMS message to an E.164 phone number.
     */
    public function send(string $phone, string $message): void;
}
