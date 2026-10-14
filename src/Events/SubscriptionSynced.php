<?php

declare(strict_types=1);

namespace BillKit\Laravel\Events;

use BillKit\Laravel\Subscription;
use BillKit\Model\Event;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Dispatched after a local {@see Subscription} row was written from the API
 * and the write committed: from a `subscription.*` webhook (`$event` is the
 * delivery) or from an action verb such as `cancel()` or `swap()` (`$event`
 * is null).
 *
 * "Committed" includes your own transactions. An action verb called inside
 * `DB::transaction()` dispatches when that transaction commits, and not at
 * all if it rolls back, so a listener never acts on a row that was then
 * rolled back ({@see ShouldDispatchAfterCommit}). Outside a transaction it
 * dispatches at once.
 *
 * This is the extension point for keeping your own state in step with a
 * subscription, in place of subclassing the model or the webhook
 * controller. A webhook older than one the row already applied is skipped
 * and dispatches nothing.
 */
final class SubscriptionSynced implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Subscription $subscription,
        public readonly ?Event $event,
    ) {
    }
}
