<?php

namespace Tests\Unit\Sms;

use App\Contracts\Sms\SmsSenderInterface;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\NullSmsSender;
use App\Services\Sms\SmsSenderManager;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class SmsSenderTest extends TestCase
{
    public function test_driver_is_resolved_from_configuration(): void
    {
        config()->set('services.sms.driver', 'log');
        $this->assertInstanceOf(LogSmsSender::class, $this->app->make(SmsSenderInterface::class));

        config()->set('services.sms.driver', 'null');
        $this->assertInstanceOf(NullSmsSender::class, $this->app->make(SmsSenderInterface::class));
    }

    public function test_unknown_driver_is_rejected(): void
    {
        config()->set('services.sms.driver', 'unregistered-provider');

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(SmsSenderInterface::class);
    }

    public function test_log_driver_writes_the_message_to_the_log(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $context['phone'] === '+252611234567'
                    && $context['message'] === 'Test message';
            });

        (new LogSmsSender)->send('+252611234567', 'Test message');
    }

    public function test_null_driver_discards_the_message(): void
    {
        Log::shouldReceive('info')->never();

        (new NullSmsSender)->send('+252611234567', 'Test message');
    }

    public function test_drivers_can_be_registered_at_runtime(): void
    {
        $manager = new SmsSenderManager;
        $custom = new NullSmsSender;

        $manager->register('custom-provider', $custom);

        $this->assertSame($custom, $manager->driver('custom-provider'));
    }
}
