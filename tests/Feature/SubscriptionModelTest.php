<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Feature;

use BillKit\Laravel\Subscription;
use BillKit\Laravel\Tests\TestCase;

/**
 * State-helper unit tests: no HTTP, no DB writes, just the entitlement logic.
 */
final class SubscriptionModelTest extends TestCase
{
    /**
     * @param array<string, mixed> $attrs
     */
    private function sub(array $attrs): Subscription
    {
        return new Subscription(array_merge(
            ['type' => 'default', 'billkit_id' => 'sub_x', 'status' => 'active'],
            $attrs,
        ));
    }

    public function test_valid_covers_entitled_statuses(): void
    {
        foreach (['active', 'trialing', 'past_due'] as $status) {
            self::assertTrue($this->sub(['status' => $status])->valid(), $status);
        }
        self::assertFalse($this->sub(['status' => 'canceled'])->valid());
        self::assertFalse($this->sub(['status' => 'paused'])->valid());
        self::assertFalse($this->sub(['status' => 'incomplete'])->valid());
    }

    public function test_on_trial(): void
    {
        self::assertTrue($this->sub(['status' => 'trialing'])->onTrial());
        self::assertTrue($this->sub(['status' => 'active', 'trial_ends_at' => now()->addDay()])->onTrial());
        self::assertFalse($this->sub(['status' => 'active', 'trial_ends_at' => now()->subDay()])->onTrial());
        self::assertFalse($this->sub(['status' => 'active'])->onTrial());
    }

    public function test_on_grace_period(): void
    {
        self::assertTrue($this->sub([
            'cancel_at_period_end' => true,
            'current_period_end' => now()->addDay(),
        ])->onGracePeriod());

        self::assertFalse($this->sub([
            'cancel_at_period_end' => true,
            'current_period_end' => now()->subDay(),
        ])->onGracePeriod());

        self::assertFalse($this->sub([
            'cancel_at_period_end' => false,
            'current_period_end' => now()->addDay(),
        ])->onGracePeriod());
    }

    public function test_canceled_within_grace_is_still_valid_but_not_ended(): void
    {
        $graceful = $this->sub([
            'status' => 'canceled',
            'cancel_at_period_end' => true,
            'current_period_end' => now()->addDay(),
        ]);
        self::assertTrue($graceful->canceled());
        self::assertTrue($graceful->onGracePeriod());
        self::assertTrue($graceful->valid());
        self::assertFalse($graceful->ended());
    }

    public function test_ended_when_canceled_and_past_period(): void
    {
        $ended = $this->sub(['status' => 'canceled', 'canceled_at' => now()->subDay()]);
        self::assertTrue($ended->canceled());
        self::assertTrue($ended->ended());
        self::assertFalse($ended->valid());
    }

    public function test_paused_and_past_due_flags(): void
    {
        self::assertTrue($this->sub(['status' => 'paused'])->paused());
        self::assertTrue($this->sub(['status' => 'past_due'])->pastDue());
    }
}
