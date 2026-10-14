<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Feature;

use BillKit\Laravel\Tests\TestCase;

final class CustomerTest extends TestCase
{
    public function test_create_customer_persists_id_and_uses_model_attributes(): void
    {
        $this->http->stage(200, ['id' => 'cus_1', 'object' => 'customer']);

        $user = $this->makeUser();
        $customer = $user->createAsBillKitCustomer();

        self::assertSame('cus_1', $customer['id']);
        self::assertSame('cus_1', $user->fresh()?->billkit_customer_id);
        self::assertTrue($user->hasBillKitId());

        // email/name were pulled from the model.
        $body = $this->http->bodyOf($this->http->lastRequest());
        self::assertSame($user->getAttribute('email'), $body['email']);
        self::assertSame('Ada Lovelace', $body['name']);
    }

    public function test_create_or_get_reuses_existing_customer_without_a_call(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_existing'])->save();

        self::assertSame('cus_existing', $user->createOrGetBillKitCustomer());
        self::assertCount(0, $this->http->requests);
    }

    public function test_as_customer_retrieves_by_id(): void
    {
        $this->http->stage(200, ['id' => 'cus_1', 'email' => 'ada@example.com']);

        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_1'])->save();

        $customer = $user->asBillKitCustomer();

        self::assertSame('cus_1', $customer['id']);
        self::assertSame('GET', $this->http->lastRequest()->getMethod());
        self::assertStringEndsWith('/v1/customers/cus_1', (string) $this->http->lastRequest()->getUri());
    }

    /**
     * There are no subscription types: nothing in BillKit records one, so
     * `subscription('addon')` could only ever have found nothing. What
     * `subscription()` answers instead is which subscription serves the
     * customer, and that is the newest valid one even when an abandoned
     * checkout left a newer, dead row behind.
     */
    public function test_subscription_prefers_the_newest_valid_one_over_a_newer_dead_one(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_1'])->save();
        foreach ([['sub_old', 'active', 1_700_000_000], ['sub_live', 'active', 1_700_000_100], ['sub_abandoned', 'incomplete', 1_700_000_200]] as [$id, $status, $at]) {
            $row = new \BillKit\Laravel\Subscription([
                'billable_type' => $user->getMorphClass(),
                'billable_id' => $user->getKey(),
                'billkit_id' => $id,
                'billkit_customer_id' => 'cus_1',
                'status' => $status,
            ]);
            $row->created_at = \Illuminate\Support\Carbon::createFromTimestamp($at);
            $row->save();
        }

        self::assertSame('sub_live', $user->subscription()?->billkit_id);
        self::assertTrue($user->subscribed());
    }

    public function test_with_no_valid_subscription_the_newest_is_returned_and_not_subscribed(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_1'])->save();
        foreach ([['sub_a', 1_700_000_000], ['sub_b', 1_700_000_100]] as [$id, $at]) {
            $row = new \BillKit\Laravel\Subscription([
                'billable_type' => $user->getMorphClass(),
                'billable_id' => $user->getKey(),
                'billkit_id' => $id,
                'billkit_customer_id' => 'cus_1',
                'status' => 'canceled',
                'canceled_at' => \Illuminate\Support\Carbon::createFromTimestamp($at),
            ]);
            $row->created_at = \Illuminate\Support\Carbon::createFromTimestamp($at);
            $row->save();
        }

        self::assertSame('sub_b', $user->subscription()?->billkit_id);
        self::assertFalse($user->subscribed());
    }

    /**
     * PHP drops an extra argument silently, so a method that merely took no
     * parameter let `subscribed('pro')` ported from Cashier (or from 0.x,
     * which filtered on the row's `type`) answer true for a customer on any
     * plan. A type is refused instead.
     */
    public function test_a_subscription_type_is_refused_rather_than_ignored(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_1'])->save();
        \BillKit\Laravel\Subscription::query()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
            'type' => 'default',
            'billkit_id' => 'sub_basic',
            'billkit_customer_id' => 'cus_1',
            'price_id' => 'price_basic',
            'status' => 'trialing',
            'trial_ends_at' => now()->addDay(),
        ]);
        self::assertTrue($user->subscribed());

        foreach (['subscription', 'subscribed', 'onTrial', 'onGracePeriod'] as $method) {
            try {
                $user->{$method}('pro');
                self::fail("{$method}('pro') answered instead of refusing the type");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString("{$method}() takes no subscription type, and was given 'pro'", $e->getMessage());
            }
            // No argument, and an explicit null, are the supported calls.
            $user->{$method}();
            $user->{$method}(null);
        }
    }

    /**
     * A create response without an id used to yield `''`, which every caller
     * fed straight into a `customer_id` field, surfacing as a puzzling 400
     * one call later, with nothing pointing back at the cause.
     */
    public function test_create_or_get_refuses_a_customer_without_an_id(): void
    {
        $this->http->stage(200, ['object' => 'customer']);

        $this->expectException(\BillKit\Exception\BillKitException::class);
        $this->expectExceptionMessage('without an id');

        $this->makeUser()->createOrGetBillKitCustomer();
    }

    public function test_create_or_get_creates_once_and_persists_the_id(): void
    {
        $this->http->stage(200, ['id' => 'cus_new', 'object' => 'customer']);
        $user = $this->makeUser();

        self::assertSame('cus_new', $user->createOrGetBillKitCustomer());
        // A second call must not create a second customer.
        self::assertSame('cus_new', $user->fresh()?->createOrGetBillKitCustomer());
        self::assertCount(1, $this->http->requests);
    }

    /**
     * The three places this package passes `metadata` straight through to the
     * php client. An empty array has to reach the API as `{}`: PHP's `[]`
     * encodes as a JSON list, which the API refuses for a map with a 400. The
     * php client's transport makes that conversion, so this pins that the
     * package gets it too rather than re-encoding the body itself.
     */
    public function test_empty_metadata_is_sent_as_a_json_object(): void
    {
        $this->http
            ->stage(200, ['id' => 'cus_1', 'object' => 'customer'])
            ->stage(200, ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://www.mollie.com/checkout/abc'])
            ->stage(200, ['id' => 'osp_1', 'object' => 'one_shot_payment', 'checkout_url' => 'https://www.mollie.com/checkout/def']);
        $user = $this->makeUser();

        $user->createAsBillKitCustomer(['metadata' => []]);
        $user->checkout('price_1', ['success_url' => 'https://app.test/ok', 'cancel_url' => 'https://app.test/no', 'metadata' => []]);
        $user->checkoutCharge(1000, 'EUR', 'ideal', ['success_url' => 'https://app.test/ok', 'cancel_url' => 'https://app.test/no', 'metadata' => []]);

        self::assertCount(3, $this->http->requests);
        foreach ($this->http->requests as $request) {
            self::assertStringContainsString('"metadata":{}', (string) $request->getBody(), (string) $request->getUri());
        }
    }

    public function test_a_refused_refund_surfaces_as_the_php_clients_payment_exception(): void
    {
        $this->http->stage(402, ['error' => [
            'type' => 'payment_error',
            'code' => 'payment_refused',
            'message' => 'The provider refused the refund: insufficient balance.',
        ]]);
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_1'])->save();

        try {
            $user->refund('pay_1');
            self::fail('expected a PaymentException');
        } catch (\BillKit\Exception\PaymentException $e) {
            self::assertSame(402, $e->statusCode);
            self::assertSame('payment_refused', $e->errorCode);
        }
        self::assertCount(1, $this->http->requests);
    }
}
