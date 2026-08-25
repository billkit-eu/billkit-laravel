<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Feature;

use BillKit\Laravel\Subscription;
use BillKit\Laravel\Tests\TestCase;

final class SubscriptionActionsTest extends TestCase
{
    private function makeSubscription(string $billkitId): Subscription
    {
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_1'])->save();

        return Subscription::query()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
            'type' => 'default',
            'billkit_id' => $billkitId,
            'billkit_customer_id' => 'cus_1',
            'price_id' => 'price_1',
            'status' => 'active',
        ]);
    }

    public function test_cancel_calls_api_and_enters_grace_period(): void
    {
        $subscription = $this->makeSubscription('sub_1');
        $now = time();
        $this->http->stage(200, [
            'id' => 'sub_1',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'current_period_end' => $now + 1000,
        ]);

        $subscription->cancel();

        self::assertSame('POST', $this->http->lastRequest()->getMethod());
        self::assertStringEndsWith('/v1/subscriptions/sub_1/cancel', (string) $this->http->lastRequest()->getUri());
        self::assertTrue($subscription->fresh()?->onGracePeriod() ?? false);
    }

    public function test_swap_sends_target_price_and_syncs(): void
    {
        $subscription = $this->makeSubscription('sub_2');
        $this->http->stage(200, ['id' => 'sub_2', 'status' => 'active', 'price_id' => 'price_new']);

        $subscription->swap('price_new');

        self::assertStringEndsWith('/v1/subscriptions/sub_2/update', (string) $this->http->lastRequest()->getUri());
        self::assertSame('price_new', $this->http->bodyOf($this->http->lastRequest())['target_price_id']);
        self::assertSame('price_new', $subscription->fresh()?->price_id);
    }

    public function test_billing_portal_url_posts_subscription_and_return_url(): void
    {
        $subscription = $this->makeSubscription('sub_3');
        $this->http->stage(200, ['id' => 'bps_1', 'url' => 'https://portal.billkit.eu/tok']);

        $url = $subscription->billingPortalUrl('https://app.test/back');

        self::assertSame('https://portal.billkit.eu/tok', $url);
        self::assertStringEndsWith('/v1/billing_portal/sessions', (string) $this->http->lastRequest()->getUri());
        $body = $this->http->bodyOf($this->http->lastRequest());
        self::assertSame('sub_3', $body['subscription_id']);
        self::assertSame('https://app.test/back', $body['return_url']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function emptyBodyVerbs(): iterable
    {
        yield 'pause' => ['pause', '/v1/subscriptions/sub_v/pause'];
        yield 'resume' => ['resume', '/v1/subscriptions/sub_v/resume'];
        yield 'reactivate' => ['reactivate', '/v1/subscriptions/sub_v/reactivate'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyBodyVerbs')]
    public function test_lifecycle_verb_posts_to_the_right_path(string $method, string $expectedPath): void
    {
        $subscription = $this->makeSubscription('sub_v');
        $this->http->stage(200, ['id' => 'sub_v', 'status' => 'active']);

        $subscription->{$method}();

        self::assertSame('POST', $this->http->lastRequest()->getMethod());
        self::assertStringEndsWith($expectedPath, (string) $this->http->lastRequest()->getUri());
        self::assertStringStartsWith('sdk-', $this->http->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function test_preview_swap_returns_body_without_state_change(): void
    {
        $subscription = $this->makeSubscription('sub_p');
        $this->http->stage(200, ['amount_due_cents' => 500, 'proration' => true]);

        $preview = $subscription->previewSwap('price_new');

        self::assertSame(500, $preview['amount_due_cents']);
        self::assertStringEndsWith('/v1/subscriptions/sub_p/preview_update', (string) $this->http->lastRequest()->getUri());
        // preview_update carries no Idempotency-Key (it mutates nothing).
        self::assertSame('price_new', $this->http->bodyOf($this->http->lastRequest())['target_price_id']);
        // Local row unchanged.
        self::assertSame('price_1', $subscription->fresh()?->price_id);
    }

    public function test_update_payment_method_returns_redirect_url(): void
    {
        $subscription = $this->makeSubscription('sub_r');
        $this->http->stage(200, ['url' => 'https://www.mollie.com/reauth/xyz']);

        $url = $subscription->updatePaymentMethod('https://app.test/back');

        self::assertSame('https://www.mollie.com/reauth/xyz', $url);
        self::assertStringEndsWith('/v1/subscriptions/sub_r/reauthorize_payment_method', (string) $this->http->lastRequest()->getUri());
        self::assertSame('https://app.test/back', $this->http->bodyOf($this->http->lastRequest())['return_url']);
    }
}
