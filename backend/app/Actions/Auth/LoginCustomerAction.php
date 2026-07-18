<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Auth\CustomerAuthenticator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginCustomerAction
{
    public function __construct(private CustomerAuthenticator $authenticator) {}

    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array{user: User, access_token: string, token_type: string}|null
     */
    public function handle(array $credentials): ?array
    {
        $user = User::query()
            ->with('customerProfile')
            ->where('email', Str::lower($credentials['email']))
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        if (! $this->authenticator->passesGates($user)) {
            return null;
        }

        return $this->authenticator->completeLogin($user);
    }
}
