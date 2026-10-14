<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Feature;

use BillKit\Laravel\Tests\TestCase;

/**
 * A caller's `idempotency_key` reaches the wire as the `Idempotency-Key`
 * header on every Billable verb that accepts one, never in the body, and
 * never on the customer create that may run first.
 */
final class IdempotencyKeyTest extends TestCase
{
    public function test_charge_forwards_the_callers_key(): void
    {
        $this->http
            ->stage(200, ['id' => 'cus_1', 'object' => 'customer'])
            ->stage(200, ['id' => 'osp_1', 'redirect_url' => 'https://www.mollie.com/x']);

        $this->makeUser()->checkoutCharge(500, 'EUR', 'ideal', ['idempotency_key' => 'order-42-charge']);

        $this->assertKeyOnLastRequestOnly('order-42-charge');
    }

    public function test_charge_embedded_forwards_the_callers_key(): void
    {
        $this->http
            ->stage(200, ['id' => 'cus_1', 'object' => 'customer'])
            ->stage(200, ['id' => 'osp_1', 'client_secret' => 'osp_1_secret_x']);

        $this->makeUser()->checkoutChargeEmbedded(500, 'EUR', ['idempotency_key' => 'order-42-embedded']);

        $this->assertKeyOnLastRequestOnly('order-42-embedded');
    }

    public function test_checkout_forwards_the_callers_key(): void
    {
        $this->http
            ->stage(200, ['id' => 'cus_1', 'object' => 'customer'])
            ->stage(200, ['id' => 'cs_1', 'url' => 'https://www.mollie.com/x']);

        $this->makeUser()->checkout('price_1', ['idempotency_key' => 'signup-7']);

        $this->assertKeyOnLastRequestOnly('signup-7');
    }

    public function test_without_a_key_the_client_mints_one(): void
    {
        $this->http
            ->stage(200, ['id' => 'cus_1', 'object' => 'customer'])
            ->stage(200, ['id' => 'osp_1', 'redirect_url' => 'https://www.mollie.com/x']);

        $this->makeUser()->checkoutCharge(500, 'EUR', 'ideal');

        self::assertStringStartsWith('sdk-', $this->http->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    /**
     * 0.x merged the options into the refund body and relied on the PHP
     * client lifting `idempotency_key` out of it. In 1.0 the client refuses
     * the key in params, so the Billable has to move it to the request
     * options itself.
     */
    public function test_refunds_send_the_callers_key_as_the_header(): void
    {
        $this->http
            ->stage(200, ['id' => 're_1', 'object' => 'refund'])
            ->stage(200, ['id' => 're_2', 'object' => 'refund']);
        $user = $this->makeUser();

        $paymentRef = 'refund-pay_1';
        $oneShotRef = 'refund-osp_1';
        $user->refund('pay_1', ['amount_cents' => 500, 'idempotency_key' => $paymentRef]);
        $user->refundOneShot('osp_1', ['idempotency_key' => $oneShotRef]);

        self::assertSame('refund-pay_1', $this->http->requests[0]->getHeaderLine('Idempotency-Key'));
        self::assertSame(['payment_id' => 'pay_1', 'amount_cents' => 500], $this->http->bodyOf($this->http->requests[0]));
        self::assertSame('refund-osp_1', $this->http->requests[1]->getHeaderLine('Idempotency-Key'));
        self::assertSame(['one_shot_payment_id' => 'osp_1'], $this->http->bodyOf($this->http->requests[1]));
    }

    public function test_swap_takes_request_options(): void
    {
        $user = $this->makeUser();
        $sub = \BillKit\Laravel\Subscription::query()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
            'billkit_id' => 'sub_s',
            'status' => 'active',
        ]);
        $this->http->stage(200, ['id' => 'sub_s', 'object' => 'subscription', 'status' => 'active']);

        $swapRef = 'swap-sub_s-price_2';
        $sub->swap('price_2', ['idempotency_key' => $swapRef]);

        self::assertSame('swap-sub_s-price_2', $this->http->lastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertSame(['target_price_id' => 'price_2'], $this->http->bodyOf($this->http->lastRequest()));
    }

    private function assertKeyOnLastRequestOnly(string $key): void
    {
        self::assertCount(2, $this->http->requests);
        $customerCreate = $this->http->requests[0];
        $call = $this->http->requests[1];

        self::assertSame($key, $call->getHeaderLine('Idempotency-Key'));
        self::assertArrayNotHasKey('idempotency_key', $this->http->bodyOf($call));
        self::assertNotSame($key, $customerCreate->getHeaderLine('Idempotency-Key'));
    }
}
