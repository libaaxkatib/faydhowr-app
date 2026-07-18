<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Contracts\Auth\GoogleIdTokenVerifierInterface;
use App\DataTransferObjects\Auth\GoogleUserData;
use App\Enums\Customer\CustomerStatus;
use App\Exceptions\Auth\GoogleTokenInvalidException;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private function fakeVerifier(GoogleUserData $data): void
    {
        $this->app->instance(GoogleIdTokenVerifierInterface::class, new class($data) implements GoogleIdTokenVerifierInterface
        {
            public function __construct(private GoogleUserData $data) {}

            public function verify(string $idToken): GoogleUserData
            {
                return $this->data;
            }
        });
    }

    private function fakeFailingVerifier(): void
    {
        $this->app->instance(GoogleIdTokenVerifierInterface::class, new class implements GoogleIdTokenVerifierInterface
        {
            public function verify(string $idToken): GoogleUserData
            {
                throw GoogleTokenInvalidException::create();
            }
        });
    }

    public function test_existing_google_subject_can_sign_in(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['google_subject' => 'google-sub-1'])->save();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $this->fakeVerifier(new GoogleUserData('google-sub-1', $user->email, true, $user->name));

        $response = $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token']);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_verified_email_match_links_google_subject(): void
    {
        $user = User::factory()->create(['email' => 'linked@example.com']);
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $this->fakeVerifier(new GoogleUserData('google-sub-2', 'linked@example.com', true, $user->name));

        $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertSame('google-sub-2', $user->fresh()->google_subject);
    }

    public function test_unverified_email_never_links_or_duplicates_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'linked@example.com']);
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $this->fakeVerifier(new GoogleUserData('google-sub-3', 'linked@example.com', false, $user->name));

        $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token'])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'GOOGLE_TOKEN_INVALID');

        $this->assertNull($user->fresh()->google_subject);
        $this->assertSame(1, User::query()->count());
    }

    public function test_unknown_google_account_is_auto_provisioned(): void
    {
        $this->fakeVerifier(new GoogleUserData('google-sub-4', 'new.customer@example.com', true, 'New Customer'));

        $response = $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token']);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.email', 'new.customer@example.com')
            ->assertJsonStructure(['data' => ['user', 'access_token', 'token_type']]);

        $user = User::query()->where('email', 'new.customer@example.com')->sole();

        $this->assertSame('google-sub-4', $user->google_subject);
        $this->assertNotNull($user->email_verified_at);

        $profile = $user->customerProfile;
        $this->assertNotNull($profile);
        $this->assertMatchesRegularExpression('/^CUS-\d{6}$/', $profile->customer_number);
        $this->assertSame(CustomerStatus::Active, $profile->status);

        $this->assertDatabaseHas('customer_activity_logs', [
            'customer_profile_id' => $profile->id,
            'event_type' => 'registration',
        ]);

        $this->assertDatabaseHas('customer_activity_logs', [
            'customer_profile_id' => $profile->id,
            'event_type' => 'login',
        ]);
    }

    public function test_invalid_google_token_is_rejected(): void
    {
        $this->fakeFailingVerifier();

        $this->postJson('/api/v1/auth/google', ['id_token' => 'bad-token'])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'GOOGLE_TOKEN_INVALID');

        $this->assertSame(0, User::query()->count());
    }

    public function test_blocked_customer_cannot_sign_in_with_google(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['google_subject' => 'google-sub-5'])->save();
        CustomerProfile::factory()->blocked()->create(['user_id' => $user->id]);

        $this->fakeVerifier(new GoogleUserData('google-sub-5', $user->email, true, $user->name));

        $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_SUSPENDED');
    }

    public function test_google_login_respects_the_feature_flag(): void
    {
        config()->set('auth_features.google_login', false);

        $this->postJson('/api/v1/auth/google', ['id_token' => 'valid-token'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'AUTH_METHOD_DISABLED');
    }

    public function test_google_login_requires_an_id_token(): void
    {
        $this->postJson('/api/v1/auth/google', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id_token']);
    }
}
