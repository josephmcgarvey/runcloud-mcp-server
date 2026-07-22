<?php

namespace App\Providers;

use App\Services\RunCloud\RunCloudClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RunCloudClient::class, fn (): RunCloudClient => new RunCloudClient(
            token: (string) config('services.runcloud.token'),
            baseUrl: (string) config('services.runcloud.base_url'),
            timeout: (int) config('services.runcloud.timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
