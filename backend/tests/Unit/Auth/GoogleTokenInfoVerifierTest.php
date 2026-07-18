<?php

namespace Tests\Unit\Auth;

use App\Exceptions\Auth\GoogleTokenInvalidException;
use App\Services\Auth\GoogleTokenInfoVerifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleTokenInfoVerifierTest extends TestCase
{
    private GoogleTokenInfoVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_ids', ['expected-client-id']);

        $this->verifier = new GoogleTokenInfoVerifier;
    }

    public function test_valid_token_returns_google_user_data(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'sub' => 'google-sub-1',
                'aud' => 'expected-client-id',
                'email' => 'customer@example.com',
                'email_verified' => 'true',
                'name' => 'Customer Name',
            ]),
        ]);

        $data = $this->verifier->verify('valid-token');

        $this->assertSame('google-sub-1', $data->subject);
        $this->assertSame('customer@example.com', $data->email);
        $this->assertTrue($data->emailVerified);
        $this->assertSame('Customer Name', $data->name);
    }

    public function test_rejected_token_throws(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_token'], 400),
        ]);

        $this->expectException(GoogleTokenInvalidException::class);

        $this->verifier->verify('bad-token');
    }

    public function test_wrong_audience_throws(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'sub' => 'google-sub-1',
                'aud' => 'someone-elses-client-id',
                'email' => 'customer@example.com',
                'email_verified' => 'true',
            ]),
        ]);

        $this->expectException(GoogleTokenInvalidException::class);

        $this->verifier->verify('foreign-token');
    }

    public function test_missing_configured_client_ids_throws(): void
    {
        config()->set('services.google.client_ids', []);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'sub' => 'google-sub-1',
                'aud' => 'expected-client-id',
            ]),
        ]);

        $this->expectException(GoogleTokenInvalidException::class);

        $this->verifier->verify('valid-token');
    }
}
