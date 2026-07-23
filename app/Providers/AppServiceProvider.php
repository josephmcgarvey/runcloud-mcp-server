<?php

namespace App\Providers;

use App\Services\RunCloud\RunCloudClient;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

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
        /*
         * Passport ships no consent screen of its own, so without this the OAuth flow has nothing
         * to render at the point a user is deciding whether to grant an application access to
         * their infrastructure.
         */
        Passport::authorizationView('oauth.authorize');
    }
}
