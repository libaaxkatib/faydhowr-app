<?php

namespace Tests\Feature\Notification;

use App\Actions\Notification\RenderTranslatedNotificationTemplateAction;
use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Events\Notification\NotificationRequested;
use App\Jobs\Notification\ProcessNotificationJob;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateTranslation;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationTemplateTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_create_and_update_translations(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;
        $template = NotificationTemplate::factory()->create([
            'template_key' => 'booking_confirmed',
            'language' => 'en',
            'title' => 'Hello {{customer_name}}',
            'message' => 'Booking {{booking_number}} confirmed.',
        ]);

        $create = $this
            ->withToken($token)
            ->postJson("/api/v1/admin/notification-templates/{$template->id}/translations", [
                'language' => 'so',
                'title' => 'Salaan {{customer_name}}',
                'message' => 'Ballanka {{booking_number}} waa la xaqiijiyay.',
            ]);

        $create
            ->assertCreated()
            ->assertJsonPath('data.language', 'so')
            ->assertJsonPath('data.title', 'Salaan {{customer_name}}')
            ->assertJsonPath('data.message', 'Ballanka {{booking_number}} waa la xaqiijiyay.');

        $translationId = $create->json('data.id');

        $this
            ->withToken($token)
            ->getJson("/api/v1/admin/notification-templates/{$template->id}/translations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.language', 'so');

        $this
            ->withToken($token)
            ->putJson("/api/v1/admin/notification-templates/{$template->id}/translations/{$translationId}", [
                'title' => 'Salaan cusub {{customer_name}}',
                'message' => 'Fariin cusub {{booking_number}}.',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Salaan cusub {{customer_name}}')
            ->assertJsonPath('data.message', 'Fariin cusub {{booking_number}}.');
    }

    public function test_duplicate_language_translation_is_rejected(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $template = NotificationTemplate::factory()->create();
        NotificationTemplateTranslation::factory()->create([
            'notification_template_id' => $template->id,
            'language' => 'ar',
        ]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson("/api/v1/admin/notification-templates/{$template->id}/translations", [
                'language' => 'ar',
                'title' => 'Duplicate',
                'message' => 'Duplicate message',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'TRANSLATION_LANGUAGE_EXISTS');
    }

    public function test_translation_validation_and_authorization(): void
    {
        $template = NotificationTemplate::factory()->create();
        $manager = Admin::factory()->create([
            'role' => AdminRole::Manager,
        ]);

        $this
            ->withToken($manager->createToken('admin-panel')->plainTextToken)
            ->getJson("/api/v1/admin/notification-templates/{$template->id}/translations")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->grantPermissions($manager, [AdminPermission::NotificationsManage]);
        $this->app['auth']->forgetGuards();

        $token = $manager->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->postJson("/api/v1/admin/notification-templates/{$template->id}/translations", [
                'language' => 'fr',
                'title' => 'Invalid language',
                'message' => 'Message',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this
            ->withToken($token)
            ->getJson("/api/v1/admin/notification-templates/{$template->id}/translations")
            ->assertOk();
    }

    public function test_render_uses_requested_language_translation(): void
    {
        $template = NotificationTemplate::factory()->create([
            'template_key' => 'order_paid',
            'language' => 'en',
            'title' => 'Hello {{customer_name}}',
            'message' => 'Paid {{amount}}.',
        ]);
        NotificationTemplateTranslation::factory()->create([
            'notification_template_id' => $template->id,
            'language' => 'so',
            'title' => 'Salaan {{customer_name}}',
            'message' => 'Lacagta {{amount}} waa la helay.',
        ]);

        $rendered = app(RenderTranslatedNotificationTemplateAction::class)->handle(
            'order_paid',
            ['customer_name' => 'Amina', 'amount' => '50.00'],
            'so',
        );

        $this->assertSame('so', $rendered['language']);
        $this->assertSame('Salaan Amina', $rendered['title']);
        $this->assertSame('Lacagta 50.00 waa la helay.', $rendered['message']);
    }

    public function test_render_falls_back_to_base_template_when_translation_missing(): void
    {
        NotificationTemplate::factory()->create([
            'template_key' => 'payment_failed',
            'language' => 'en',
            'title' => 'Payment failed for {{customer_name}}',
            'message' => 'Payment {{payment_number}} failed.',
        ]);

        $rendered = app(RenderTranslatedNotificationTemplateAction::class)->handle(
            'payment_failed',
            [
                'customer_name' => 'Hassan',
                'payment_number' => 'PAY-1',
            ],
            'ar',
        );

        $this->assertSame('en', $rendered['language']);
        $this->assertSame('Payment failed for Hassan', $rendered['title']);
        $this->assertSame('Payment PAY-1 failed.', $rendered['message']);
    }

    public function test_notification_requested_uses_customer_preferred_language(): void
    {
        Bus::fake([ProcessNotificationJob::class]);

        $template = NotificationTemplate::factory()->create([
            'template_key' => 'booking_update',
            'type' => NotificationType::Booking,
            'channel' => NotificationChannel::InApp,
            'language' => 'en',
            'title' => 'Booking update',
            'message' => 'Booking {{booking_number}} updated.',
        ]);
        NotificationTemplateTranslation::factory()->create([
            'notification_template_id' => $template->id,
            'language' => 'so',
            'title' => 'Cusboonaysi ballan',
            'message' => 'Ballanka {{booking_number}} waa la cusbooneysiiyay.',
        ]);

        $profile = CustomerProfile::factory()->create([
            'preferred_language' => 'so',
        ]);

        event(NotificationRequested::make(
            recipient: $profile,
            templateKey: 'booking_update',
            variables: ['booking_number' => 'BK-42'],
        ));

        $notification = Notification::query()->firstOrFail();

        $this->assertSame('Cusboonaysi ballan', $notification->title);
        $this->assertSame('Ballanka BK-42 waa la cusbooneysiiyay.', $notification->message);
        $this->assertSame('so', $notification->data['language']);
    }

    public function test_explicit_language_overrides_customer_preferred_language(): void
    {
        Bus::fake([ProcessNotificationJob::class]);

        $template = NotificationTemplate::factory()->create([
            'template_key' => 'quote_ready',
            'type' => NotificationType::Quotation,
            'channel' => NotificationChannel::Email,
            'language' => 'en',
            'title' => 'Quote ready',
            'message' => 'Quotation {{quotation_number}} ready.',
            'subject' => 'Quote {{quotation_number}}',
        ]);
        NotificationTemplateTranslation::factory()->create([
            'notification_template_id' => $template->id,
            'language' => 'ar',
            'subject' => 'عرض {{quotation_number}}',
            'title' => 'العرض جاهز',
            'message' => 'عرض السعر {{quotation_number}} جاهز.',
        ]);

        $profile = CustomerProfile::factory()->create([
            'preferred_language' => 'so',
        ]);

        event(NotificationRequested::make(
            recipient: $profile,
            templateKey: 'quote_ready',
            variables: ['quotation_number' => 'QT-9'],
            language: 'ar',
        ));

        $notification = Notification::query()->firstOrFail();

        $this->assertSame('العرض جاهز', $notification->title);
        $this->assertSame('عرض السعر QT-9 جاهز.', $notification->message);
        $this->assertSame('عرض QT-9', $notification->data['subject']);
        $this->assertSame('ar', $notification->data['language']);
    }

    public function test_translation_not_found_for_foreign_template_pair(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $template = NotificationTemplate::factory()->create();
        $otherTemplate = NotificationTemplate::factory()->create();
        $translation = NotificationTemplateTranslation::factory()->create([
            'notification_template_id' => $otherTemplate->id,
            'language' => 'en',
        ]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/notification-templates/{$template->id}/translations/{$translation->id}", [
                'title' => 'Nope',
            ])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'TRANSLATION_NOT_FOUND');
    }

    /**
     * @param  list<AdminPermission>  $permissions
     */
    private function grantPermissions(Admin $admin, array $permissions): void
    {
        $now = now();

        DB::table('admin_permissions')->insert(
            collect($permissions)
                ->map(fn (AdminPermission $permission): array => [
                    'admin_id' => $admin->id,
                    'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
        );
    }
}
