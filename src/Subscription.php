<?php

declare(strict_types=1);

namespace BillKit\Laravel;

use BillKit\BillKitClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/**
 * Local mirror of a BillKit subscription, kept in sync by the webhook
 * controller. Action methods delegate to the API and re-sync from the
 * response.
 *
 * BillKit subscriptions are single-price, so there is no SubscriptionItem.
 *
 * @property int $id
 * @property string|null $billable_type
 * @property int|string|null $billable_id
 * @property string $type
 * @property string $billkit_id
 * @property string|null $billkit_customer_id
 * @property string|null $price_id
 * @property string $status
 * @property string|null $renewal_state
 * @property bool $cancel_at_period_end
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $canceled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Subscription extends Model
{
    /** Statuses that grant entitlement (mirrors the API's ENTITLED set). */
    public const ENTITLED = ['active', 'trialing', 'past_due'];

    protected $table = 'billkit_subscriptions';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cancel_at_period_end' => 'boolean',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    // ─── State ──────────────────────────────────────────────────────

    /** Entitled (active/trialing/past_due) or still within a cancel grace period. */
    public function valid(): bool
    {
        return in_array($this->status, self::ENTITLED, true) || $this->onGracePeriod();
    }

    public function active(): bool
    {
        return $this->status === 'active';
    }

    public function trialing(): bool
    {
        return $this->status === 'trialing';
    }

    public function onTrial(): bool
    {
        return $this->trialing()
            || ($this->trial_ends_at !== null && $this->trial_ends_at->isFuture());
    }

    public function pastDue(): bool
    {
        return $this->status === 'past_due';
    }

    /**
     * Paused: renewals are off, but the paid-for period is still running.
     *
     * Read from `renewal_state`, not `status`. Pausing stops the renewal
     * and leaves `status` at `active`, because the customer has paid for
     * the period they are in and is still entitled to it. No BillKit
     * subscription ever carries `status = 'paused'`, so the old check
     * here was dead: it answered "no" to every paused subscription.
     */
    public function paused(): bool
    {
        return $this->renewal_state === 'paused';
    }

    public function canceled(): bool
    {
        return $this->status === 'canceled' || $this->canceled_at !== null;
    }

    /** Canceled-at-period-end but the paid-through period hasn't elapsed yet. */
    public function onGracePeriod(): bool
    {
        return $this->cancel_at_period_end
            && $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }

    public function ended(): bool
    {
        return $this->canceled() && ! $this->onGracePeriod();
    }

    // ─── Actions (delegate to the API, then re-sync) ────────────────

    public function cancel(): self
    {
        return $this->syncFromApi($this->client()->subscriptions->cancel($this->billkit_id));
    }

    public function pause(): self
    {
        return $this->syncFromApi($this->client()->subscriptions->pause($this->billkit_id));
    }

    public function resume(): self
    {
        return $this->syncFromApi($this->client()->subscriptions->resume($this->billkit_id));
    }

    /** Undo a scheduled cancellation while still inside the current period. */
    public function reactivate(): self
    {
        return $this->syncFromApi($this->client()->subscriptions->reactivate($this->billkit_id));
    }

    /**
     * Preview the proration of switching to another price (no state change).
     *
     * @return array<string, mixed>
     */
    public function previewSwap(string $priceId): array
    {
        return $this->client()->subscriptions->previewUpdate($this->billkit_id, $priceId);
    }

    /** Switch to another price. */
    public function swap(string $priceId): self
    {
        return $this->syncFromApi($this->client()->subscriptions->update($this->billkit_id, $priceId));
    }

    /**
     * Start a hosted re-authorization of the payment mandate.
     *
     * Returns the redirect URL to send the customer to (mandate re-auth is
     * always a hosted redirect, never an inline confirm).
     */
    public function updatePaymentMethod(string $returnUrl): string
    {
        $result = $this->client()->subscriptions->reauthorizePaymentMethod($this->billkit_id, $returnUrl);
        $url = $result['url'] ?? $result['redirect_url'] ?? null;

        return is_string($url) ? $url : '';
    }

    /** Mint a customer-facing billing-portal URL scoped to this subscription. */
    public function billingPortalUrl(string $returnUrl): string
    {
        $session = $this->client()->billingPortalSessions->create([
            'subscription_id' => $this->billkit_id,
            'return_url' => $returnUrl,
        ]);

        return is_string($session['url'] ?? null) ? $session['url'] : '';
    }

    public function redirectToBillingPortal(string $returnUrl): RedirectResponse
    {
        return new RedirectResponse($this->billingPortalUrl($returnUrl));
    }

    // ─── Metered usage ──────────────────────────────────────────────
    //
    // Cashier's shape (`reportUsage` / `usageRecords`), against BillKit's
    // metered subscriptions. These do NOT re-sync the model: a usage record
    // is not a subscription state change, and nothing on this row moves
    // when one is written.

    /**
     * Report consumption against this subscription's meter.
     *
     * Only valid when the subscription's price is `usage_type: "metered"`.
     *
     * `$identifier` is your own id for the event being metered, and it is
     * the dedupe an idempotency key cannot do. The key covers a retry of
     * one HTTP request; `$identifier` covers a retry of *your* call — a
     * queued job replaying, a webhook you handle twice, a retried
     * `dispatch()` — which reaches the API as a genuinely new request with
     * a new key. A second report of the same identifier returns the first
     * record unchanged rather than billing twice.
     *
     * In a Laravel app that almost always means: pass something derived
     * from the job, not `uniqid()`. `$job->uuid()` or the domain event's
     * primary key both work; a value that changes per attempt does not.
     *
     * @param array<string, string> $metadata
     *
     * @return array<string, mixed> the created (or already-existing) usage record
     */
    public function reportUsage(
        int $quantity = 1,
        ?string $identifier = null,
        ?int $occurredAt = null,
        array $metadata = [],
    ): array {
        return $this->client()->subscriptions->createUsageRecord($this->billkit_id, array_filter([
            'quantity' => $quantity,
            'identifier' => $identifier,
            'occurred_at' => $occurredAt,
            'metadata' => $metadata === [] ? null : $metadata,
        ], static fn ($v): bool => $v !== null));
    }

    /**
     * One page of usage records, newest first.
     *
     * `$invoiceId` filters by billing state: `'pending'` is what the next
     * period close will bill, a concrete `inv_…` id is what that invoice
     * billed. Null lists everything.
     *
     * @return array<string, mixed>
     */
    public function usageRecords(?string $invoiceId = null, ?int $limit = null): array
    {
        return $this->client()->subscriptions->listUsageRecords($this->billkit_id, array_filter([
            'invoice_id' => $invoiceId,
            'limit' => $limit,
        ], static fn ($v): bool => $v !== null));
    }

    /**
     * What the next period close will bill, priced.
     *
     * {@see self::usageRecords()} gives the quantity; this gives the money:
     * `pending_quantity`, `net_cents` / `tax_cents` / `gross_cents`, and
     * `will_charge`.
     *
     * **Read `will_charge` before showing a customer an amount.** A period
     * whose total is under `minimum_charge_cents` (EUR 1.00) is not
     * charged, because the payment provider would refuse it; the usage
     * stays pending and rolls into the next period, which is then billed
     * for both. A dashboard that renders `gross_cents` as "your next
     * invoice" without checking this is wrong exactly when the number is
     * small.
     *
     * @return array<string, mixed>
     */
    public function usageSummary(): array
    {
        return $this->client()->subscriptions->retrieveUsageSummary($this->billkit_id);
    }

    /**
     * Map a wire subscription object (epoch-int timestamps) onto this row.
     *
     * A field the payload does not mention keeps its current value, because
     * the action endpoints answer with the whole subscription but a caller
     * may pass a partial object. A field the payload sends as ``null`` is
     * written as ``null``: ``null`` is an answer, not a gap. Collapsing the
     * two with ``??`` made a cleared field unclearable — a trial that
     * converts sends ``trial_end: null`` and would have left the old trial
     * date on the row forever.
     *
     * @param array<string, mixed> $data
     */
    public function syncFromApi(array $data): self
    {
        $attributes = [];
        foreach (['price_id', 'renewal_state'] as $column) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = is_string($data[$column]) ? $data[$column] : null;
            }
        }
        // `status` is the one column that is never null, so an unusable value
        // leaves the row's current status alone rather than clearing it.
        if (is_string($data['status'] ?? null)) {
            $attributes['status'] = $data['status'];
        }
        if (array_key_exists('cancel_at_period_end', $data)) {
            $attributes['cancel_at_period_end'] = (bool) $data['cancel_at_period_end'];
        }
        foreach ([
            'current_period_start' => 'current_period_start',
            'current_period_end' => 'current_period_end',
            'trial_end' => 'trial_ends_at',
            'canceled_at' => 'canceled_at',
        ] as $wire => $column) {
            if (array_key_exists($wire, $data)) {
                $attributes[$column] = self::ts($data[$wire]);
            }
        }

        $this->fill($attributes);
        $this->save();

        return $this;
    }

    /** Epoch seconds to a Carbon, or null for anything that is not one. */
    private static function ts(mixed $epoch): ?Carbon
    {
        return is_int($epoch) ? Carbon::createFromTimestamp($epoch) : null;
    }

    private function client(): BillKitClient
    {
        return app(BillKitClient::class);
    }
}
