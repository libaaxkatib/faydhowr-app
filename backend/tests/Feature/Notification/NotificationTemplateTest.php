<?php

namespace Tests\Feature\Notification;

use App\Actions\Notification\RenderNotificationTemplateAction;
use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTemplateStatus;
use App\Enums\NotificationType;
use App\Events\Notification\NotificationRequested;
use App\Jobs\Notification\ProcessNotificationJob;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_list_show_and_update_templates(): void
    {
        // Remove the migration-seeded Sprint 27 operational templates so the
        // pagination and ordering assertions below stay deterministic.
        DB::table('notification_templates')->delete();

        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $create = $this
            ->withToken($token)
            ->postJson('/api/v1/admin/notification-templates', [
                'template_key' => 'booking_confirmed',
                'name' => 'Booking Confirmed',
                'type' => NotificationType::Booking->value,
                'channel' => NotificationChannel::InApp->value,
                'language' => 'en',
                'title' => 'Hello {{customer_name}}',
                'message' => 'Booking {{booking_number}} is confirmed.',
                'status' => NotificationTemplateStatus::Active->value,
                'variables' => ['customer_name', 'booking_number'],
            ]);

        $create
            ->assertCreated()
            ->assertJsonPath('data.template_key', 'booking_confirmed')
            ->assertJsonPath('data.type', NotificationType::Booking->value)
            ->assertJsonPath('data.channel', NotificationChannel::InApp->value)
            ->assertJsonPath('data.language', 'en')
            ->assertJsonPath('data.status', NotificationTemplateStatus::Active->value)
            ->assertJsonMissingPath('data.title')
            ->assertJsonMissingPath('data.message')
            ->assertJsonMissingPath('data.subject');

        $id = $create->json('data.id');

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/notification-templates')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.template_key', 'booking_confirmed');

        $this
            ->withToken($token)
            ->getJson("/api/v1/admin/notification-templates/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'Booking Confirmed');

        $this
            ->withToken($token)
            ->putJson("/api/v1/admin/notification-templates/{$id}", [
                'name' => 'Booking Confirmed Updated',
                'status' => NotificationTemplateStatus::Inactive->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Booking Confirmed Updated')
            ->assertJsonPath('data.status', NotificationTemplateStatus::Inactive->value);
    }

    public function test_template_validation_rejects_invalid_payloads(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->postJson('/api/v1/admin/notification-templates', [
                'template_key' => 'bad key',
                'name' => 'Bad',
                'type' => 'not-a-type',
                'channel' => 'push',
                'title' => 'Title',
                'message' => 'Message',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_notifications_manage_permission_is_required(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Manager,
        ]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/notification-templates')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->grantPermissions($admin, [AdminPermission::NotificationsManage]);

        $this->app['auth']->forgetGuards();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/notification-templates')
            ->assertOk();
    }

    public function test_render_replaces_known_variables_and_leaves_unknown_placeholders(): void
    {
        NotificationTemplate::factory()->create([
            'template_key' => 'order_paid',
            'title' => 'Hi {{customer_name}}',
            'message' => 'Order {{order_number}} paid {{amount}} on {{date}}. Missing {{unknown_token}}.',
            'variables' => ['customer_name', 'order_number', 'amount', 'date'],
        ]);

        $rendered = app(RenderNotificationTemplateAction::class)->handle('order_paid', [
            'customer_name' => 'Amina',
            'order_number' => 'ORD-2026-000001',
            'amount' => '95.00',
            'date' => '2026-07-16',
        ]);

        $this->assertSame('Hi Amina', $rendered['title']);
        $this->assertSame(
            'Order ORD-2026-000001 paid 95.00 on 2026-07-16. Missing {{unknown_token}}.',
            $rendered['message'],
        );
    }

    public function test_inactive_template_cannot_be_rendered(): void
    {
        NotificationTemplate::factory()->inactive()->create([
            'template_key' => 'inactive_template',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('TEMPLATE_INACTIVE');

        app(RenderNotificationTemplateAction::class)->handle('inactive_template', [
            'customer_name' => 'Amina',
        ]);
    }

    public function test_notification_requested_renders_template_before_persistence(): void
    {
        Bus::fake([ProcessNotificationJob::class]);

        NotificationTemplate::factory()->create([
            'template_key' => 'quotation_ready',
            'type' => NotificationType::Quotation,
            'channel' => NotificationChannel::Email,
            'subject' => 'Quote {{quotation_number}}',
            'title' => 'Quote ready for {{customer_name}}',
            'message' => 'Quotation {{quotation_number}} is ready. Amount {{amount}}.',
        ]);

        $profile = CustomerProfile::factory()->create();

        event(NotificationRequested::make(
            recipient: $profile,
            templateKey: 'quotation_ready',
            variables: [
                'customer_name' => 'Hassan',
                'quotation_number' => 'QT-2026-000010',
                'amount' => '120.00',
            ],
        ));

        $notification = Notification::query()->firstOrFail();

        $this->assertSame(NotificationType::Quotation, $notification->type);
        $this->assertSame(NotificationChannel::Email, $notification->channel);
        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertSame('Quote ready for Hassan', $notification->title);
        $this->assertSame(
            'Quotation QT-2026-000010 is ready. Amount 120.00.',
            $notification->message,
        );
        $this->assertSame('quotation_ready', $notification->data['template_key']);
        $this->assertSame('Quote QT-2026-000010', $notification->data['subject']);
        $this->assertSame('Hassan', $notification->data['variables']['customer_name']);

        Bus::assertDispatched(ProcessNotificationJob::class);
    }

    public function test_notification_requested_rejects_inactive_template(): void
    {
        Bus::fake([ProcessNotificationJob::class]);

        NotificationTemplate::factory()->inactive()->create([
            'template_key' => 'inactive_dispatch',
        ]);

        try {
            event(NotificationRequested::make(
                recipient: Admin::factory()->create(),
                templateKey: 'inactive_dispatch',
                variables: ['customer_name' => 'Amina'],
            ));
            $this->fail('Expected TEMPLATE_INACTIVE DomainException was not thrown.');
        } catch (DomainException $exception) {
            $this->assertSame('TEMPLATE_INACTIVE', $exception->getMessage());
        }

        $this->assertDatabaseCount('notifications', 0);
        Bus::assertNothingDispatched();
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
