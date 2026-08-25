<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Feature;

use BillKit\Laravel\Checkout;
use BillKit\Laravel\Tests\TestCase;

final class CheckoutTest extends TestCase
{
    public function test_checkout_creates_customer_then_returns_hosted_redirect(): void
    {
        $this->http
            ->stage(200, ['id' => 'cus_1', 'object' => 'customer'])
            ->stage(200, ['id' => 'cs_1', 'object' => 'checkout_session', 'url' => 'https://www.mollie.com/checkout/abc']);

        $user = $this->makeUser();
        $checkout = $user->checkout('price_1', [
            'success_url' => 'https://app.test/ok',
            'cancel_url' => 'https://app.test/no',
        ]);

        self::assertInstanceOf(Checkout::class, $checkout);
        self::assertSame('https://www.mollie.com/checkout/abc', $checkout->url());
        self::assertSame('cus_1', $user->fresh()?->billkit_customer_id);

        self::assertCount(2, $this->http->requests);
        self::assertStringEndsWith('/v1/customers', (string) $this->http->requests[0]->getUri());
        self::assertStringEndsWith('/v1/checkout/sessions', (string) $this->http->requests[1]->getUri());
        $body = $this->http->bodyOf($this->http->requests[1]);
        self::assertSame('cus_1', $body['customer_id']);
        self::assertSame('price_1', $body['price_id']);
        self::assertSame('https://app.test/ok', $body['success_url']);
    }

    public function test_checkout_reuses_existing_customer(): void
    {
        $this->http->stage(200, ['id' => 'cs_2', 'url' => 'https://www.mollie.com/checkout/def']);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_existing'])->save();

        $checkout = $user->checkout('price_1');

        self::assertSame('https://www.mollie.com/checkout/def', $checkout->url());
        self::assertCount(1, $this->http->requests);
        self::assertSame('cus_existing', $this->http->bodyOf($this->http->requests[0])['customer_id']);
    }

    public function test_checkout_is_responsable_and_redirects(): void
    {
        $this->http->stage(200, ['id' => 'cs_3', 'url' => 'https://www.mollie.com/checkout/xyz']);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_x'])->save();

        $response = $user->checkout('price_1')->toResponse(request());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://www.mollie.com/checkout/xyz', $response->headers->get('Location'));
    }
}
