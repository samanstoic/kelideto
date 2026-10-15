<?php

namespace App\Providers;

use App\Modules\Identity\Contracts\SmsGatewayInterface;
use App\Modules\Identity\Adapters\MockSmsGatewayAdapter;
use App\Modules\Identity\Adapters\KavenegarSmsGatewayAdapter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind interchangeable SMS Adapter according to configuration
        $this->app->bind(SmsGatewayInterface::class, function () {
            $driver = config('services.sms.driver', env('SMS_DRIVER', 'mock'));

            return match ($driver) {
                'kavenegar' => new KavenegarSmsGatewayAdapter(),
                default => new MockSmsGatewayAdapter(),
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
