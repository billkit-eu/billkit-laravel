<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Feature;

use BillKit\Laravel\Events\SubscriptionSynced;
use BillKit\Laravel\Events\WebhookHandled;
use BillKit\Laravel\Events\WebhookReceived;
use BillKit\Laravel\Http\Controllers\WebhookController;
use BillKit\Laravel\Subscription;
use BillKit\Laravel\Tests\TestCase;
use BillKit\Model\InvoicePaidEvent;
use BillKit\Model\SubscriptionUpdatedEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The model and the controller are final; events are how an application
 * extends them. These pin what each event carries and when it fires.
 */
final class ExtensionPointsTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     */
    private function postSigned(array $payload): void
    {
        $body = (string) json_encode($payload);
        $this->call(
            'POST',
            '/billkit/webhook',
            server: ['HTTP_BILLKIT_SIGNATURE' => $this->signWebhook($body), 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        )->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private static function subscriptionEvent(int $created, string $status = 'active'): array
    {
        return [
            'id' => 'evt_' . $created,
            'object' => 'event',
            'type' => 'subscription.updated',
            'created' => $created,
            'livemode' => false,
            'previous_attributes' => null,
            'data' => [
                'id' => 'sub_x',
                'object' => 'subscription',
                'customer_id' => 'cus_x',
                'price_id' => 'price_1',
                'status' => $status,
                'renewal_state' => 'auto_renew',
                'cancel_at_period_end' => false,
                'serves_customer' => true,
            ],
        ];
    }

    public function test_both_classes_are_final(): void
    {
        self::assertTrue((new \ReflectionClass(Subscription::class))->isFinal());
        self::assertTrue((new \ReflectionClass(WebhookController::class))->isFinal());
    }

    public function test_webhook_events_carry_the_typed_event_and_the_array(): void
    {
        Event::fake([WebhookReceived::class, WebhookHandled::class, SubscriptionSynced::class]);

        $this->postSigned(['id' => 'evt_1', 'object' => 'event', 'type' => 'invoice.paid', 'created' => 1, 'livemode' => false,
            'previous_attributes' => null, 'data' => ['id' => 'in_1', 'object' => 'invoice', 'customer_id' => 'cus_1']]);

        Event::assertDispatched(WebhookReceived::class, static fn (WebhookReceived $e): bool => $e->event instanceof InvoicePaidEvent
            && is_array($e->payload['data']) && ($e->payload['data']['id'] ?? null) === 'in_1'
            && $e->event->data?->customer_id === 'cus_1');
        Event::assertDispatched(WebhookHandled::class, static fn (WebhookHandled $e): bool => $e->event instanceof InvoicePaidEvent);
        Event::assertNotDispatched(SubscriptionSynced::class);
    }

    public function test_a_synced_subscription_is_announced_with_its_event(): void
    {
        Event::fake([SubscriptionSynced::class]);

        $this->postSigned(self::subscriptionEvent(1_700_000_000));

        Event::assertDispatched(
            SubscriptionSynced::class,
            static fn (SubscriptionSynced $e): bool => $e->subscription->billkit_id === 'sub_x'
                && $e->subscription->exists
                && $e->event instanceof SubscriptionUpdatedEvent,
        );
    }

    public function test_a_stale_event_is_skipped_and_not_announced(): void
    {
        $this->postSigned(self::subscriptionEvent(1_700_000_100, 'past_due'));
        Event::fake([SubscriptionSynced::class]);

        $this->postSigned(self::subscriptionEvent(1_700_000_000, 'active'));

        Event::assertNotDispatched(SubscriptionSynced::class);
        self::assertSame('past_due', Subscription::query()->where('billkit_id', 'sub_x')->value('status'));
    }

    public function test_an_action_verb_announces_the_sync_without_an_event(): void
    {
        $this->postSigned(self::subscriptionEvent(1_700_000_000));
        $row = Subscription::query()->where('billkit_id', 'sub_x')->firstOrFail();
        $this->http->stage(200, ['id' => 'sub_x', 'object' => 'subscription', 'status' => 'active', 'renewal_state' => 'paused']);
        Event::fake([SubscriptionSynced::class]);

        $row->pause();

        Event::assertDispatched(
            SubscriptionSynced::class,
            static fn (SubscriptionSynced $e): bool => $e->event === null && $e->subscription->paused(),
        );
    }

    public function test_an_action_verb_inside_a_rolled_back_transaction_announces_nothing(): void
    {
        $row = $this->pausableRow();
        $seen = [];
        Event::listen(SubscriptionSynced::class, static function (SubscriptionSynced $e) use (&$seen): void {
            $seen[] = $e->subscription->renewal_state;
        });

        try {
            DB::transaction(function () use ($row, &$seen): void {
                $row->pause();
                self::assertSame([], $seen, 'dispatched before the outer transaction committed');

                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame([], $seen, 'a listener heard about a write that was rolled back');
        self::assertNotSame('paused', Subscription::query()->where('billkit_id', 'sub_x')->value('renewal_state'));
    }

    public function test_an_action_verb_inside_a_committed_transaction_announces_once_after_commit(): void
    {
        $row = $this->pausableRow();
        $seen = [];
        Event::listen(SubscriptionSynced::class, static function (SubscriptionSynced $e) use (&$seen): void {
            $seen[] = $e->subscription->renewal_state;
        });

        DB::transaction(function () use ($row, &$seen): void {
            $row->pause();
            self::assertSame([], $seen, 'dispatched before the outer transaction committed');
        });

        self::assertSame(['paused'], $seen);
    }

    private function pausableRow(): Subscription
    {
        $this->postSigned(self::subscriptionEvent(1_700_000_000));
        $row = Subscription::query()->where('billkit_id', 'sub_x')->firstOrFail();
        $this->http->stage(200, ['id' => 'sub_x', 'object' => 'subscription', 'status' => 'active', 'renewal_state' => 'paused']);

        return $row;
    }

    public function test_the_controller_refuses_a_request_the_middleware_did_not_verify(): void
    {
        $request = Request::create('/anything', 'POST', content: (string) json_encode(self::subscriptionEvent(1)));

        try {
            (new WebhookController())->handleWebhook($request);
            self::fail('an unverified body must not be acted on');
        } catch (AccessDeniedHttpException) {
        }
        self::assertSame(0, Subscription::query()->count());
    }
}
