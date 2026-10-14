<?php

declare(strict_types=1);

namespace BillKit\Laravel\Events;

use BillKit\Model\Event;

/**
 * Dispatched for every verified inbound webhook, before the package syncs
 * any local state. Listen for this to react to events the package does not
 * handle itself.
 *
 * `$event` is the typed event (`SubscriptionUpdatedEvent`,
 * `InvoicePaidEvent`, ... or a plain `Event` for a type the SDK does not
 * know); `$payload` is the same delivery as a decoded array, as in Cashier.
 */
final class WebhookReceived
{
    /**
     * @param array<string, mixed> $payload the decoded event envelope
     */
    public function __construct(
        public readonly array $payload,
        public readonly Event $event,
    ) {
    }
}
