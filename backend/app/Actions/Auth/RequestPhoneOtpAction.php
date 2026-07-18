<?php

namespace App\Actions\Auth;

use App\Contracts\Auth\Services\OtpServiceInterface;
use App\Enums\Auth\OtpPurpose;
use App\Exceptions\Auth\OtpCooldownException;
use App\Exceptions\Auth\OtpRateLimitedException;

class RequestPhoneOtpAction
{
    public function __construct(private OtpServiceInterface $otp) {}

    /**
     * The OTP lifecycle runs regardless of account existence so the response
     * never discloses whether a phone number is registered (SRS FR-003A
     * pattern); SMS delivery itself is suppressed for unregistered phones.
     *
     * @throws OtpCooldownException
     * @throws OtpRateLimitedException
     */
    public function handle(string $phone, OtpPurpose $purpose = OtpPurpose::Login): void
    {
        $this->otp->issue($phone, $purpose);
    }
}
