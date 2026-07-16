<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ValueError;

class NotificationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_defaults_to_pending_status(): void
    {
        $admin = Admin::factory()->create();

        $notification = Notification::query()->create([
            'recipient_type' => Admin::class,
            'recipient_id' => $admin->id,
            'type' => NotificationType::System,
            'channel' => NotificationChannel::InApp,
            'title' => 'Welcome',
            'message' => 'Your account is ready.',
        ]);

        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertNull($notification->read_at);
        $this->assertNull($notification->sent_at);
        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => NotificationStatus::Pending->value,
        ]);
    }

    public function test_notification_belongs_to_admin_recipient_polymorphically(): void
    {
        $admin = Admin::factory()->create();

        $notification = Notification::factory()->forAdmin($admin)->create([
            'type' => NotificationType::Inventory,
            'channel' => NotificationChannel::Email,
        ]);

        $notification->load('recipient');

        $this->assertInstanceOf(Admin::class, $notification->recipient);
        $this->assertTrue($notification->recipient->is($admin));
        $this->assertSame(Admin::class, $notification->recipient_type);
        $this->assertSame($admin->id, $notification->recipient_id);
    }

    public function test_notification_belongs_to_customer_profile_recipient_polymorphically(): void
    {
        $profile = CustomerProfile::factory()->create();

        $notification = Notification::factory()->forCustomerProfile($profile)->create([
            'type' => NotificationType::Booking,
            'channel' => NotificationChannel::Sms,
        ]);

        $notification->load('recipient');

        $this->assertInstanceOf(CustomerProfile::class, $notification->recipient);
        $this->assertTrue($notification->recipient->is($profile));
        $this->assertSame(CustomerProfile::class, $notification->recipient_type);
        $this->assertSame($profile->id, $notification->recipient_id);
    }

    public function test_read_status_persists_read_at_timestamp(): void
    {
        $readAt = now()->subMinute()->startOfSecond();

        $notification = Notification::factory()->read()->create([
            'read_at' => $readAt,
        ]);

        $this->assertSame(NotificationStatus::Read, $notification->fresh()->status);
        $this->assertTrue($notification->fresh()->read_at->equalTo($readAt));
    }

    public function test_notification_casts_enums_and_data_payload(): void
    {
        $notification = Notification::factory()->create([
            'type' => NotificationType::StoreOrder,
            'channel' => NotificationChannel::InApp,
            'status' => NotificationStatus::Sent,
            'data' => ['store_order_id' => 42, 'number' => 'STO-2026-000001'],
            'sent_at' => now(),
        ]);

        $fresh = $notification->fresh();

        $this->assertSame(NotificationType::StoreOrder, $fresh->type);
        $this->assertSame(NotificationChannel::InApp, $fresh->channel);
        $this->assertSame(NotificationStatus::Sent, $fresh->status);
        $this->assertSame([
            'store_order_id' => 42,
            'number' => 'STO-2026-000001',
        ], $fresh->data);
    }

    public function test_enums_reject_invalid_values(): void
    {
        $this->expectException(ValueError::class);

        NotificationType::from('invalid_type');
    }

    public function test_channel_and_status_enums_expose_approved_cases(): void
    {
        $this->assertSame(
            ['in_app', 'email', 'sms'],
            array_column(NotificationChannel::cases(), 'value'),
        );

        $this->assertSame(
            ['pending', 'processing', 'sent', 'delivered', 'read', 'failed'],
            array_column(NotificationStatus::cases(), 'value'),
        );

        $this->assertSame(
            ['booking', 'quotation', 'order', 'payment', 'store_order', 'inventory', 'system'],
            array_column(NotificationType::cases(), 'value'),
        );
    }

    public function test_factory_pending_default_leaves_delivery_timestamps_null(): void
    {
        $notification = Notification::factory()->create();

        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertNull($notification->processing_started_at);
        $this->assertNull($notification->read_at);
        $this->assertNull($notification->sent_at);
        $this->assertNull($notification->delivered_at);
        $this->assertNull($notification->failed_at);
    }
}
