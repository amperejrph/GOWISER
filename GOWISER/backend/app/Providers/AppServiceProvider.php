<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Singleton so the configured VAT rate is read from billing_config once per request
        // rather than once per account. A nightly billing run touches thousands of accounts;
        // without this it would issue the same lookup thousands of times.
        $this->app->singleton(\App\Services\VatCalculator::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
