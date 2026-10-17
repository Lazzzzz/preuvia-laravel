<?php

namespace Preuvia\Laravel;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Preuvia\Laravel\Webhooks\Http\VerifyPreuviaWebhook;
use Preuvia\Laravel\Webhooks\Http\WebhookController;

class PreuviaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/preuvia.php', 'preuvia');

        $this->app->singleton(Preuvia::class, fn ($app): Preuvia => Preuvia::fromConfig(
            $app->make(HttpFactory::class),
            $app['config']->get('preuvia', []),
        ));

        $this->app->alias(Preuvia::class, 'preuvia');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/preuvia.php' => config_path('preuvia.php'),
            ], 'preuvia-config');
        }

        $this->registerWebhookRoute();
    }

    private function registerWebhookRoute(): void
    {
        $path = config('preuvia.webhooks.path');

        if (blank($path) || $this->app->routesAreCached()) {
            return;
        }

        // Outside the web group on purpose: no session, no CSRF, the signature authenticates.
        Route::post($path, WebhookController::class)
            ->middleware([...config('preuvia.webhooks.middleware', []), VerifyPreuviaWebhook::class])
            ->name('preuvia.webhook');
    }
}
