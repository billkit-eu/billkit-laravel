<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests;

use BillKit\BillKitClient;
use BillKit\Laravel\BillKitServiceProvider;
use BillKit\Laravel\Tests\Fixtures\FakeHttpClient;
use BillKit\Laravel\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected FakeHttpClient $http;

    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BillKitServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('billkit.api_key', 'sk_test_unit');
        $app['config']->set('billkit.base_url', 'https://test.billkit.eu');
        $app['config']->set('billkit.webhook.secret', self::WEBHOOK_SECRET);
        $app['config']->set('billkit.model', User::class);

        $this->http = new FakeHttpClient();
        $psr17 = new Psr17Factory();
        $app->singleton(BillKitClient::class, fn (): BillKitClient => new BillKitClient(
            apiKey: 'sk_test_unit',
            baseUrl: 'https://test.billkit.eu',
            httpClient: $this->http,
            requestFactory: $psr17,
            streamFactory: $psr17,
        ));
    }

    protected function defineDatabaseMigrations(): void
    {
        // A minimal `users` table (earlier timestamp) so the package's
        // add-columns migration has a table to alter; the package's own
        // migrations are auto-loaded by the service provider.
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }

    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada+' . uniqid() . '@example.com',
            'password' => 'secret',
        ]);
    }

    /** Build a valid BillKit-Signature header for a payload. */
    protected function signWebhook(string $payload, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        $sig = hash_hmac('sha256', $ts . '.' . $payload, self::WEBHOOK_SECRET);

        return "t={$ts},v1={$sig}";
    }
}
