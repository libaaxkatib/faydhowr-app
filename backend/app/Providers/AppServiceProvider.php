<?php

namespace App\Providers;

use App\Services\Notification\Channels\EmailNotificationChannel;
use App\Services\Notification\Channels\InAppNotificationChannel;
use App\Services\Notification\Channels\SmsNotificationChannel;
use App\Services\Notification\NotificationChannelManager;
use App\Services\Payments\Gateways\ManualPaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class, function (): PaymentGatewayManager {
            $manager = new PaymentGatewayManager;
            $manager->register('manual', new ManualPaymentGateway);

            return $manager;
        });

        $this->app->singleton(NotificationChannelManager::class, function (): NotificationChannelManager {
            $manager = new NotificationChannelManager;
            $manager->register('in_app', new InAppNotificationChannel);
            $manager->register('email', new EmailNotificationChannel);
            $manager->register('sms', new SmsNotificationChannel);

            return $manager;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-register', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('auth-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                Str::lower((string) $request->input('email')).'|'.$request->ip(),
            );
        });
    }
}
