<?php

namespace App\Services\Auth;

use App\Contracts\Auth\GoogleIdTokenVerifierInterface;
use App\DataTransferObjects\Auth\GoogleUserData;
use App\Exceptions\Auth\GoogleTokenInvalidException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies Google ID tokens via Google's tokeninfo endpoint, which validates
 * signature and expiry server-side; the audience is checked locally against
 * the configured client IDs (environment configuration only).
 *
 * Implementation note: offline JWKS verification (validating the token
 * signature locally against Google's published keys) may replace this
 * endpoint-based approach in the future. Consumers depend only on
 * GoogleIdTokenVerifierInterface, so swapping the implementation requires
 * no changes to authentication business logic.
 */
class GoogleTokenInfoVerifier implements GoogleIdTokenVerifierInterface
{
    private const string TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    public function verify(string $idToken): GoogleUserData
    {
        try {
            $response = Http::timeout(10)->get(self::TOKENINFO_URL, ['id_token' => $idToken]);
        } catch (Throwable) {
            throw GoogleTokenInvalidException::create();
        }

        if (! $response->successful()) {
            throw GoogleTokenInvalidException::create();
        }

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['sub']) || ! is_string($payload['sub'])) {
            throw GoogleTokenInvalidException::create();
        }

        $allowedAudiences = (array) config('services.google.client_ids', []);

        if ($allowedAudiences === [] || ! in_array($payload['aud'] ?? null, $allowedAudiences, true)) {
            throw GoogleTokenInvalidException::create();
        }

        return new GoogleUserData(
            subject: $payload['sub'],
            email: isset($payload['email']) && is_string($payload['email']) ? $payload['email'] : null,
            emailVerified: ($payload['email_verified'] ?? null) === 'true' || ($payload['email_verified'] ?? null) === true,
            name: isset($payload['name']) && is_string($payload['name']) ? $payload['name'] : null,
        );
    }
}
