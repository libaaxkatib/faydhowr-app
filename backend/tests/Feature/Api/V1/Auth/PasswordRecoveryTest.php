<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Contracts\Sms\SmsSenderInterface;
use App\Enums\Auth\OtpPurpose;
use App\Mail\PasswordResetTokenMail;
use App\Models\CustomerProfile;
use App\Models\PasswordResetToken;
use App\Models\PhoneOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeSmsSender;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsSender $sms;

    private const string PHONE = '+252611234567';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSenderInterface::class, $this->sms);
    }

    private function createCustomer(array $userAttributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => 'customer@example.com',
            'phone' => self::PHONE,
            'password' => Hash::make('old-password'),
        ], $userAttributes));

        CustomerProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function capturedMailToken(): string
    {
        $token = null;

        Mail::assertSent(PasswordResetTokenMail::class, function (PasswordResetTokenMail $mail) use (&$token): bool {
            $token = $mail->token;

            return true;
        });

        return $token;
    }

    public function test_forgot_password_by_email_creates_hashed_token_and_sends_mail(): void
    {
        $user = $this->createCustomer();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'customer@example.com']);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'If this account exists, recovery instructions have been sent.');

        Mail::assertSent(PasswordResetTokenMail::class);

        $record = PasswordResetToken::query()->sole();
        $token = $this->capturedMailToken();

        $this->assertSame($user->id, $record->subject_id);
        $this->assertNotSame($token, $record->token_hash);
        $this->assertTrue(Hash::check($token, $record->token_hash));
    }

    public function test_forgot_password_response_is_identical_for_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If this account exists, recovery instructions have been sent.');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_forgot_password_requires_exactly_one_identifier(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', [])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'customer@example.com',
            'phone' => self::PHONE,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_new_email_token_invalidates_prior_tokens(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'customer@example.com'])->assertOk();
        $firstRecord = PasswordResetToken::query()->sole();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'customer@example.com'])->assertOk();

        $this->assertNotNull($firstRecord->fresh()->used_at);
        $this->assertSame(2, PasswordResetToken::query()->count());
    }

    public function test_password_can_be_reset_with_a_valid_email_token(): void
    {
        $user = $this->createCustomer();
        $user->createToken('customer-mobile');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'customer@example.com'])->assertOk();
        $token = $this->capturedMailToken();

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'customer@example.com',
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Password has been reset.');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));

        // Global token revocation: every device must re-authenticate.
        $this->assertSame(0, $user->tokens()->count());

        $this->assertNotNull(PasswordResetToken::query()->sole()->used_at);

        $this->assertDatabaseHas('customer_activity_logs', [
            'customer_profile_id' => $user->customerProfile->id,
            'event_type' => 'password_reset',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'new-password-123',
        ])->assertOk();
    }

    public function test_reset_with_an_invalid_email_token_is_rejected(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'customer@example.com',
            'token' => 'not-a-real-token',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'RESET_TOKEN_INVALID');
    }

    public function test_email_reset_token_is_single_use(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'customer@example.com'])->assertOk();
        $token = $this->capturedMailToken();

        $payload = [
            'email' => 'customer@example.com',
            'token' => $token,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();

        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'RESET_TOKEN_INVALID');
    }

    public function test_expired_email_token_is_rejected(): void
    {
        $user = $this->createCustomer();

        PasswordResetToken::factory()->expired()->create([
            'subject_id' => $user->id,
            'token_hash' => Hash::make('expired-token'),
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'customer@example.com',
            'token' => 'expired-token',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'RESET_TOKEN_INVALID');
    }

    public function test_forgot_password_by_phone_issues_password_reset_otp(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/forgot-password', ['phone' => self::PHONE])
            ->assertOk()
            ->assertJsonPath('message', 'If this account exists, recovery instructions have been sent.');

        $this->assertDatabaseHas('phone_otps', [
            'phone' => self::PHONE,
            'purpose' => OtpPurpose::PasswordReset->value,
        ]);

        $this->assertNotNull($this->sms->lastCodeFor(self::PHONE));
    }

    public function test_password_can_be_reset_with_a_valid_phone_otp(): void
    {
        $user = $this->createCustomer();
        $user->createToken('customer-mobile');

        $this->postJson('/api/v1/auth/forgot-password', ['phone' => self::PHONE])->assertOk();
        $code = $this->sms->lastCodeFor(self::PHONE);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'phone' => self::PHONE,
            'token' => $code,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Password has been reset.');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_reset_with_a_wrong_phone_otp_is_rejected(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/forgot-password', ['phone' => self::PHONE])->assertOk();
        $code = $this->sms->lastCodeFor(self::PHONE);
        $wrongCode = $code === '111111' ? '222222' : '111111';

        $this->postJson('/api/v1/auth/reset-password', [
            'phone' => self::PHONE,
            'token' => $wrongCode,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'RESET_TOKEN_INVALID');
    }

    public function test_forgot_password_for_unknown_phone_returns_generic_response_without_sms(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['phone' => '+252619999999'])
            ->assertOk()
            ->assertJsonPath('message', 'If this account exists, recovery instructions have been sent.');

        // OTP lifecycle runs (identical throttling) but no SMS is delivered.
        $this->assertDatabaseHas('phone_otps', [
            'phone' => '+252619999999',
            'purpose' => OtpPurpose::PasswordReset->value,
        ]);
        $this->assertSame(0, $this->sms->messagesTo('+252619999999'));
    }

    public function test_reset_for_a_phone_without_an_account_is_rejected(): void
    {
        // Factory default hash corresponds to code 123456.
        PhoneOtp::factory()->passwordReset()->create(['phone' => '+252619999999']);

        $this->postJson('/api/v1/auth/reset-password', [
            'phone' => '+252619999999',
            'token' => '123456',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'RESET_TOKEN_INVALID');
    }

    public function test_forgot_password_phone_path_enforces_otp_cooldown(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/forgot-password', ['phone' => self::PHONE])->assertOk();

        $this->postJson('/api/v1/auth/forgot-password', ['phone' => self::PHONE])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'OTP_COOLDOWN');
    }

    public function test_reset_password_requires_matching_confirmation(): void
    {
        $this->createCustomer();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'customer@example.com',
            'token' => 'anything',
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }
}
