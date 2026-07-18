<?php

namespace App\Actions\Auth;

use App\Contracts\Auth\Repositories\PasswordResetTokenRepositoryInterface;
use App\Contracts\Auth\Services\OtpServiceInterface;
use App\Enums\Auth\OtpPurpose;
use App\Exceptions\Auth\OtpCooldownException;
use App\Exceptions\Auth\OtpRateLimitedException;
use App\Mail\PasswordResetTokenMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ForgotPasswordAction
{
    public const int TOKEN_EXPIRY_MINUTES = 60;

    public function __construct(
        private PasswordResetTokenRepositoryInterface $tokens,
        private OtpServiceInterface $otp,
    ) {}

    /**
     * Start recovery for the email path. Silently does nothing when the
     * account does not exist (no account enumeration).
     */
    public function handleEmail(string $email): void
    {
        $user = User::query()->where('email', Str::lower($email))->first();

        if ($user === null || $user->email === null) {
            return;
        }

        $this->tokens->invalidateForUser($user);

        $rawToken = Str::random(64);

        $this->tokens->create(
            $user,
            Hash::make($rawToken),
            now()->addMinutes(self::TOKEN_EXPIRY_MINUTES),
        );

        Mail::to($user->email)->send(new PasswordResetTokenMail($rawToken));
    }

    /**
     * Start recovery for the phone path. The OTP is issued regardless of
     * account existence so the response never discloses registration state.
     *
     * @throws OtpCooldownException
     * @throws OtpRateLimitedException
     */
    public function handlePhone(string $phone): void
    {
        $this->otp->issue($phone, OtpPurpose::PasswordReset);
    }
}
