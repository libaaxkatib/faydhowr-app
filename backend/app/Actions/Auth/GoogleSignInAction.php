<?php

namespace App\Actions\Auth;

use App\Contracts\Auth\GoogleIdTokenVerifierInterface;
use App\Contracts\Customer\Services\CustomerActivityServiceInterface;
use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\DataTransferObjects\Auth\GoogleUserData;
use App\Enums\Customer\ActivityType;
use App\Enums\Customer\CustomerStatus;
use App\Enums\UserStatus;
use App\Exceptions\Auth\AccountRestrictedException;
use App\Exceptions\Auth\GoogleTokenInvalidException;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\Auth\CustomerAuthenticator;
use App\Support\Customer\CustomerCodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GoogleSignInAction
{
    public function __construct(
        private GoogleIdTokenVerifierInterface $verifier,
        private CustomerAuthenticator $authenticator,
        private CustomerCodeGenerator $codes,
        private CustomerActivityServiceInterface $activities,
        private DashboardCacheInvalidatorInterface $dashboardCache,
    ) {}

    /**
     * @return array{user: User, access_token: string, token_type: string}
     *
     * @throws GoogleTokenInvalidException
     * @throws AccountRestrictedException
     */
    public function handle(string $idToken): array
    {
        $google = $this->verifier->verify($idToken);

        $user = $this->resolveUser($google);

        if (! $this->authenticator->passesGates($user)) {
            throw AccountRestrictedException::create();
        }

        return $this->authenticator->completeLogin($user, 'Customer login (Google)');
    }

    /**
     * Account resolution order per FR-002D:
     * (1) google_subject match, (2) verified-email link, (3) auto-provision.
     */
    private function resolveUser(GoogleUserData $google): User
    {
        $user = User::query()
            ->with('customerProfile')
            ->where('google_subject', $google->subject)
            ->first();

        if ($user !== null) {
            return $user;
        }

        if ($google->email !== null && $google->emailVerified) {
            $user = User::query()
                ->with('customerProfile')
                ->where('email', Str::lower($google->email))
                ->first();

            if ($user !== null) {
                $user->forceFill(['google_subject' => $google->subject])->save();

                return $user;
            }
        }

        return $this->provisionUser($google);
    }

    private function provisionUser(GoogleUserData $google): User
    {
        // An existing account with this email that could not be linked
        // (unverified Google email) must never be duplicated or taken over.
        if ($google->email !== null
            && User::query()->where('email', Str::lower($google->email))->exists()) {
            throw GoogleTokenInvalidException::create();
        }

        $user = DB::transaction(function () use ($google): User {
            $name = $google->name
                ?? ($google->email !== null ? Str::before($google->email, '@') : 'Google Customer');

            $user = User::query()->create([
                'name' => $name,
                'email' => $google->email !== null ? Str::lower($google->email) : null,
                'password' => Hash::make(Str::random(40)),
                'status' => UserStatus::Active,
            ]);

            $user->forceFill([
                'google_subject' => $google->subject,
                'email_verified_at' => $google->emailVerified ? now() : null,
            ])->save();

            $profile = new CustomerProfile([
                'full_name' => $name,
                'preferred_language' => 'so',
            ]);
            $profile->customer_number = $this->codes->next();
            $profile->classification = 'lead';
            $profile->status = CustomerStatus::Active;

            $user->customerProfile()->save($profile);

            $this->activities->record($profile, ActivityType::Registration, 'Account registered (Google)');

            return $user->load('customerProfile');
        });

        $this->dashboardCache->invalidate();

        return $user;
    }
}
