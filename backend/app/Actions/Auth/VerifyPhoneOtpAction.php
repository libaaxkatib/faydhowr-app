<?php

namespace App\Actions\Auth;

use App\Contracts\Auth\Services\OtpServiceInterface;
use App\Enums\Auth\OtpPurpose;
use App\Exceptions\Auth\AccountRestrictedException;
use App\Exceptions\Auth\OtpAttemptsExceededException;
use App\Exceptions\Auth\OtpExpiredException;
use App\Exceptions\Auth\OtpInvalidException;
use App\Models\User;
use App\Support\Auth\CustomerAuthenticator;

class VerifyPhoneOtpAction
{
    public function __construct(
        private OtpServiceInterface $otp,
        private CustomerAuthenticator $authenticator,
    ) {}

    /**
     * @return array{user: User, access_token: string, token_type: string}
     *
     * @throws OtpInvalidException
     * @throws OtpExpiredException
     * @throws OtpAttemptsExceededException
     * @throws AccountRestrictedException
     */
    public function handle(string $phone, string $code): array
    {
        $this->otp->verify($phone, OtpPurpose::Login, $code);

        $user = User::query()
            ->with('customerProfile')
            ->where('phone', $phone)
            ->first();

        if ($user === null) {
            throw OtpInvalidException::create();
        }

        if (! $this->authenticator->passesGates($user)) {
            throw AccountRestrictedException::create();
        }

        if ($user->phone_verified_at === null) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        return $this->authenticator->completeLogin($user, 'Customer login (phone OTP)');
    }
}
