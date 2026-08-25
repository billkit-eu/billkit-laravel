<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Integration;

use BillKit\BillKitClient;
use BillKit\Laravel\BillKitServiceProvider;
use BillKit\Laravel\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base case for the Laravel package's **live-API** integration suite.
 *
 * The unit suite (`tests/Feature/*`) binds a `FakeHttpClient` and asserts the
 * package builds the right request bodies. That proves the package is
 * self-consistent, and nothing more. It cannot catch the API rejecting a
 * payload, renaming a response field, or changing a status vocabulary.
 *
 * This base binds the **real** `BillKitClient` at a live API instead, so the
 * Cashier-shaped surface (`Billable::checkout`, `Subscription::cancel`, ...)
 * is exercised against the server it actually ships against.
 *
 * Skipped unless `BILLKIT_INTEGRATION_BASE_URL` is set; boot a stack with
 * `make sdk-integration`. That API must be booted with
 * `BILLKIT_E2E_TEST_LOGIN=1` so the harness can provision a fresh tenant.
 */
abstract class IntegrationTestCase extends Orchestra
{
    use RefreshDatabase;

    protected const WEBHOOK_SECRET = 'whsec_laravel_integration_secret';

    /** @var array{api_key: string, tenant_id: string, mollie_route_id: string} */
    protected static array $tenant;

    protected function setUp(): void
    {
        if (! LaravelHarness::enabled()) {
            self::markTestSkipped(
                'Set BILLKIT_INTEGRATION_BASE_URL to run the Laravel integration suite.',
            );
        }
        // One tenant for the whole run: provisioning is a round-trip, and the
        // scenarios don't make cross-cutting assertions about tenant-wide
        // list contents (unlike the server SDKs' pagination specs).
        if (! isset(self::$tenant)) {
            self::$tenant = LaravelHarness::provisionTenant();
        }
        parent::setUp();
    }

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
        $app['config']->set('billkit.api_key', self::$tenant['api_key']);
        $app['config']->set('billkit.base_url', LaravelHarness::baseUrl());
        $app['config']->set('billkit.webhook.secret', self::WEBHOOK_SECRET);
        $app['config']->set('billkit.model', User::class);
        $app['config']->set('billkit.success_url', 'https://merchant.example.com/ok');
        $app['config']->set('billkit.cancel_url', 'https://merchant.example.com/cancel');

        // The real client: curl transport, live base URL. This is the whole
        // point of the suite; no fake is bound.
        $app->singleton(BillKitClient::class, fn (): BillKitClient => new BillKitClient(
            apiKey: self::$tenant['api_key'],
            baseUrl: LaravelHarness::baseUrl(),
        ));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada+' . bin2hex(random_bytes(8)) . '@example.com',
            'password' => 'secret',
        ]);
    }

    /** Build a valid BillKit-Signature header for a payload. */
    protected function signWebhook(string $payload, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();

        return "t={$ts},v1=" . hash_hmac('sha256', $ts . '.' . $payload, self::WEBHOOK_SECRET);
    }
}
