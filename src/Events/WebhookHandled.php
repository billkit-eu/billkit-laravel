<?php

declare(strict_types=1);

namespace BillKit\Laravel\Events;

use BillKit\Model\Event;

/**
 * Dispatched after the package has finished syncing local state for a
 * verified inbound webhook. Same fields as {@see WebhookReceived}.
 */
final class WebhookHandled
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
