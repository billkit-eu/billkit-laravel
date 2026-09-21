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
        self::assertFalse($this->sub(['status' => 'incomplete'])->valid());

        // A paused subscription is still entitled: the customer paid for
        // the period they are in, and only the renewal is off.
        self::assertTrue(
            $this->sub(['status' => 'active', 'renewal_state' => 'paused'])->valid(),
        );
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

    public function test_paused_reads_renewal_state_not_status(): void
    {
        // The wire shape of a paused subscription: status stays `active`
        // and `renewal_state` carries the pause. Keying `paused()` off
        // `status` answered "no" for every real paused row.
        $paused = $this->sub(['status' => 'active', 'renewal_state' => 'paused']);
        self::assertTrue($paused->paused());
        self::assertTrue($paused->active());

        self::assertFalse($this->sub(['status' => 'active', 'renewal_state' => 'auto_renew'])->paused());
        self::assertFalse($this->sub(['status' => 'active'])->paused());
    }

    public function test_past_due_flag(): void
    {
        self::assertTrue($this->sub(['status' => 'past_due'])->pastDue());
    }

    // ── syncFromApi ──────────────────────────────────────────────────
    //
    // The mapping reads every field with array_key_exists rather than `??`.
    // The two are the same until the API sends an explicit null, and then
    // they are opposites: `??` treats null as "the key was absent" and keeps
    // the old value, so a field the API had cleared could never be cleared
    // locally. A trial that converts sends `trial_end: null`.

    public function test_sync_writes_an_explicit_null_as_null(): void
    {
        $sub = Subscription::create([
            'type' => 'default',
            'billkit_id' => 'sub_sync',
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays(7),
        ]);

        $sub->syncFromApi(['status' => 'active', 'trial_end' => null]);

        self::assertNull($sub->fresh()->trial_ends_at);
        self::assertSame('active', $sub->fresh()->status);
    }

    public function test_sync_leaves_an_absent_key_alone(): void
    {
        $trialEnd = now()->addDays(7)->startOfSecond();
        $sub = Subscription::create([
            'type' => 'default',
            'billkit_id' => 'sub_absent',
            'status' => 'trialing',
            'price_id' => 'price_1',
            'trial_ends_at' => $trialEnd,
        ]);

        // A partial object: the action endpoints answer with the whole
        // subscription, but a caller may hand over less than that.
        $sub->syncFromApi(['status' => 'active']);

        $fresh = $sub->fresh();
        self::assertSame('price_1', $fresh->price_id);
        self::assertNotNull($fresh->trial_ends_at);
        self::assertTrue($trialEnd->equalTo($fresh->trial_ends_at));
    }

    public function test_sync_ignores_a_status_that_is_not_a_string(): void
    {
        // `status` is the one column that is never null, so an unusable
        // value must leave the row's current status alone rather than
        // clear it.
        $sub = Subscription::create([
            'type' => 'default',
            'billkit_id' => 'sub_status',
            'status' => 'active',
        ]);

        $sub->syncFromApi(['status' => 123, 'renewal_state' => 'paused']);

        self::assertSame('active', $sub->fresh()->status);
        self::assertSame('paused', $sub->fresh()->renewal_state);
    }

    public function test_sync_casts_epoch_timestamps(): void
    {
        $sub = Subscription::create([
            'type' => 'default',
            'billkit_id' => 'sub_ts',
            'status' => 'active',
        ]);

        $sub->syncFromApi([
            'current_period_start' => 1790000000,
            'current_period_end' => 1792592000,
            'cancel_at_period_end' => true,
        ]);

        $fresh = $sub->fresh();
        self::assertSame(1790000000, $fresh->current_period_start?->getTimestamp());
        self::assertSame(1792592000, $fresh->current_period_end?->getTimestamp());
        self::assertTrue($fresh->cancel_at_period_end);
    }
}
