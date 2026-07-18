<?php

namespace App\Services\Auth;

use App\Contracts\Auth\Repositories\PhoneOtpRepositoryInterface;
use App\Contracts\Auth\Services\OtpServiceInterface;
use App\Contracts\Sms\SmsSenderInterface;
use App\Enums\Auth\OtpPurpose;
use App\Exceptions\Auth\OtpAttemptsExceededException;
use App\Exceptions\Auth\OtpCooldownException;
use App\Exceptions\Auth\OtpExpiredException;
use App\Exceptions\Auth\OtpInvalidException;
use App\Exceptions\Auth\OtpRateLimitedException;
use App\Models\PhoneOtp;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * OTP lifecycle per SRS FR-002A–FR-002C (approved, frozen limits).
 */
class OtpService implements OtpServiceInterface
{
    public const int CODE_LENGTH = 6;

    public const int EXPIRY_SECONDS = 300;

    public const int RESEND_COOLDOWN_SECONDS = 60;

    public const int MAX_REQUESTS_PER_HOUR = 5;

    public const int MAX_FAILED_ATTEMPTS = 5;

    public function __construct(
        private PhoneOtpRepositoryInterface $otps,
        private SmsSenderInterface $sms,
    ) {}

    public function issue(string $phone, OtpPurpose $purpose): PhoneOtp
    {
        $latest = $this->otps->latestFor($phone, $purpose);

        if ($latest !== null && $latest->created_at->gt(now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
            throw OtpCooldownException::create();
        }

        if ($this->otps->countIssuedSince($phone, $purpose, now()->subHour()) >= self::MAX_REQUESTS_PER_HOUR) {
            throw OtpRateLimitedException::create();
        }

        $this->otps->invalidateActive($phone, $purpose);

        $code = $this->generateCode();

        $otp = $this->otps->create(
            $phone,
            $purpose,
            Hash::make($code),
            now()->addSeconds(self::EXPIRY_SECONDS),
        );

        // SMS delivery is suppressed for phones without a customer account
        // (cost control). The full OTP lifecycle above still runs so the
        // response, cooldown, and rate-limit behavior remain identical for
        // known and unknown phones (no account enumeration).
        if ($this->phoneHasAccount($phone)) {
            $this->sms->send($phone, $this->message($code));
        }

        return $otp;
    }

    public function verify(string $phone, OtpPurpose $purpose, string $code): PhoneOtp
    {
        $otp = $this->otps->findActive($phone, $purpose);

        if ($otp === null) {
            $latest = $this->otps->latestFor($phone, $purpose);

            if ($latest !== null) {
                throw OtpExpiredException::create();
            }

            throw OtpInvalidException::create();
        }

        if ($otp->attempts >= self::MAX_FAILED_ATTEMPTS) {
            $this->otps->invalidate($otp);

            throw OtpAttemptsExceededException::create();
        }

        if (! Hash::check($code, $otp->otp_hash)) {
            // A code from a superseded/consumed OTP is expired, not wrong (API §2.2.2).
            if ($this->matchesTerminalOtp($phone, $purpose, $code)) {
                throw OtpExpiredException::create();
            }

            $otp = $this->otps->incrementAttempts($otp);

            if ($otp->attempts >= self::MAX_FAILED_ATTEMPTS) {
                $this->otps->invalidate($otp);

                throw OtpAttemptsExceededException::create();
            }

            throw OtpInvalidException::create();
        }

        return $this->otps->markConsumed($otp);
    }

    private function phoneHasAccount(string $phone): bool
    {
        return User::query()->where('phone', $phone)->exists();
    }

    private function matchesTerminalOtp(string $phone, OtpPurpose $purpose, string $code): bool
    {
        return $this->otps
            ->recentTerminal($phone, $purpose, now()->subHour())
            ->contains(fn (PhoneOtp $otp): bool => Hash::check($code, $otp->otp_hash));
    }

    private function generateCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    private function message(string $code): string
    {
        return "Your Fayadhowr verification code is {$code}. It expires in 5 minutes. Do not share this code.";
    }
}
