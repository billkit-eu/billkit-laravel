<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Feature;

use BillKit\Laravel\Subscription;
use BillKit\Laravel\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * Period boundaries survive an app timezone that is not UTC.
 *
 * Eloquent writes a datetime as its wall-clock string, with no offset, and
 * reads it back in the app timezone. Carbon 3's `createFromTimestamp()`
 * builds a UTC instance unless told otherwise, so an epoch synced in an app
 * on `Europe/Amsterdam` came back two hours early (summer time), so every
 * entitlement check that compares against `now()` cut the customer off two
 * hours before the period they had paid for ended.
 */
final class SubscriptionTimezoneTest extends TestCase
{
    private const APP_TIMEZONE = 'Europe/Amsterdam';

    // 2026-10-13 12:00:00 UTC, when Amsterdam is UTC+2.
    private const PERIOD_END = 1_791_892_800;

    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        // What Laravel does at boot with `app.timezone`.
        $this->previousTimezone = date_default_timezone_get();
        config(['app.timezone' => self::APP_TIMEZONE]);
        date_default_timezone_set(self::APP_TIMEZONE);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $wire
     */
    private function synced(array $wire): Subscription
    {
        $sub = Subscription::create(['type' => 'default', 'billkit_id' => 'sub_tz', 'status' => 'active']);
        $sub->syncFromApi($wire);
        $fresh = $sub->fresh();
        self::assertNotNull($fresh);

        return $fresh;
    }

    private function at(int $epoch): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp($epoch, self::APP_TIMEZONE));
    }

    public function test_a_synced_epoch_reads_back_as_the_same_instant(): void
    {
        $fresh = $this->synced([
            'current_period_start' => self::PERIOD_END - 2_592_000,
            'current_period_end' => self::PERIOD_END,
            'trial_end' => self::PERIOD_END,
            'canceled_at' => self::PERIOD_END,
        ]);

        self::assertSame(self::PERIOD_END - 2_592_000, $fresh->current_period_start?->getTimestamp());
        self::assertSame(self::PERIOD_END, $fresh->current_period_end?->getTimestamp());
        self::assertSame(self::PERIOD_END, $fresh->trial_ends_at?->getTimestamp());
        self::assertSame(self::PERIOD_END, $fresh->canceled_at?->getTimestamp());
    }

    public function test_a_canceling_subscription_is_valid_until_the_exact_period_end(): void
    {
        $fresh = $this->synced([
            'status' => 'active',
            'renewal_state' => 'canceling',
            'serves_customer' => true,
            'cancel_at_period_end' => true,
            'current_period_end' => self::PERIOD_END,
        ]);

        $this->at(self::PERIOD_END - 1);
        self::assertTrue($fresh->valid());
        self::assertTrue($fresh->onGracePeriod());

        $this->at(self::PERIOD_END + 1);
        self::assertFalse($fresh->valid(), 'still valid after the period ended');
        self::assertFalse($fresh->onGracePeriod(), 'still on a grace period after the period ended');
    }

    public function test_a_trial_ends_at_the_exact_trial_end(): void
    {
        $fresh = $this->synced(['status' => 'active', 'trial_end' => self::PERIOD_END]);

        $this->at(self::PERIOD_END - 1);
        self::assertTrue($fresh->onTrial());

        $this->at(self::PERIOD_END + 1);
        self::assertFalse($fresh->onTrial(), 'still on trial after trial_end');
    }
}
