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
            $this->sub([
                'status' => 'active',
                'renewal_state' => 'paused',
                'current_period_end' => now()->addDay(),
            ])->valid(),
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

    public function test_canceling_within_the_paid_period_is_valid(): void
    {
        // BillKit's cancel-at-period-end shape: `status` stays `active` and
        // `renewal_state` says no renewal is coming.
        $canceling = $this->sub([
            'status' => 'active',
            'renewal_state' => 'canceling',
            'serves_customer' => true,
            'cancel_at_period_end' => true,
            'current_period_end' => now()->addDay(),
        ]);
        self::assertTrue($canceling->onGracePeriod());
        self::assertTrue($canceling->valid());
        // Cashier's meaning: cancelled, on its grace period, not ended.
        self::assertTrue($canceling->canceled());
        self::assertFalse($canceling->ended());
    }

    public function test_canceled_is_never_on_a_grace_period(): void
    {
        // An immediate cancel overtaking a scheduled one leaves
        // cancel_at_period_end set beside a future period end. The API
        // treats `canceled` as terminal, so Cashier's
        // `subscribed() || onGracePeriod()` must not grant it either.
        $canceled = $this->sub([
            'status' => 'canceled',
            'renewal_state' => 'stopped',
            'serves_customer' => false,
            'cancel_at_period_end' => true,
            'canceled_at' => now()->subMinute(),
            'current_period_end' => now()->addWeek(),
        ]);
        self::assertFalse($canceled->onGracePeriod());
        self::assertFalse($canceled->valid());
        self::assertTrue($canceled->canceled());
        self::assertTrue($canceled->ended());

        // The status alone is enough, for a row synced without canceled_at.
        self::assertFalse($this->sub([
            'status' => 'canceled',
            'cancel_at_period_end' => true,
            'current_period_end' => now()->addWeek(),
        ])->onGracePeriod());
    }

    public function test_a_renewing_subscription_is_not_canceled(): void
    {
        $active = $this->sub([
            'status' => 'active',
            'renewal_state' => 'auto_renew',
            'cancel_at_period_end' => false,
            'current_period_end' => now()->addWeek(),
        ]);
        self::assertFalse($active->canceled());
        self::assertFalse($active->onGracePeriod());
        self::assertFalse($active->ended());
    }

    public function test_canceled_is_never_valid_even_inside_the_period(): void
    {
        // `canceled` is terminal in the API: serves_customer is false for it
        // whatever the period says. This used to answer true through
        // onGracePeriod(), serving a customer the API had cut off.
        foreach ([null, false] as $served) {
            self::assertFalse($this->sub([
                'status' => 'canceled',
                'serves_customer' => $served,
                'cancel_at_period_end' => true,
                'current_period_end' => now()->addDay(),
            ])->valid(), var_export($served, true));
        }
    }

    public function test_valid_follows_serves_customer_over_status(): void
    {
        self::assertFalse($this->sub([
            'status' => 'active',
            'renewal_state' => 'auto_renew',
            'serves_customer' => false,
        ])->valid());
    }

    public function test_a_non_renewing_snapshot_expires_at_period_end(): void
    {
        // serves_customer was true when the row was synced, but nothing is
        // renewing and the paid period is over: the API now answers false
        // and no event marks the moment, so the row must not keep serving.
        foreach (['canceling', 'paused', 'stopped'] as $state) {
            self::assertFalse($this->sub([
                'status' => 'active',
                'renewal_state' => $state,
                'serves_customer' => true,
                'current_period_end' => now()->subMinute(),
            ])->valid(), $state);
            self::assertFalse($this->sub([
                'status' => 'active',
                'renewal_state' => $state,
                'serves_customer' => true,
                'current_period_end' => null,
            ])->valid(), $state . ' without a period end');
        }

        // A renewing subscription is not held to its period end: the renewal
        // is charged in a short gap after it, and that is not a lapse.
        self::assertTrue($this->sub([
            'status' => 'active',
            'renewal_state' => 'auto_renew',
            'serves_customer' => true,
            'current_period_end' => now()->subMinute(),
        ])->valid());
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

        $fresh = $sub->fresh();
        self::assertNotNull($fresh);
        self::assertNull($fresh->trial_ends_at);
        self::assertSame('active', $fresh->status);
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

        self::assertNotNull($fresh);
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

        $fresh = $sub->fresh();
        self::assertNotNull($fresh);
        self::assertSame('active', $fresh->status);
        self::assertSame('paused', $fresh->renewal_state);
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

        self::assertNotNull($fresh);
        self::assertSame(1790000000, $fresh->current_period_start?->getTimestamp());
        self::assertSame(1792592000, $fresh->current_period_end?->getTimestamp());
        self::assertTrue($fresh->cancel_at_period_end);
    }

    public function test_sync_leaves_discount_and_payment_method_alone_when_absent(): void
    {
        // An API that predates the two fields, or a partial object, must not
        // wipe what an earlier event stored.
        $sub = Subscription::create([
            'type' => 'default',
            'billkit_id' => 'sub_keep',
            'status' => 'active',
            'coupon_id' => 'co_keep',
            'payment_method_type' => 'paypal',
        ]);

        $sub->syncFromApi(['status' => 'past_due']);

        $fresh = $sub->fresh();

        self::assertNotNull($fresh);
        self::assertSame('co_keep', $fresh->coupon_id);
        self::assertSame('paypal', $fresh->payment_method_type);
    }

    public function test_sync_treats_a_malformed_discount_as_none(): void
    {
        $sub = Subscription::create([
            'type' => 'default',
            'billkit_id' => 'sub_bad_discount',
            'status' => 'active',
            'coupon_id' => 'co_old',
            'discount_ends_at' => now()->addDay(),
        ]);

        $sub->syncFromApi(['discount' => ['object' => 'discount', 'ends_at' => 1790000000]]);

        $fresh = $sub->fresh();

        self::assertNotNull($fresh);
        self::assertNull($fresh->coupon_id);
        self::assertNull($fresh->discount_ends_at);
    }

    public function test_has_discount_is_false_once_the_window_has_passed(): void
    {
        // The coupon_expired webhook clears the columns; until it lands, the
        // stored end date alone must already answer "no discount".
        self::assertFalse($this->sub([
            'coupon_id' => 'co_past',
            'discount_ends_at' => now()->subMinute(),
        ])->hasDiscount());
        self::assertTrue($this->sub(['coupon_id' => 'co_forever'])->hasDiscount());
        self::assertFalse($this->sub([])->hasDiscount());
    }
}
