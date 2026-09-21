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

    public function test_subscription_of_specific_type_is_selected(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['billkit_customer_id' => 'cus_1'])->save();
        foreach (['default', 'addon'] as $type) {
            \BillKit\Laravel\Subscription::query()->create([
                'billable_type' => $user->getMorphClass(),
                'billable_id' => $user->getKey(),
                'type' => $type,
                'billkit_id' => 'sub_' . $type,
                'billkit_customer_id' => 'cus_1',
                'status' => 'active',
            ]);
        }

        self::assertSame('sub_default', $user->subscription()?->billkit_id);
        self::assertSame('sub_addon', $user->subscription('addon')?->billkit_id);
        self::assertTrue($user->subscribed('addon'));
        self::assertFalse($user->subscribed('nonexistent'));
    }

    /**
     * A create response without an id used to yield `''`, which every caller
     * fed straight into a `customer_id` field — surfacing as a puzzling 400
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
}
