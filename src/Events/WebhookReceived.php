<?php

declare(strict_types=1);

namespace BillKit\Laravel\Events;

/**
 * Fired for every verified inbound webhook, before the package syncs any
 * local state. Listen for this to react to events the package doesn't
 * itself handle.
 */
final class WebhookReceived
{
    /**
     * @param array<string, mixed> $payload the decoded event envelope
     */
    public function __construct(public readonly array $payload)
    {
    }
}
