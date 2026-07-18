<?php

namespace App\Contracts\Auth\Services;

use App\Enums\Auth\OtpPurpose;
use App\Exceptions\Auth\OtpAttemptsExceededException;
use App\Exceptions\Auth\OtpCooldownException;
use App\Exceptions\Auth\OtpExpiredException;
use App\Exceptions\Auth\OtpInvalidException;
use App\Exceptions\Auth\OtpRateLimitedException;
use App\Models\PhoneOtp;

interface OtpServiceInterface
{
    /**
     * Issue a fresh OTP for the phone/purpose, invalidating any prior active OTP.
     *
     * @throws OtpCooldownException
     * @throws OtpRateLimitedException
     */
    public function issue(string $phone, OtpPurpose $purpose): PhoneOtp;

    /**
     * Verify and consume the active OTP for the phone/purpose.
     *
     * @throws OtpInvalidException
     * @throws OtpExpiredException
     * @throws OtpAttemptsExceededException
     */
    public function verify(string $phone, OtpPurpose $purpose, string $code): PhoneOtp;
}
