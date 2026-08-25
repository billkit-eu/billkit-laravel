<?php

declare(strict_types=1);

namespace BillKit\Laravel\Events;

/**
 * Fired after the package has finished syncing local state for a verified
 * inbound webhook.
 */
final class WebhookHandled
{
    /**
     * @param array<string, mixed> $payload the decoded event envelope
     */
    public function __construct(public readonly array $payload)
    {
    }
}
