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
            ->stage(200, ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://www.mollie.com/checkout/abc']);

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

    public function test_checkout_forwards_the_buyer_country(): void
    {
        // The country is what lets VAT apply to the FIRST charge: on the
        // hosted flow the buyer only reaches a country-collecting page
        // after the charge already exists.
        $this->http->stage(200, ['id' => 'cs_4', 'url' => 'https://www.mollie.com/checkout/nl']);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_nl'])->save();

        $user->checkout('price_1', ['country' => 'NL']);

        $body = $this->http->bodyOf($this->http->requests[0]);
        self::assertSame('NL', $body['country']);
    }

    public function test_checkout_omits_the_country_when_not_given(): void
    {
        $this->http->stage(200, ['id' => 'cs_5', 'url' => 'https://www.mollie.com/checkout/none']);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_none'])->save();

        $user->checkout('price_1');

        self::assertArrayNotHasKey('country', $this->http->bodyOf($this->http->requests[0]));
    }

    public function test_trial_days_is_sent_as_the_api_field(): void
    {
        $this->http->stage(200, ['id' => 'cs_t', 'url' => 'https://www.mollie.com/checkout/t']);
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_1'])->save();

        $user->checkout('price_1', ['trial_days' => 14, 'success_url' => 'https://app.test/ok', 'cancel_url' => 'https://app.test/no']);

        $body = $this->http->bodyOf($this->http->lastRequest());
        self::assertSame(14, $body['trial_days_override']);
        self::assertArrayNotHasKey('trial_days', $body);
    }

    /**
     * One spelling only. 0.x took both and silently preferred one, so a
     * typo'd key and a correct one could disagree about the trial.
     */
    public function test_the_api_spelling_of_the_trial_is_refused(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_1'])->save();

        try {
            $user->checkout('price_1', ['trial_days_override' => 14]);
            self::fail('expected \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('trial_days', $e->getMessage());
        }
        self::assertSame([], $this->http->requests);
    }

    public function test_payment_method_is_sent_under_its_api_name(): void
    {
        $this->http->stage(200, ['id' => 'cs_m', 'url' => 'https://www.mollie.com/checkout/m']);
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_1'])->save();

        $user->checkout('price_1', ['payment_method' => 'ideal', 'success_url' => 'https://app.test/ok', 'cancel_url' => 'https://app.test/no']);

        $body = $this->http->bodyOf($this->http->lastRequest());
        self::assertSame('ideal', $body['payment_method']);
        self::assertArrayNotHasKey('method', $body);
    }

    /**
     * The 0.x option name is refused, not dropped: dropping it would let the
     * buyer pick any method, a different checkout from the one asked for.
     */
    public function test_the_0x_method_option_is_refused(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_1'])->save();

        try {
            $user->checkout('price_1', ['method' => 'ideal']);
            self::fail('expected \InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('payment_method', $e->getMessage());
        }
        self::assertSame([], $this->http->requests);
    }

    public function test_charge_is_now_checkout_charge(): void
    {
        $user = new \ReflectionClass(\BillKit\Laravel\Tests\Fixtures\User::class);

        self::assertFalse($user->hasMethod('charge'));
        self::assertFalse($user->hasMethod('chargeEmbedded'));
        self::assertTrue($user->hasMethod('checkoutCharge'));
        self::assertTrue($user->hasMethod('checkoutChargeEmbedded'));
    }

    public function test_checkout_sends_metadata_to_the_session_and_not_the_customer(): void
    {
        $this->http
            ->stage(200, ['id' => 'cus_m', 'object' => 'customer'])
            ->stage(200, ['id' => 'cs_m', 'url' => 'https://www.mollie.com/checkout/m']);

        $user = $this->makeUser();
        $user->checkout('price_1', [
            'success_url' => 'https://app.test/ok',
            'cancel_url' => 'https://app.test/no',
            'metadata' => ['order_id' => 'ord_42'],
        ]);

        self::assertArrayNotHasKey('metadata', $this->http->bodyOf($this->http->requests[0]));
        self::assertSame(['order_id' => 'ord_42'], $this->http->bodyOf($this->http->requests[1])['metadata']);
    }

    public function test_return_urls_may_be_route_names(): void
    {
        \Illuminate\Support\Facades\Route::get('/billing/done', static fn () => 'ok')->name('billing.done');
        \Illuminate\Support\Facades\Route::get('/billing/back', static fn () => 'back')->name('billing.back');
        \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
        config(['billkit.cancel_url' => 'billing.back']);
        $this->http
            ->stage(200, ['id' => 'cs_r', 'url' => 'https://www.mollie.com/checkout/r'])
            ->stage(200, ['id' => 'osp_r', 'redirect_url' => 'https://www.mollie.com/checkout/o']);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_r'])->save();
        $user->checkout('price_1', ['success_url' => 'billing.done']);
        $user->checkoutCharge(500, 'EUR', 'ideal', ['success_url' => 'https://app.test/absolute']);

        $session = $this->http->bodyOf($this->http->requests[0]);
        self::assertSame(route('billing.done'), $session['success_url']);
        self::assertStringStartsWith('http', $session['success_url']);
        self::assertSame(route('billing.back'), $session['cancel_url']);

        $oneShot = $this->http->bodyOf($this->http->requests[1]);
        self::assertSame('https://app.test/absolute', $oneShot['success_url']);
        self::assertSame(route('billing.back'), $oneShot['cancel_url']);
    }
}
