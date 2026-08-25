<?php

declare(strict_types=1);

namespace BillKit\Laravel;

use BillKit\BillKitClient;
use BillKit\Laravel\Http\Controllers\WebhookController;
use BillKit\Laravel\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class BillKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/billkit.php', 'billkit');

        $this->app->singleton(BillKitClient::class, static function ($app): BillKitClient {
            $config = $app['config']->get('billkit', []);
            $apiKey = $config['api_key'] ?? null;
            $baseUrl = $config['base_url'] ?? null;

            return new BillKitClient(
                apiKey: is_string($apiKey) && $apiKey !== '' ? $apiKey : null,
                baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
                logger: self::resolveLogger($config['log_channel'] ?? null),
            );
        });
    }

    /**
     * Resolve the configured log channel into a PSR-3 logger.
     *
     * Returns ``null`` (meaning the SDK stays silent) unless the app
     * names a channel. Opting an application into log output is its own
     * decision to make, not something a package should do on install.
     *
     * A misconfigured channel name must not take down the container:
     * ``Log::channel()`` throws for an unknown channel, and a package
     * that lets that escape turns a logging typo into a 500 on every
     * request that touches billing. Degrade to silence instead.
     */
    private static function resolveLogger(mixed $channel): ?LoggerInterface
    {
        if (! is_string($channel) || $channel === '') {
            return null;
        }

        try {
            return Log::channel($channel);
        } catch (\Throwable) {
            return null;
        }
    }

    public function boot(): void
    {
        $this->registerWebhookRoute();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__ . '/../config/billkit.php' => $this->app->configPath('billkit.php')],
                'billkit-config',
            );
            $this->publishes(
                [__DIR__ . '/../database/migrations' => $this->app->databasePath('migrations')],
                'billkit-migrations',
            );
        }
    }

    /**
     * Register the inbound webhook route, guarded by signature verification.
     *
     * Set ``billkit.path`` to ``null`` to disable and wire your own route.
     */
    private function registerWebhookRoute(): void
    {
        $path = $this->app['config']->get('billkit.path');
        if (! is_string($path) || $path === '') {
            return;
        }

        Route::post($path . '/webhook', [WebhookController::class, 'handleWebhook'])
            ->middleware(VerifyWebhookSignature::class)
            ->name('billkit.webhook');
    }
}
