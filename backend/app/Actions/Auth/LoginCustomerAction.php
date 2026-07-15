<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginCustomerAction
{
    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array{user: User, access_token: string, token_type: string}|null
     */
    public function handle(array $credentials): ?array
    {
        $user = User::query()
            ->where('email', Str::lower($credentials['email']))
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        return [
            'user' => $user,
            'access_token' => $user->createToken('customer-mobile')->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }
}
