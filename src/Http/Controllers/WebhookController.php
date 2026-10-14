<?php

declare(strict_types=1);

namespace BillKit\Laravel\Http\Controllers;

use BillKit\Laravel\Events\SubscriptionSynced;
use BillKit\Laravel\Events\WebhookHandled;
use BillKit\Laravel\Events\WebhookReceived;
use BillKit\Laravel\Http\Middleware\VerifyWebhookSignature;
use BillKit\Laravel\Subscription;
use BillKit\Model\Event;
use BillKit\Model\Subscription as SubscriptionObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Receives verified BillKit webhooks and keeps the local {@see Subscription}
 * rows in sync. Every ``subscription.*`` event carries the full subscription
 * object as its ``data``, so a single generic sync covers create / update /
 * cancel / pause / resume / reactivate / trial / past_due / plan-change, and
 * the coupon and payment-method changes through the object's ``discount``
 * and ``payment_method``.
 *
 * Deliveries are not ordered: each is retried on its own schedule, so a
 * ``subscription.updated`` from hours ago can land after the
 * ``subscription.canceled`` that superseded it. The row records the
 * ``created`` of the newest event applied to it (``last_event_created``) and
 * an older event is skipped, so a late retry can never roll the row back.
 *
 * The class is final. Extend the behaviour through events, in this order:
 * {@see WebhookReceived} for every delivery, {@see SubscriptionSynced} when a
 * row was written, {@see WebhookHandled} after the package is done. To take
 * the route over entirely, set `billkit.path` to null and register your own,
 * keeping {@see VerifyWebhookSignature} in front of it.
 */
final class WebhookController
{
    public function handleWebhook(Request $request): Response
    {
        $event = $request->attributes->get(VerifyWebhookSignature::EVENT_ATTRIBUTE);
        if (! $event instanceof Event) {
            // Never act on an unverified body. Reaching here without the
            // middleware means the route was wired without it.
            throw new AccessDeniedHttpException(
                'BillKit webhooks must pass through the VerifyWebhookSignature middleware.',
            );
        }
        $payload = $event->toArray();

        event(new WebhookReceived($payload, $event));

        if (str_starts_with($event->type ?? '', 'subscription.') && $event->data instanceof SubscriptionObject) {
            $synced = $this->syncSubscription($event->data, $payload['created'] ?? null);
            if ($synced !== null) {
                event(new SubscriptionSynced($synced, $event));
            }
        }

        event(new WebhookHandled($payload, $event));

        return new Response('Webhook handled', Response::HTTP_OK);
    }

    /**
     * Apply a ``subscription.*`` event to its local row, unless the row has
     * already seen a newer one. Returns the written row, or null when the
     * event was skipped or carried no subscription.
     *
     * The comparison is on the envelope's ``created`` (epoch seconds), which
     * is what BillKit's webhook guide tells every receiver to do. An event
     * with the same ``created`` as the watermark is applied: BillKit emits a
     * transition and its paired ``subscription.updated`` in one write, with
     * one timestamp and the same object, so equal means "the same change",
     * and skipping it would drop the second of the pair for no reason. Two
     * distinct changes inside the same second cannot be told apart by
     * ``created`` alone; the next event about the subscription settles them.
     *
     * An event without an integer ``created`` is applied and leaves the
     * watermark where it was, because it makes no claim about its age.
     *
     * The read and the write run in one transaction with the row locked, so
     * two deliveries handled concurrently cannot both pass the check.
     *
     * @param mixed $created the envelope's `created`, used only when it is an int
     */
    private function syncSubscription(SubscriptionObject $data, mixed $created): ?Subscription
    {
        $id = $data['id'];
        if (! is_string($id) || $id === '') {
            return null;
        }
        $created = is_int($created) ? $created : null;

        return (new Subscription())->getConnection()->transaction(function () use ($id, $data, $created): ?Subscription {
            /** @var Subscription|null $existing */
            $existing = Subscription::query()->where('billkit_id', $id)->lockForUpdate()->first();
            $subscription = $existing ?? new Subscription(['billkit_id' => $id]);

            if (self::isStale($subscription, $created)) {
                return null;
            }

            $customerId = is_string($data['customer_id']) ? $data['customer_id'] : null;
            if ($customerId !== null) {
                $subscription->billkit_customer_id = $customerId;
                if ($subscription->billable_id === null) {
                    $billable = self::resolveBillable($customerId);
                    if ($billable !== null) {
                        $subscription->billable_type = $billable->getMorphClass();
                        $key = $billable->getKey();
                        $subscription->billable_id = is_int($key) || is_string($key) ? $key : null;
                    }
                }
            }
            if (($subscription->type ?? '') === '') {
                $subscription->type = 'default';
            }
            if ($created !== null) {
                $subscription->last_event_created = $created;
            }

            return $subscription->syncFromApi($data);
        });
    }

    /** The row has already applied an event strictly newer than this one. */
    private static function isStale(Subscription $subscription, ?int $created): bool
    {
        $watermark = $subscription->last_event_created;

        return $created !== null && $watermark !== null && $created < $watermark;
    }

    /**
     * Find the billable model that owns a BillKit customer id.
     */
    private static function resolveBillable(string $customerId): ?Model
    {
        $model = config('billkit.model');
        if (! is_string($model) || ! class_exists($model)) {
            return null;
        }
        $instance = new $model();
        if (! $instance instanceof Model) {
            return null;
        }

        $found = $instance->newQuery()->where('billkit_customer_id', $customerId)->first();

        return $found instanceof Model ? $found : null;
    }
}
