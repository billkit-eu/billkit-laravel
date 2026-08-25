<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Feature;

use BillKit\Laravel\Checkout;
use BillKit\Laravel\Tests\TestCase;

final class OneShotTest extends TestCase
{
    public function test_charge_creates_customer_then_returns_hosted_redirect(): void
    {
        $this->http
            ->stage(200, ['id' => 'cus_1', 'object' => 'customer'])
            ->stage(200, [
                'id' => 'osp_1',
                'object' => 'one_shot_payment',
                'redirect_url' => 'https://www.mollie.com/checkout/one/abc',
            ]);

        $user = $this->makeUser();
        $checkout = $user->charge(1999, 'EUR', 'creditcard', [
            'success_url' => 'https://app.test/ok',
            'cancel_url' => 'https://app.test/no',
            'description' => 'A single widget',
        ]);

        self::assertInstanceOf(Checkout::class, $checkout);
        self::assertSame('https://www.mollie.com/checkout/one/abc', $checkout->url());
        self::assertSame('cus_1', $user->fresh()?->billkit_customer_id);

        self::assertCount(2, $this->http->requests);
        self::assertStringEndsWith('/v1/customers', (string) $this->http->requests[0]->getUri());
        self::assertStringEndsWith('/v1/checkout/one_shot', (string) $this->http->requests[1]->getUri());

        $body = $this->http->bodyOf($this->http->requests[1]);
        self::assertSame('cus_1', $body['customer_id']);
        self::assertSame(1999, $body['amount_cents']);
        self::assertSame('EUR', $body['currency']);
        self::assertSame('creditcard', $body['method']);
        self::assertSame('https://app.test/ok', $body['success_url']);
        self::assertSame('https://app.test/no', $body['cancel_url']);
        self::assertSame('A single widget', $body['description']);
    }

    public function test_charge_keeps_metadata_on_payment_not_customer(): void
    {
        // metadata is payment-scoped (PaymentIntent semantics); it must reach
        // the one-shot payload but NOT leak into customer creation.
        $this->http
            ->stage(200, ['id' => 'cus_1', 'object' => 'customer'])
            ->stage(200, [
                'id' => 'osp_m',
                'object' => 'one_shot_payment',
                'redirect_url' => 'https://www.mollie.com/checkout/one/m',
            ]);

        $user = $this->makeUser();
        $user->charge(1200, 'EUR', 'creditcard', [
            'success_url' => 'https://app.test/ok',
            'metadata' => ['order_id' => '1024'],
        ]);

        $customerBody = $this->http->bodyOf($this->http->requests[0]);
        self::assertArrayNotHasKey('metadata', $customerBody);

        $paymentBody = $this->http->bodyOf($this->http->requests[1]);
        self::assertSame(['order_id' => '1024'], $paymentBody['metadata']);
    }

    public function test_charge_without_success_url_omits_the_key(): void
    {
        // With neither an option nor a config default, the key is dropped and
        // the API rejects it (success_url is required). Pin the behavior.
        config()->set('billkit.success_url', null);
        $this->http->stage(200, [
            'id' => 'osp_n',
            'object' => 'one_shot_payment',
            'redirect_url' => 'https://www.mollie.com/checkout/one/n',
        ]);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_x'])->save();
        $user->charge(1000, 'EUR', 'creditcard', []);

        $body = $this->http->bodyOf($this->http->requests[0]);
        self::assertArrayNotHasKey('success_url', $body);
    }

    public function test_charge_reuses_existing_customer_and_preserves_zero_refund_window(): void
    {
        $this->http->stage(200, [
            'id' => 'osp_2',
            'object' => 'one_shot_payment',
            'redirect_url' => 'https://www.mollie.com/checkout/one/def',
        ]);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_existing'])->save();

        $checkout = $user->charge(500, 'EUR', 'ideal', [
            'success_url' => 'https://app.test/ok',
            'refund_window_days' => 0,
        ]);

        self::assertSame('https://www.mollie.com/checkout/one/def', $checkout->url());
        self::assertCount(1, $this->http->requests);

        $body = $this->http->bodyOf($this->http->requests[0]);
        self::assertSame('cus_existing', $body['customer_id']);
        self::assertArrayHasKey('refund_window_days', $body);
        self::assertSame(0, $body['refund_window_days']);
    }

    public function test_charge_is_responsable_and_redirects(): void
    {
        $this->http->stage(200, [
            'id' => 'osp_3',
            'object' => 'one_shot_payment',
            'redirect_url' => 'https://www.mollie.com/checkout/one/xyz',
        ]);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_x'])->save();

        $response = $user->charge(1000, 'EUR', 'bancontact', [
            'success_url' => 'https://app.test/ok',
        ])->toResponse(request());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://www.mollie.com/checkout/one/xyz', $response->headers->get('Location'));
    }

    public function test_refund_one_shot_delegates_to_refunds_api(): void
    {
        $this->http->stage(200, ['id' => 're_1', 'object' => 'refund', 'status' => 'pending']);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_x'])->save();

        $refund = $user->refundOneShot('osp_1', ['amount_cents' => 500]);

        self::assertSame('re_1', $refund['id']);
        self::assertCount(1, $this->http->requests);
        self::assertStringEndsWith('/v1/refunds', (string) $this->http->requests[0]->getUri());

        $body = $this->http->bodyOf($this->http->requests[0]);
        self::assertSame('osp_1', $body['one_shot_payment_id']);
        self::assertSame(500, $body['amount_cents']);
    }

    public function test_refund_one_shot_full_refund_sends_only_the_id(): void
    {
        $this->http->stage(200, ['id' => 're_2', 'object' => 'refund', 'status' => 'pending']);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_x'])->save();

        $refund = $user->refundOneShot('osp_9');

        self::assertSame('re_2', $refund['id']);
        $body = $this->http->bodyOf($this->http->requests[0]);
        self::assertSame('osp_9', $body['one_shot_payment_id']);
        self::assertArrayNotHasKey('amount_cents', $body);
    }

    public function test_refund_delegates_to_refunds_api_by_payment_id(): void
    {
        $this->http->stage(200, ['id' => 're_3', 'object' => 'refund', 'status' => 'pending']);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_x'])->save();

        $refund = $user->refund('pay_1', ['amount_cents' => 250, 'reason' => 'duplicate']);

        self::assertSame('re_3', $refund['id']);
        self::assertCount(1, $this->http->requests);
        self::assertStringEndsWith('/v1/refunds', (string) $this->http->requests[0]->getUri());

        $body = $this->http->bodyOf($this->http->requests[0]);
        self::assertSame('pay_1', $body['payment_id']);
        self::assertSame(250, $body['amount_cents']);
        self::assertSame('duplicate', $body['reason']);
        self::assertArrayNotHasKey('one_shot_payment_id', $body);
    }

    public function test_refund_full_refund_sends_only_the_payment_id(): void
    {
        $this->http->stage(200, ['id' => 're_4', 'object' => 'refund', 'status' => 'pending']);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_x'])->save();

        $refund = $user->refund('pay_9');

        self::assertSame('re_4', $refund['id']);
        $body = $this->http->bodyOf($this->http->requests[0]);
        self::assertSame('pay_9', $body['payment_id']);
        self::assertArrayNotHasKey('amount_cents', $body);
        self::assertArrayNotHasKey('one_shot_payment_id', $body);
    }
}
