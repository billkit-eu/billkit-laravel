<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Feature;

use BillKit\Laravel\Subscription;
use BillKit\Laravel\Tests\Fixtures\User;
use BillKit\Laravel\Tests\TestCase;

/**
 * Webhook deliveries are unordered: each is retried on its own schedule. The
 * row keeps the `created` of the newest event it applied and skips anything
 * older, so a late retry cannot roll it back.
 */
final class WebhookOrderingTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides merged into the subscription object
     */
    private function deliver(string $type, ?int $created, array $overrides = []): void
    {
        $now = time();
        $event = [
            'id' => 'evt_' . $type . ($created ?? 'none'),
            'object' => 'event',
            'type' => $type,
            'livemode' => false,
            'data' => array_merge([
                'id' => 'sub_o',
                'object' => 'subscription',
                'customer_id' => 'cus_o',
                'price_id' => 'price_1',
                'status' => 'active',
                'renewal_state' => 'auto_renew',
                'serves_customer' => true,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'current_period_start' => $now - 100,
                'current_period_end' => $now + 2_592_000,
            ], $overrides),
        ];
        if ($created !== null) {
            $event['created'] = $created;
        }
        $body = (string) json_encode($event);

        $this->call(
            'POST',
            '/billkit/webhook',
            server: ['HTTP_BILLKIT_SIGNATURE' => $this->signWebhook($body), 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        )->assertOk();
    }

    /** @return array<string, mixed> */
    private function canceled(): array
    {
        return [
            'status' => 'canceled',
            'renewal_state' => 'stopped',
            'serves_customer' => false,
            'canceled_at' => time(),
        ];
    }

    private function customer(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_o'])->save();

        return $user;
    }

    private function subscribed(User $user): bool
    {
        $fresh = $user->fresh();
        self::assertNotNull($fresh);

        return $fresh->subscribed();
    }

    private function row(): Subscription
    {
        $row = Subscription::query()->where('billkit_id', 'sub_o')->first();
        self::assertNotNull($row);

        return $row;
    }

    public function test_an_older_event_landing_after_a_cancel_does_not_resurrect_the_subscription(): void
    {
        $user = $this->customer();
        $t = time();

        $this->deliver('subscription.canceled', $t, $this->canceled());
        self::assertFalse($this->subscribed($user));

        // A retry of an update from an hour earlier, arriving second.
        $this->deliver('subscription.updated', $t - 3600);

        self::assertFalse($this->subscribed($user));
        self::assertSame('canceled', $this->row()->status);
        self::assertSame($t, $this->row()->last_event_created);
    }

    public function test_a_newer_event_is_applied_and_moves_the_watermark(): void
    {
        $this->customer();
        $t = time();

        $this->deliver('subscription.created', $t - 60);
        $this->deliver('subscription.canceled', $t, $this->canceled());

        self::assertSame('canceled', $this->row()->status);
        self::assertSame($t, $this->row()->last_event_created);
    }

    public function test_an_event_with_the_same_created_is_applied(): void
    {
        // A transition and its paired subscription.updated share one write
        // and one timestamp.
        $this->customer();
        $t = time();

        $this->deliver('subscription.updated', $t);
        $this->deliver('subscription.paused', $t, ['renewal_state' => 'paused']);

        self::assertSame('paused', $this->row()->renewal_state);
        self::assertSame($t, $this->row()->last_event_created);
    }

    public function test_an_event_without_created_is_applied_and_leaves_the_watermark(): void
    {
        $this->customer();
        $t = time();

        $this->deliver('subscription.updated', $t);
        $this->deliver('subscription.paused', null, ['renewal_state' => 'paused']);

        self::assertSame('paused', $this->row()->renewal_state);
        self::assertSame($t, $this->row()->last_event_created);
    }

    public function test_an_action_verb_does_not_move_the_watermark(): void
    {
        $this->customer();
        $t = time();
        $this->deliver('subscription.updated', $t);

        $this->http->stage(200, [
            'id' => 'sub_o',
            'object' => 'subscription',
            'status' => 'active',
            'renewal_state' => 'canceling',
            'serves_customer' => true,
            'cancel_at_period_end' => true,
        ]);
        $this->row()->cancel();

        self::assertSame('canceling', $this->row()->renewal_state);
        self::assertSame($t, $this->row()->last_event_created);
    }

    public function test_serves_customer_is_mirrored_from_the_payload(): void
    {
        $user = $this->customer();
        $t = time();

        // status still `active`, but the API says this customer is not served.
        $this->deliver('subscription.updated', $t, ['serves_customer' => false]);

        self::assertFalse($this->row()->serves_customer);
        self::assertFalse($this->subscribed($user));

        $this->deliver('subscription.updated', $t + 1, ['serves_customer' => true]);
        self::assertTrue($this->row()->serves_customer);
        self::assertTrue($this->subscribed($user));
    }
}
