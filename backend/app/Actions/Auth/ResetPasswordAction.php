<?php

namespace App\Actions\Auth;

use App\Contracts\Auth\Repositories\PasswordResetTokenRepositoryInterface;
use App\Contracts\Auth\Services\OtpServiceInterface;
use App\Contracts\Customer\Services\CustomerActivityServiceInterface;
use App\Enums\Auth\OtpPurpose;
use App\Enums\Customer\ActivityType;
use App\Exceptions\Auth\OtpAttemptsExceededException;
use App\Exceptions\Auth\OtpExpiredException;
use App\Exceptions\Auth\OtpInvalidException;
use App\Exceptions\Auth\ResetTokenInvalidException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResetPasswordAction
{
    public function __construct(
        private PasswordResetTokenRepositoryInterface $tokens,
        private OtpServiceInterface $otp,
        private CustomerActivityServiceInterface $activities,
    ) {}

    /**
     * Complete recovery via the email token path.
     *
     * @throws ResetTokenInvalidException
     */
    public function handleEmail(string $email, string $token, string $password): void
    {
        $user = User::query()
            ->with('customerProfile')
            ->where('email', Str::lower($email))
            ->first();

        if ($user === null) {
            throw ResetTokenInvalidException::create();
        }

        $record = $this->tokens->findUsableForUser($user);

        if ($record === null || ! Hash::check($token, $record->token_hash)) {
            throw ResetTokenInvalidException::create();
        }

        DB::transaction(function () use ($user, $record, $password): void {
            $this->tokens->markUsed($record);
            $this->completeReset($user, $password);
        });
    }

    /**
     * Complete recovery via the phone OTP path. OTP failures surface as
     * RESET_TOKEN_INVALID on this endpoint (API Design §2.5).
     *
     * @throws ResetTokenInvalidException
     */
    public function handlePhone(string $phone, string $otpCode, string $password): void
    {
        $user = User::query()
            ->with('customerProfile')
            ->where('phone', $phone)
            ->first();

        if ($user === null) {
            throw ResetTokenInvalidException::create();
        }

        try {
            $this->otp->verify($phone, OtpPurpose::PasswordReset, $otpCode);
        } catch (OtpInvalidException|OtpExpiredException|OtpAttemptsExceededException) {
            throw ResetTokenInvalidException::create();
        }

        DB::transaction(function () use ($user, $password): void {
            $this->completeReset($user, $password);
        });
    }

    private function completeReset(User $user, string $password): void
    {
        $user->forceFill(['password' => Hash::make($password)])->save();

        // Global revocation: every device must re-authenticate (FR-003B).
        $user->tokens()->delete();

        $profile = $user->customerProfile;

        if ($profile !== null) {
            $this->activities->record($profile, ActivityType::PasswordReset, 'Password reset');
        }
    }
}
