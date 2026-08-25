<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Feature;

use BillKit\BillKitClient;
use BillKit\Laravel\BillKitServiceProvider;
use BillKit\Laravel\Tests\TestCase;
use BillKit\Transport;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The package's only job here is wiring: turn a channel name in
 * ``config/billkit.php`` into a PSR-3 logger for the SDK, and hand over
 * nothing at all when no channel is named.
 *
 * Installing a package must not switch on log output. An application
 * that hasn't asked for BillKit's request lifecycle in its logs should
 * not start finding it there after a ``composer update``.
 */
final class LoggingTest extends TestCase
{
    /**
     * The base TestCase rebinds BillKitClient with a fake HTTP client, so
     * it never runs the service provider's own factory. Re-register to
     * exercise the real closure, which is the code under test.
     */
    private function clientFromServiceProvider(): BillKitClient
    {
        $this->app->forgetInstance(BillKitClient::class);
        (new BillKitServiceProvider($this->app))->register();

        return $this->app->make(BillKitClient::class);
    }

    /** Reach the transport's private logger; it has no accessor by design. */
    private function loggerOf(BillKitClient $client): LoggerInterface
    {
        $property = new \ReflectionProperty(Transport::class, 'logger');

        $logger = $property->getValue($client->transport);
        self::assertInstanceOf(LoggerInterface::class, $logger);

        return $logger;
    }

    public function test_no_channel_configured_leaves_the_sdk_silent(): void
    {
        config()->set('billkit.log_channel', null);

        self::assertInstanceOf(NullLogger::class, $this->loggerOf($this->clientFromServiceProvider()));
    }

    public function test_empty_channel_string_leaves_the_sdk_silent(): void
    {
        // An unset BILLKIT_LOG_CHANNEL in .env arrives as "" rather than
        // null, and "" is not a channel name.
        config()->set('billkit.log_channel', '');

        self::assertInstanceOf(NullLogger::class, $this->loggerOf($this->clientFromServiceProvider()));
    }

    public function test_configured_channel_is_handed_to_the_sdk(): void
    {
        config()->set('logging.channels.billkit_test', ['driver' => 'single', 'path' => storage_path('logs/billkit-test.log')]);
        config()->set('billkit.log_channel', 'billkit_test');

        $logger = $this->loggerOf($this->clientFromServiceProvider());

        self::assertNotInstanceOf(NullLogger::class, $logger);
        self::assertSame(Log::channel('billkit_test'), $logger);
    }

    public function test_a_bad_channel_name_never_breaks_the_container(): void
    {
        // A logging typo must not turn every billing request into a 500.
        // Laravel's LogManager degrades to an emergency logger rather than
        // throwing; either way, resolving the client has to succeed.
        config()->set('billkit.log_channel', 'no_such_channel');

        $logger = $this->loggerOf($this->clientFromServiceProvider());

        self::assertInstanceOf(LoggerInterface::class, $logger);
    }
}
