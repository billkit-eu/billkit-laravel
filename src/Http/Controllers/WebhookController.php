<?php

declare(strict_types=1);

namespace BillKit\Laravel\Http\Controllers;

use BillKit\Laravel\Events\WebhookHandled;
use BillKit\Laravel\Events\WebhookReceived;
use BillKit\Laravel\Subscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives verified BillKit webhooks and keeps the local {@see Subscription}
 * rows in sync. Every ``subscription.*`` event carries the full subscription
 * object as its ``data``, so a single generic sync covers create / update /
 * cancel / pause / resume / reactivate / trial / past_due / plan-change.
 *
 * Fires {@see WebhookReceived} and {@see WebhookHandled} so applications can
 * react to any event (e.g. ``invoice.paid``, ``payment.failed``) without
 * subclassing. Subclass and override {@see self::syncSubscription()} for
 * custom behaviour.
 */
class WebhookController
{
    public function handleWebhook(Request $request): Response
    {
        $decoded = json_decode($request->getContent(), true);
        /** @var array<string, mixed> $payload */
        $payload = is_array($decoded) ? $decoded : [];
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';

        event(new WebhookReceived($payload));

        if (str_starts_with($type, 'subscription.')) {
            $this->syncSubscription($payload);
        }

        event(new WebhookHandled($payload));

        return new Response('Webhook handled', Response::HTTP_OK);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function syncSubscription(array $payload): void
    {
        $data = $payload['data'] ?? null;
        if (! is_array($data) || ! isset($data['id']) || ! is_string($data['id'])) {
            return;
        }

        $subscription = Subscription::firstOrNew(['billkit_id' => $data['id']]);

        $customerId = is_string($data['customer_id'] ?? null) ? $data['customer_id'] : null;
        if ($customerId !== null) {
            $subscription->billkit_customer_id = $customerId;
            if ($subscription->billable_id === null) {
                $billable = $this->resolveBillable($customerId);
                if ($billable !== null) {
                    $subscription->billable_type = $billable->getMorphClass();
                    $subscription->billable_id = $billable->getKey();
                }
            }
        }
        if (($subscription->type ?? '') === '') {
            $subscription->type = 'default';
        }

        $subscription->syncFromApi($data);
    }

    /**
     * Find the billable model that owns a BillKit customer id.
     */
    protected function resolveBillable(string $customerId): ?Model
    {
        $model = config('billkit.model');
        if (! is_string($model) || ! class_exists($model)) {
            return null;
        }
        $instance = new $model();
        if (! $instance instanceof Model) {
            return null;
        }

        /** @var Model|null $found */
        $found = $instance->newQuery()->where('billkit_customer_id', $customerId)->first();

        return $found;
    }
}
