<?php

namespace App\Providers;

use App\Contracts\EmailServiceInterface;
use App\Contracts\SmsServiceInterface;
use App\Services\FakeEmailService;
use App\Services\FakeSmsService;
use Illuminate\Support\ServiceProvider;

class EnvioServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Bind email service - use fake for now, replace with real implementation later
        $this->app->bind(EmailServiceInterface::class, function ($app) {
            // TODO: Return real implementation based on config
            // if (config('services.email.driver') === 'sendgrid') {
            //     return new SendGridEmailService();
            // }
            return new FakeEmailService;
        });

        // Bind SMS service - use fake for now, replace with real implementation later
        $this->app->bind(SmsServiceInterface::class, function ($app) {
            // TODO: Return real implementation based on config
            // if (config('services.sms.driver') === 'twilio') {
            //     return new TwilioSmsService();
            // }
            return new FakeSmsService;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
