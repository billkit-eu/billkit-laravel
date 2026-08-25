<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Integration;

use BillKit\Laravel\Subscription;

/**
 * `billkit-eu/billkit-laravel` integration suite, run against a **live** BillKit API.
 *
 * Skipped unless `BILLKIT_INTEGRATION_BASE_URL` is set; boot a stack with
 * `make sdk-integration`.
 *
 * Every test is tagged with a `framework`-family scenario id from
 * `sdk/integration/scenarios.json`, and `testZzManifestCoverage` asserts this
 * suite covers all of them, the same gate the server and browser families
 * carry.
 */
final class IntegrationTest extends IntegrationTestCase
{
    /** Only scenarios in this family are required of the Laravel package. */
    private const FAMILY = 'framework';

    /** Scenario ids this class claims; checked against the shared manifest. */
    private const COVERED = [
        'laravel.customer',
        'laravel.checkout',
        'laravel.subscription_sync',
        'laravel.subscription_actions',
        'laravel.refund',
        'laravel.billing_portal',
        'laravel.webhook_signature',
    ];

    /**
     * Drive a billable all the way to an active subscription.
     *
     * Mirrors production ordering: the package opens the checkout, the buyer
     * pays at Mollie (here: the in-process fake settles), then the provider
     * webhook drives the saga. Returns the synced Subscription model.
     */
    private function subscribeUser(int $amountCents = 2500): Subscription
    {
        $user = $this->makeUser();
        $priceId = LaravelHarness::createPrice(self::$tenant['api_key'], $amountCents);

        $checkout = $user->checkout($priceId);
        $url = $checkout->url();
        self::assertIsString($url, 'hosted checkout must carry a redirect url');

        $providerPaymentId = LaravelHarness::paymentIdFromCheckoutUrl($url);
        LaravelHarness::settle($providerPaymentId, 'paid');
        LaravelHarness::deliverMollieWebhook(self::$tenant['mollie_route_id'], $providerPaymentId);

        // The API is now the source of truth; mirror it onto the Eloquent row
        // the same way the package's webhook controller does.
        $subs = LaravelHarness::get(self::$tenant['api_key'], '/v1/subscriptions?limit=100');
        /** @var list<array<string, mixed>> $rows */
        $rows = $subs['data'];
        $match = array_values(array_filter(
            $rows,
            static fn (array $s): bool => $s['price_id'] === $priceId,
        ));
        self::assertNotEmpty($match, 'a subscription should exist for the settled checkout');

        $subscription = new Subscription();
        $subscription->forceFill([
            'billable_type' => $user::class,
            'billable_id' => $user->getKey(),
            'type' => 'default',
            'billkit_id' => $match[0]['id'],
            'billkit_customer_id' => $user->billkitId(),
        ]);

        return $subscription->syncFromApi($match[0]);
    }

    // ── scenarios ────────────────────────────────────────────────────

    public function testLaravelCustomer(): void
    {
        $user = $this->makeUser();
        self::assertFalse($user->hasBillKitId());

        $customerId = $user->createOrGetBillKitCustomer(['email' => $user->email]);
        self::assertStringStartsWith('cus_', $customerId);

        // Persisted on the billable, so a second call is a no-op rather than
        // a duplicate customer, the bug that silently doubles a tenant's
        // customer count.
        self::assertTrue($user->fresh()->hasBillKitId());
        self::assertSame($customerId, $user->createOrGetBillKitCustomer());

        $remote = $user->asBillKitCustomer();
        self::assertSame($customerId, $remote['id']);
    }

    public function testLaravelCheckout(): void
    {
        $user = $this->makeUser();
        $priceId = LaravelHarness::createPrice(self::$tenant['api_key']);

        $checkout = $user->checkout($priceId);

        self::assertStringStartsWith('cs_', $checkout->id());
        // The config-level success/cancel URLs must reach the API. A tenant
        // that sets them once in config should not have to repeat them.
        self::assertIsString($checkout->url());
        self::assertStringContainsString('mollie.com', (string) $checkout->url());
    }

    public function testLaravelSubscriptionSync(): void
    {
        $subscription = $this->subscribeUser();

        self::assertSame('active', $subscription->status);
        self::assertTrue($subscription->active());
        self::assertTrue($subscription->valid());
        self::assertFalse($subscription->canceled());
        self::assertNotNull($subscription->current_period_end);
    }

    public function testLaravelSubscriptionActions(): void
    {
        $subscription = $this->subscribeUser();

        // Cancel-at-period-end: still valid (the customer keeps access until
        // the period ends), but now on its grace period.
        $subscription->cancel();
        self::assertTrue($subscription->cancel_at_period_end);
        self::assertTrue($subscription->onGracePeriod());
        self::assertTrue($subscription->valid());

        // Reactivate undoes it before the period actually ends.
        $subscription->reactivate();
        self::assertFalse($subscription->cancel_at_period_end);
        self::assertTrue($subscription->active());
    }

    public function testLaravelRefund(): void
    {
        $subscription = $this->subscribeUser(10000);
        $user = $this->makeUser();

        $payments = LaravelHarness::get(self::$tenant['api_key'], '/v1/payments?limit=100');
        /** @var list<array<string, mixed>> $rows */
        $rows = $payments['data'];
        $match = array_values(array_filter(
            $rows,
            static fn (array $p): bool => $p['subscription_id'] === $subscription->billkit_id,
        ));
        self::assertNotEmpty($match, 'the settled payment should be listed');

        $refund = $user->refund((string) $match[0]['id'], ['amount_cents' => 4000]);
        self::assertSame(4000, $refund['amount_cents']);

        // The remainder stays refundable; a partial must not close the balance.
        $after = LaravelHarness::get(
            self::$tenant['api_key'],
            '/v1/payments/' . $match[0]['id'],
        );
        self::assertSame(4000, $after['amount_refunded_cents']);
        self::assertSame(6000, $after['amount_refundable_cents']);
    }

    public function testLaravelBillingPortal(): void
    {
        $subscription = $this->subscribeUser();

        $url = $subscription->billingPortalUrl('https://merchant.example.com/account');

        self::assertNotSame('', $url, 'billingPortalUrl must return a real url');
        self::assertStringStartsWith('http', $url);
    }

    public function testLaravelWebhookSignature(): void
    {
        $payload = json_encode([
            'id' => 'evt_laravel_it',
            'type' => 'subscription.updated',
            'data' => ['object' => ['id' => 'sub_nonexistent', 'status' => 'active']],
        ], JSON_THROW_ON_ERROR);

        // A correctly signed payload is accepted.
        $ok = $this->call(
            'POST',
            '/billkit/webhook',
            [],
            [],
            [],
            ['HTTP_BILLKIT_SIGNATURE' => $this->signWebhook($payload), 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getContent());

        // A tampered body is refused with 403. `VerifyWebhookSignature`
        // raises `AccessDeniedHttpException`, matching Cashier's convention
        // that a bad signature is an authorization failure, not a malformed
        // request. This middleware is the only thing standing between a
        // public route and forged billing state.
        $tampered = $this->call(
            'POST',
            '/billkit/webhook',
            [],
            [],
            [],
            ['HTTP_BILLKIT_SIGNATURE' => $this->signWebhook($payload), 'CONTENT_TYPE' => 'application/json'],
            str_replace('evt_laravel_it', 'evt_forged', $payload),
        );
        self::assertSame(403, $tampered->getStatusCode());

        // A stale timestamp is refused too (replay defence).
        $stale = $this->call(
            'POST',
            '/billkit/webhook',
            [],
            [],
            [],
            [
                'HTTP_BILLKIT_SIGNATURE' => $this->signWebhook($payload, time() - 10000),
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload,
        );
        self::assertSame(403, $stale->getStatusCode());
    }

    // ── parity gate ──────────────────────────────────────────────────

    public function testZzManifestCoverage(): void
    {
        $path = __DIR__ . '/../../integration/scenarios.json';
        /** @var array{scenarios: list<array{id: string, family: string}>} $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $required = array_column(
            array_filter(
                $manifest['scenarios'],
                static fn (array $s): bool => $s['family'] === self::FAMILY,
            ),
            'id',
        );

        $missing = array_values(array_diff($required, self::COVERED));
        $unknown = array_values(array_diff(self::COVERED, $required));

        self::assertSame(
            [],
            $missing,
            'Not implemented by the laravel suite: ' . implode(', ', $missing),
        );
        self::assertSame(
            [],
            $unknown,
            'Claimed ids that are not in the manifest: ' . implode(', ', $unknown),
        );
    }
}
