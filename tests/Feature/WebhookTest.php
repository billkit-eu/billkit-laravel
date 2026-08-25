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
}
