<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Contracts\Sms\SmsSenderInterface;
use App\Enums\Auth\OtpPurpose;
use App\Enums\Customer\CustomerStatus;
use App\Models\CustomerProfile;
use App\Models\PhoneOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeSmsSender;
use Tests\TestCase;

class PhoneOtpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsSender $sms;

    private const string PHONE = '+252611234567';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSenderInterface::class, $this->sms);
    }

    private function createCustomer(array $userAttributes = [], array $profileAttributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'phone' => self::PHONE,
            'phone_verified_at' => null,
        ], $userAttributes));

        CustomerProfile::factory()->create(array_merge([
            'user_id' => $user->id,
        ], $profileAttributes));

        return $user;
    }

    public function test_otp_request_issues_hashed_otp_and_returns_generic_acknowledgement(): void
    {
        $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE]);

        $response
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'If this phone number is registered, a code has been sent.',
                'data' => ['expires_in' => 300, 'resend_after' => 60],
                'meta' => null,
            ]);

        $otp = PhoneOtp::query()->sole();
        $code = $this->sms->lastCodeFor(self::PHONE);

        $this->assertSame(self::PHONE, $otp->phone);
        $this->assertSame(OtpPurpose::Login, $otp->purpose);
        $this->assertNotNull($code);
        $this->assertNotSame($code, $otp->otp_hash);
        $this->assertTrue(Hash::check($code, $otp->otp_hash));
    }

    public function test_otp_request_response_is_identical_for_unknown_phone_but_no_sms_is_sent(): void
    {
        $response = $this->postJson('/api/v1/auth/phone/request', ['phone' => '+252619999999']);

        $response
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'If this phone number is registered, a code has been sent.',
                'data' => ['expires_in' => 300, 'resend_after' => 60],
                'meta' => null,
            ]);

        // Delivery is suppressed for unregistered phones, but the OTP
        // lifecycle still runs so throttling behavior stays identical.
        $this->assertSame(0, $this->sms->messagesTo('+252619999999'));
        $this->assertSame(1, PhoneOtp::query()->where('phone', '+252619999999')->count());

        $this->postJson('/api/v1/auth/phone/request', ['phone' => '+252619999999'])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'OTP_COOLDOWN');
    }

    public function test_otp_request_rejects_malformed_phone(): void
    {
        $this->postJson('/api/v1/auth/phone/request', ['phone' => '0611234567'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_otp_resend_within_cooldown_is_rejected(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE])->assertOk();

        $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'OTP_COOLDOWN');
    }

    public function test_otp_resend_after_cooldown_invalidates_previous_otp(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE])->assertOk();
        $firstOtp = PhoneOtp::query()->sole();
        $firstCode = $this->sms->lastCodeFor(self::PHONE);

        $this->travel(61)->seconds();

        $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE])->assertOk();

        $this->assertNotNull($firstOtp->fresh()->invalidated_at);
        $this->assertSame(2, PhoneOtp::query()->count());

        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'otp' => $firstCode])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'OTP_EXPIRED');
    }

    public function test_otp_request_hourly_cap_is_enforced(): void
    {
        $this->createCustomer();

        PhoneOtp::factory()->count(5)->create([
            'phone' => self::PHONE,
            'purpose' => OtpPurpose::Login,
            'invalidated_at' => now(),
            'created_at' => now()->subMinutes(10),
        ]);

        $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'RATE_LIMITED');
    }

    public function test_otp_verify_logs_the_customer_in(): void
    {
        $user = $this->createCustomer();

        $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE])->assertOk();
        $code = $this->sms->lastCodeFor(self::PHONE);

        $response = $this->postJson('/api/v1/auth/phone/verify', [
            'phone' => self::PHONE,
            'otp' => $code,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['user', 'access_token', 'token_type']]);

        $user->refresh();
        $this->assertNotNull($user->phone_verified_at);
        $this->assertNotNull($user->last_login_at);

        $this->assertNotNull(PhoneOtp::query()->sole()->consumed_at);

        $this->assertDatabaseHas('customer_activity_logs', [
            'customer_profile_id' => $user->customerProfile->id,
            'event_type' => 'login',
        ]);
    }

    public function test_otp_verify_with_wrong_code_increments_attempts(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE])->assertOk();
        $code = $this->sms->lastCodeFor(self::PHONE);
        $wrongCode = $code === '111111' ? '222222' : '111111';

        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'otp' => $wrongCode])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'OTP_INVALID');

        $this->assertSame(1, PhoneOtp::query()->sole()->attempts);
    }

    public function test_otp_is_invalidated_after_five_failed_attempts(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE])->assertOk();
        $code = $this->sms->lastCodeFor(self::PHONE);
        $wrongCode = $code === '111111' ? '222222' : '111111';

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'otp' => $wrongCode])
                ->assertUnauthorized()
                ->assertJsonPath('error_code', 'OTP_INVALID');
        }

        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'otp' => $wrongCode])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'OTP_ATTEMPTS_EXCEEDED');

        $this->assertNotNull(PhoneOtp::query()->sole()->invalidated_at);

        // Even the correct code is unusable once the OTP is invalidated.
        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'otp' => $code])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'OTP_EXPIRED');
    }

    public function test_expired_otp_is_rejected(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE])->assertOk();
        $code = $this->sms->lastCodeFor(self::PHONE);

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'otp' => $code])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'OTP_EXPIRED');
    }

    public function test_consumed_otp_cannot_be_replayed(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE])->assertOk();
        $code = $this->sms->lastCodeFor(self::PHONE);

        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'otp' => $code])->assertOk();

        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'otp' => $code])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'OTP_EXPIRED');
    }

    public function test_verify_without_any_requested_otp_is_rejected(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'otp' => '123456'])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'OTP_INVALID');
    }

    public function test_verify_for_phone_without_an_account_is_rejected(): void
    {
        // Factory default hash corresponds to code 123456.
        PhoneOtp::factory()->create(['phone' => '+252619999999']);

        $this->postJson('/api/v1/auth/phone/verify', ['phone' => '+252619999999', 'otp' => '123456'])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'OTP_INVALID');
    }

    public function test_blocked_customer_cannot_login_with_otp(): void
    {
        $this->createCustomer(profileAttributes: ['status' => CustomerStatus::Blocked]);

        $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE])->assertOk();
        $code = $this->sms->lastCodeFor(self::PHONE);

        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'otp' => $code])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ACCOUNT_SUSPENDED');
    }

    public function test_phone_login_endpoints_respect_the_feature_flag(): void
    {
        config()->set('auth_features.phone_otp_login', false);

        $this->postJson('/api/v1/auth/phone/request', ['phone' => self::PHONE])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'AUTH_METHOD_DISABLED');

        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'otp' => '123456'])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'AUTH_METHOD_DISABLED');
    }

    public function test_verify_validates_otp_format(): void
    {
        $this->postJson('/api/v1/auth/phone/verify', ['phone' => self::PHONE, 'otp' => '12ab56'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['otp']);
    }
}
