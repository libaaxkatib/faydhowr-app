<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Events\Notification\NotificationRequested;
use App\Jobs\Notification\ProcessNotificationJob;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([ProcessNotificationJob::class]);
    }

    public function test_notification_requested_event_persists_a_pending_notification(): void
    {
        $admin = Admin::factory()->create();
        $this->createTemplate('system_notice', NotificationType::System, NotificationChannel::InApp);

        event(NotificationRequested::make(
            recipient: $admin,
            templateKey: 'system_notice',
            variables: [
                'customer_name' => 'Ops',
                'booking_number' => 'BK-1',
                'date' => '2026-07-16',
            ],
            data: ['source' => 'test'],
        ));

        $this->assertDatabaseCount('notifications', 1);

        $notification = Notification::query()->firstOrFail();

        $this->assertSame(Admin::class, $notification->recipient_type);
        $this->assertSame($admin->id, $notification->recipient_id);
        $this->assertSame(NotificationType::System, $notification->type);
        $this->assertSame(NotificationChannel::InApp, $notification->channel);
        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertSame('Hello Ops', $notification->title);
        $this->assertSame('Your reference is BK-1 on 2026-07-16.', $notification->message);
        $this->assertSame('test', $notification->data['source']);
        $this->assertSame('system_notice', $notification->data['template_key']);
        $this->assertArrayHasKey('event_id', $notification->data);
        $this->assertNull($notification->read_at);
        $this->assertNull($notification->sent_at);
    }

    public function test_notification_requested_supports_admin_recipient(): void
    {
        $admin = Admin::factory()->create();
        $this->createTemplate('inventory_alert', NotificationType::Inventory, NotificationChannel::Email, [
            'title' => 'Stock alert',
            'message' => 'Low stock for {{customer_name}}.',
            'subject' => 'Inventory',
        ]);

        event(NotificationRequested::make(
            recipient: $admin,
            templateKey: 'inventory_alert',
            variables: ['customer_name' => 'Warehouse'],
        ));

        $notification = Notification::query()->firstOrFail();

        $this->assertInstanceOf(Admin::class, $notification->recipient);
        $this->assertTrue($notification->recipient->is($admin));
        $this->assertSame(NotificationChannel::Email, $notification->channel);
    }

    public function test_notification_requested_supports_customer_profile_recipient(): void
    {
        $profile = CustomerProfile::factory()->create();
        NotificationPreference::factory()->forCustomerProfile($profile)->create([
            'notification_type' => NotificationType::Booking,
            'in_app' => true,
            'email' => true,
            'sms' => true,
        ]);
        $this->createTemplate('booking_sms', NotificationType::Booking, NotificationChannel::Sms, [
            'title' => 'Booking update',
            'message' => 'Booking {{booking_number}} confirmed.',
        ]);

        event(NotificationRequested::make(
            recipient: $profile,
            templateKey: 'booking_sms',
            variables: ['booking_number' => 'BK-9'],
        ));

        $notification = Notification::query()->firstOrFail();

        $this->assertInstanceOf(CustomerProfile::class, $notification->recipient);
        $this->assertTrue($notification->recipient->is($profile));
        $this->assertSame(NotificationType::Booking, $notification->type);
        $this->assertSame(NotificationChannel::Sms, $notification->channel);
    }

    public function test_duplicate_dispatch_of_the_same_event_does_not_create_duplicates(): void
    {
        $admin = Admin::factory()->create();
        $this->createTemplate('order_placed', NotificationType::Order, NotificationChannel::InApp, [
            'title' => 'Order placed',
            'message' => 'Order {{order_number}} confirmed.',
        ]);

        $event = NotificationRequested::make(
            recipient: $admin,
            templateKey: 'order_placed',
            variables: ['order_number' => 'ORD-1'],
            eventId: 'evt-order-duplicate-guard',
        );

        event($event);
        event($event);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, Notification::query()->where('event_id', 'evt-order-duplicate-guard')->count());
        $this->assertSame(1, Notification::query()->where('data->event_id', 'evt-order-duplicate-guard')->count());
    }

    public function test_template_channel_is_persisted_from_template(): void
    {
        $profile = CustomerProfile::factory()->create();
        $this->createTemplate('payment_received', NotificationType::Payment, NotificationChannel::Email, [
            'title' => 'Payment received',
            'message' => 'Payment {{payment_number}} received.',
            'subject' => 'Payment {{payment_number}}',
        ]);

        event(NotificationRequested::make(
            recipient: $profile,
            templateKey: 'payment_received',
            variables: ['payment_number' => 'PAY-1'],
            eventId: 'evt-payment-channels',
        ));

        $this->assertDatabaseCount('notifications', 1);

        $notification = Notification::query()->firstOrFail();

        $this->assertSame(NotificationChannel::Email, $notification->channel);
        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertNull($notification->sent_at);
        $this->assertNull($notification->read_at);
        $this->assertSame('evt-payment-channels', $notification->event_id);
        $this->assertSame('evt-payment-channels', $notification->data['event_id']);
    }

    public function test_pending_is_the_default_status_for_dispatched_notifications(): void
    {
        $admin = Admin::factory()->create();
        $this->createTemplate('quotation_ready', NotificationType::Quotation, NotificationChannel::InApp, [
            'title' => 'Quotation ready',
            'message' => 'A quotation is ready for review.',
        ]);

        event(NotificationRequested::make(
            recipient: $admin,
            templateKey: 'quotation_ready',
        ));

        $this->assertDatabaseHas('notifications', [
            'status' => NotificationStatus::Pending->value,
            'read_at' => null,
            'sent_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTemplate(
        string $key,
        NotificationType $type,
        NotificationChannel $channel,
        array $overrides = [],
    ): NotificationTemplate {
        return NotificationTemplate::factory()->create([
            'template_key' => $key,
            'type' => $type,
            'channel' => $channel,
            'title' => $overrides['title'] ?? 'Hello {{customer_name}}',
            'message' => $overrides['message'] ?? 'Your reference is {{booking_number}} on {{date}}.',
            'subject' => $overrides['subject'] ?? null,
        ]);
    }
}
