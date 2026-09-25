<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Feature;

use BillKit\Laravel\Events\WebhookHandled;
use BillKit\Laravel\Events\WebhookReceived;
use BillKit\Laravel\Subscription;
use BillKit\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Event;

final class WebhookTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\Response>
     */
    private function postWebhook(array $payload, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call(
            'POST',
            '/billkit/webhook',
            server: [
                'HTTP_BILLKIT_SIGNATURE' => $signature ?? $this->signWebhook($body),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );
    }

    public function test_subscription_created_webhook_syncs_and_links_billable(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_1'])->save();

        $now = time();
        $payload = (string) json_encode([
            'id' => 'evt_1',
            'object' => 'event',
            'type' => 'subscription.created',
            'created' => $now,
            'livemode' => false,
            'data' => [
                'id' => 'sub_1',
                'object' => 'subscription',
                'customer_id' => 'cus_1',
                'price_id' => 'price_1',
                'status' => 'active',
                'renewal_state' => 'auto_renew',
                'cancel_at_period_end' => false,
                'current_period_start' => $now,
                'current_period_end' => $now + 2_592_000,
            ],
        ]);

        $response = $this->call(
            'POST',
            '/billkit/webhook',
            server: ['HTTP_BILLKIT_SIGNATURE' => $this->signWebhook($payload), 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        );

        $response->assertOk();

        $subscription = Subscription::query()->first();
        self::assertNotNull($subscription);
        self::assertSame('sub_1', $subscription->billkit_id);
        self::assertSame('active', $subscription->status);
        self::assertSame($user->getKey(), $subscription->billable_id);
        self::assertTrue($user->fresh()?->subscribed() ?? false);
    }

    public function test_subscription_canceled_webhook_updates_status(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_2'])->save();
        Subscription::query()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
            'type' => 'default',
            'billkit_id' => 'sub_2',
            'billkit_customer_id' => 'cus_2',
            'price_id' => 'price_1',
            'status' => 'active',
        ]);

        $now = time();
        $payload = (string) json_encode([
            'type' => 'subscription.canceled',
            'data' => [
                'id' => 'sub_2',
                'customer_id' => 'cus_2',
                'status' => 'canceled',
                'canceled_at' => $now,
            ],
        ]);

        $this->call(
            'POST',
            '/billkit/webhook',
            server: ['HTTP_BILLKIT_SIGNATURE' => $this->signWebhook($payload), 'CONTENT_TYPE' => 'application/json'],
            content: $payload,
        )->assertOk();

        $subscription = Subscription::query()->where('billkit_id', 'sub_2')->first();
        self::assertNotNull($subscription);
        self::assertTrue($subscription->canceled());
        self::assertFalse($user->fresh()?->subscribed() ?? true);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->postWebhook(
            ['type' => 'subscription.created', 'data' => ['id' => 'sub_x', 'customer_id' => 'cus_1']],
            signature: 't=1,v1=deadbeef',
        )->assertForbidden();

        self::assertSame(0, Subscription::query()->count());
    }

    public function test_dispatches_received_and_handled_events(): void
    {
        Event::fake([WebhookReceived::class, WebhookHandled::class]);

        $this->postWebhook(['type' => 'invoice.paid', 'data' => ['id' => 'in_1']])->assertOk();

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(WebhookHandled::class);
    }

    public function test_non_subscription_event_is_a_noop(): void
    {
        $this->postWebhook(['type' => 'invoice.paid', 'data' => ['id' => 'in_1']])->assertOk();
        $this->postWebhook(['type' => 'customer.created', 'data' => ['id' => 'cus_9']])->assertOk();

        self::assertSame(0, Subscription::query()->count());
    }

    public function test_missing_webhook_secret_config_is_forbidden(): void
    {
        config()->set('billkit.webhook.secret', null);

        $this->postWebhook(
            ['type' => 'subscription.created', 'data' => ['id' => 'sub_x']],
            signature: 't=1,v1=deadbeef',
        )->assertForbidden();
    }

    public function test_subscription_updated_reflects_new_status_and_period(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_u'])->save();
        Subscription::query()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
            'type' => 'default',
            'billkit_id' => 'sub_u',
            'billkit_customer_id' => 'cus_u',
            'price_id' => 'price_1',
            'status' => 'trialing',
        ]);

        $now = time();
        $this->postWebhook([
            'type' => 'subscription.updated',
            'data' => [
                'id' => 'sub_u',
                'customer_id' => 'cus_u',
                'status' => 'active',
                'current_period_end' => $now + 2_592_000,
            ],
        ])->assertOk();

        $subscription = Subscription::query()->where('billkit_id', 'sub_u')->first();
        self::assertNotNull($subscription);
        self::assertSame('active', $subscription->status);
        self::assertTrue($subscription->current_period_end?->isFuture() ?? false);
    }

    // ── discount + payment_method ────────────────────────────────────
    //
    // The three specific events are each paired with a subscription.updated
    // carrying the same object, and every one of them runs through the same
    // generic sync. Each test below posts the specific type so a future
    // narrowing of that sync to named types fails here.

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function subscriptionObject(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'object' => 'subscription',
            'customer_id' => 'cus_pm',
            'price_id' => 'price_1',
            'status' => 'active',
            'renewal_state' => 'auto_renew',
            'cancel_at_period_end' => false,
            'current_period_start' => 1790000000,
            'current_period_end' => 1792592000,
            'discount' => null,
            'payment_method' => null,
        ], $overrides);
    }

    private const CARD = [
        'type' => 'creditcard',
        'brand' => 'Mastercard',
        'last4' => '4444',
        'exp_month' => 12,
        'exp_year' => 2030,
    ];

    public function test_created_webhook_mirrors_discount_and_card(): void
    {
        $endsAt = now()->addMonths(3)->getTimestamp();

        $this->postWebhook([
            'type' => 'subscription.created',
            'data' => $this->subscriptionObject('sub_pm', [
                'discount' => ['object' => 'discount', 'coupon_id' => 'co_launch', 'ends_at' => $endsAt],
                'payment_method' => self::CARD,
            ]),
        ])->assertOk();

        $sub = Subscription::query()->where('billkit_id', 'sub_pm')->first();
        self::assertNotNull($sub);
        self::assertTrue($sub->hasDiscount());
        self::assertSame('co_launch', $sub->couponId());
        self::assertSame($endsAt, $sub->discountEndsAt()?->getTimestamp());
        self::assertTrue($sub->hasPaymentMethod());
        self::assertTrue($sub->hasCard());
        self::assertSame('creditcard', $sub->paymentMethodType());
        self::assertSame('Mastercard', $sub->cardBrand());
        self::assertSame('4444', $sub->cardLastFour());
        self::assertSame(12, $sub->card_exp_month);
        self::assertSame(2030, $sub->card_exp_year);
    }

    public function test_forever_coupon_has_a_discount_with_no_end(): void
    {
        $this->postWebhook([
            'type' => 'subscription.coupon_applied',
            'data' => $this->subscriptionObject('sub_forever', [
                'discount' => ['object' => 'discount', 'coupon_id' => 'co_forever', 'ends_at' => null],
            ]),
        ])->assertOk();

        $sub = Subscription::query()->where('billkit_id', 'sub_forever')->first();
        self::assertNotNull($sub);
        self::assertTrue($sub->hasDiscount());
        self::assertNull($sub->discountEndsAt());
    }

    public function test_coupon_expired_clears_the_discount(): void
    {
        Subscription::query()->create([
            'type' => 'default',
            'billkit_id' => 'sub_exp',
            'status' => 'active',
            'coupon_id' => 'co_launch',
            'discount_ends_at' => now()->addDay(),
            'payment_method_type' => 'creditcard',
            'card_last_four' => '4444',
        ]);

        $this->postWebhook([
            'type' => 'subscription.coupon_expired',
            'data' => $this->subscriptionObject('sub_exp', ['payment_method' => self::CARD]),
            'previous_attributes' => [
                'discount' => ['object' => 'discount', 'coupon_id' => 'co_launch', 'ends_at' => 1790000000],
            ],
        ])->assertOk();

        $sub = Subscription::query()->where('billkit_id', 'sub_exp')->first();
        self::assertNotNull($sub);
        self::assertFalse($sub->hasDiscount());
        self::assertNull($sub->coupon_id);
        self::assertNull($sub->discount_ends_at);
        self::assertNull($sub->discountEndsAt());
        // The payment method in the same object is untouched by the expiry.
        self::assertSame('4444', $sub->cardLastFour());
    }

    public function test_payment_method_updated_to_directdebit_clears_card_fields(): void
    {
        Subscription::query()->create([
            'type' => 'default',
            'billkit_id' => 'sub_sepa',
            'status' => 'active',
            'payment_method_type' => 'creditcard',
            'card_brand' => 'Mastercard',
            'card_last_four' => '4444',
            'card_exp_month' => 12,
            'card_exp_year' => 2030,
        ]);

        $this->postWebhook([
            'type' => 'subscription.payment_method_updated',
            'data' => $this->subscriptionObject('sub_sepa', [
                'payment_method' => [
                    'type' => 'directdebit',
                    'brand' => null,
                    'last4' => null,
                    'exp_month' => null,
                    'exp_year' => null,
                ],
            ]),
            'previous_attributes' => ['payment_method' => self::CARD],
        ])->assertOk();

        $sub = Subscription::query()->where('billkit_id', 'sub_sepa')->first();
        self::assertNotNull($sub);
        self::assertTrue($sub->hasPaymentMethod());
        self::assertFalse($sub->hasCard());
        self::assertSame('directdebit', $sub->paymentMethodType());
        self::assertNull($sub->cardBrand());
        self::assertNull($sub->cardLastFour());
        self::assertNull($sub->card_exp_month);
        self::assertNull($sub->card_exp_year);
    }

    public function test_import_awaiting_activation_has_no_payment_method(): void
    {
        Subscription::query()->create([
            'type' => 'default',
            'billkit_id' => 'sub_import',
            'status' => 'active',
            'payment_method_type' => 'creditcard',
            'card_last_four' => '4444',
        ]);

        $this->postWebhook([
            'type' => 'subscription.updated',
            'data' => $this->subscriptionObject('sub_import', ['payment_method' => null]),
        ])->assertOk();

        $sub = Subscription::query()->where('billkit_id', 'sub_import')->first();
        self::assertNotNull($sub);
        self::assertFalse($sub->hasPaymentMethod());
        self::assertNull($sub->paymentMethodType());
        self::assertNull($sub->cardLastFour());
    }
}
