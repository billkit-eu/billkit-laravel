<?php

declare(strict_types=1);

namespace BillKit\Laravel;

use BillKit\BillKitClient;
use BillKit\Laravel\Http\Controllers\WebhookController;
use BillKit\Laravel\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Contracts\Config\Repository;
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
                logger: self::resolveLogger($config['log_channel'] ?? null, $app['config']),
            );
        });
    }

    /**
     * Resolve the configured log channel into a PSR-3 logger.
     *
     * Returns ``null`` (meaning the SDK stays silent) unless the app
     * names a channel that actually exists. Opting an application into
     * log output is its own decision to make, not something a package
     * should do on install — and neither is *where* that output lands.
     *
     * The channel is checked against ``config/logging.php`` rather than
     * left to ``Log::channel()``, because ``Log::channel()`` does not
     * throw for an unknown name: Laravel's ``LogManager`` catches the
     * resolution failure and hands back its **emergency logger**, a
     * `single` file handler at `debug`. So a typo'd ``BILLKIT_LOG_CHANNEL``
     * did the one thing this method exists to prevent — it switched
     * logging on, at the most verbose level, into a file the app never
     * nominated, appending a line per HTTP attempt for every billing
     * call. The try/catch below is kept as a belt-and-braces guard for
     * a channel that is declared but fails to build (a bad driver, an
     * unwritable path).
     */
    private static function resolveLogger(mixed $channel, Repository $config): ?LoggerInterface
    {
        if (! is_string($channel) || $channel === '') {
            return null;
        }

        if (! is_array($config->get("logging.channels.{$channel}"))) {
            // One warning, on the app's own default channel, then silence.
            // Loud enough to find during setup; not loud enough to become
            // the log output the typo was about to produce.
            Log::warning(
                "BillKit: log channel [{$channel}] is not defined in config/logging.php. " .
                'The SDK will not log. Check BILLKIT_LOG_CHANNEL.',
            );

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
