<?php

namespace App\Contracts\Auth;

use App\DataTransferObjects\Auth\GoogleUserData;
use App\Exceptions\Auth\GoogleTokenInvalidException;

interface GoogleIdTokenVerifierInterface
{
    /**
     * Verify a Google ID token server-side (signature, expiry, audience).
     *
     * @throws GoogleTokenInvalidException
     */
    public function verify(string $idToken): GoogleUserData;
}
