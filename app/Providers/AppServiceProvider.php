<?php

namespace App\Providers;

use App\Modules\Identity\Adapters\KavenegarSmsGatewayAdapter;
use App\Modules\Identity\Adapters\MockSmsGatewayAdapter;
use App\Modules\Identity\Contracts\SmsGatewayInterface;
use App\Modules\Payment\Adapters\DisconnectedPaymentGatewayAdapter;
use App\Modules\Payment\Adapters\ZarinpalPaymentGatewayAdapter;
use App\Modules\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Passkeys::ignoreRoutes();

        // Bind interchangeable SMS Adapter according to configuration
        $this->app->bind(SmsGatewayInterface::class, function () {
            $driver = config('services.sms.driver', env('SMS_DRIVER', 'mock'));

            return match ($driver) {
                'kavenegar' => new KavenegarSmsGatewayAdapter,
                default => new MockSmsGatewayAdapter,
            };
        });

        $this->app->bind(PaymentGatewayInterface::class, function () {
            return match (config('services.payment_gateway.driver', 'disconnected')) {
                'zarinpal' => new ZarinpalPaymentGatewayAdapter,
                default => new DisconnectedPaymentGatewayAdapter,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
