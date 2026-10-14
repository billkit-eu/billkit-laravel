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

    public function test_a_redirect_url_missing_from_the_response_is_an_error_not_an_empty_redirect(): void
    {
        $subscription = $this->makeSubscription('sub_4');
        $this->http->stage(200, ['id' => 'bps_1'])->stage(200, ['object' => 'subscription']);

        foreach (['billing portal session without a url' => fn (): string => $subscription->billingPortalUrl('https://app.test/back'),
            'reauthorisation without a checkout_url' => fn (): string => $subscription->updatePaymentMethod('https://app.test/back')] as $message => $call) {
            try {
                $call();
                self::fail('returned a URL the response did not carry');
            } catch (\BillKit\Exception\BillKitException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: non-empty-string}>
     */
    public static function emptyBodyVerbs(): iterable
    {
        yield 'pause' => ['pause', '/v1/subscriptions/sub_v/pause'];
        yield 'resume' => ['resume', '/v1/subscriptions/sub_v/resume'];
        yield 'reactivate' => ['reactivate', '/v1/subscriptions/sub_v/reactivate'];
    }

    /**
     * @param non-empty-string $expectedPath
     */
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

    /**
     * The API answers with `checkout_url`. 0.x read `url` and then
     * `redirect_url`, neither of which the response has, so it returned ''
     * to every caller, and this test staged `url` and pinned the bug.
     */
    public function test_update_payment_method_returns_the_checkout_url(): void
    {
        $subscription = $this->makeSubscription('sub_r');
        $this->http->stage(200, [
            'object' => 'reauthorization',
            'subscription_id' => 'sub_r',
            'operation_id' => 'op_1',
            'checkout_url' => 'https://www.mollie.com/reauth/xyz',
            'verification_amount_cents' => 1,
            'verification_currency' => 'EUR',
        ]);

        $url = $subscription->updatePaymentMethod('https://app.test/back');

        self::assertSame('https://www.mollie.com/reauth/xyz', $url);
        self::assertStringEndsWith('/v1/subscriptions/sub_r/reauthorize_payment_method', (string) $this->http->lastRequest()->getUri());
        self::assertSame('https://app.test/back', $this->http->bodyOf($this->http->lastRequest())['return_url']);
    }

    // ─── Metered usage ──────────────────────────────────────────────

    public function test_report_usage_posts_quantity_and_identifier(): void
    {
        $subscription = $this->makeSubscription('sub_meter');
        $this->http->stage(200, ['id' => 'ur_1', 'object' => 'usage_record', 'identifier' => 'job-42']);

        $record = $subscription->reportUsage(1200, 'job-42');

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertStringEndsWith('/v1/subscriptions/sub_meter/usage_records', (string) $req->getUri());
        self::assertSame(
            ['quantity' => 1200, 'identifier' => 'job-42'],
            $this->http->bodyOf($req),
        );
        self::assertSame('ur_1', $record['id']);
    }

    public function test_report_usage_omits_the_identifier_when_not_given(): void
    {
        // Dedupe is opt-in: two identical reports at different times are
        // legitimately two records.
        $subscription = $this->makeSubscription('sub_meter2');
        $this->http->stage(200, ['id' => 'ur_2']);

        $subscription->reportUsage();

        self::assertSame(['quantity' => 1], $this->http->bodyOf($this->http->lastRequest()));
    }

    public function test_report_usage_does_not_touch_the_local_row(): void
    {
        // A usage record is not a subscription state change, so unlike
        // cancel/swap/pause this must not re-sync the model from a response
        // that describes a usage record rather than a subscription.
        $subscription = $this->makeSubscription('sub_meter3');
        $this->http->stage(200, ['id' => 'ur_3', 'status' => 'canceled', 'price_id' => 'price_wrong']);

        $subscription->reportUsage(5);

        $fresh = $subscription->fresh();
        self::assertNotNull($fresh);
        self::assertSame('active', $fresh->status);
        self::assertSame('price_1', $fresh->price_id);
    }

    public function test_usage_records_forwards_the_pending_filter(): void
    {
        $subscription = $this->makeSubscription('sub_meter4');
        $this->http->stage(200, ['object' => 'list', 'data' => [], 'has_more' => false]);

        $subscription->usageRecords(['invoice_id' => 'pending', 'limit' => 25]);

        $uri = $this->http->lastRequest()->getUri();
        self::assertSame('GET', $this->http->lastRequest()->getMethod());
        self::assertStringContainsString('/v1/subscriptions/sub_meter4/usage_records', (string) $uri);
        parse_str($uri->getQuery(), $query);
        self::assertSame('pending', $query['invoice_id']);
        self::assertSame('25', $query['limit']);
    }

    public function test_usage_summary_reports_that_a_small_period_will_not_charge(): void
    {
        $subscription = $this->makeSubscription('sub_meter5');
        $this->http->stage(200, [
            'object' => 'usage_summary',
            'pending_quantity' => 3,
            'gross_cents' => 15,
            'will_charge' => false,
            'minimum_charge_cents' => 100,
        ]);

        $summary = $subscription->usageSummary();

        self::assertStringEndsWith(
            '/v1/subscriptions/sub_meter5/usage_summary',
            (string) $this->http->lastRequest()->getUri(),
        );
        // A dashboard rendering gross_cents as "your next invoice" without
        // reading this is wrong exactly when the number is small.
        self::assertFalse($summary['will_charge']);
        self::assertSame(100, $summary['minimum_charge_cents']);
    }
}
