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
        $this->app->singleton('api_error', function() {
            return new \App\Exceptions\APIError;
        });
        // $this->app->alias('bugsnag.multi', \Illuminate\Contracts\Logging\Log::class);
        // $this->app->alias('bugsnag.multi', \Psr\Log\LoggerInterface::class);
    }
}
