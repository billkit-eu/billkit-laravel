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

    public function paused(): bool
    {
        return $this->status === 'paused';
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

    /**
     * Map a wire subscription object (epoch-int timestamps) onto this row.
     *
     * @param array<string, mixed> $data
     */
    public function syncFromApi(array $data): self
    {
        $this->fill([
            'price_id' => $data['price_id'] ?? $this->price_id,
            'status' => is_string($data['status'] ?? null) ? $data['status'] : $this->status,
            'renewal_state' => $data['renewal_state'] ?? $this->renewal_state,
            'cancel_at_period_end' => (bool) ($data['cancel_at_period_end'] ?? $this->cancel_at_period_end),
            'current_period_start' => self::ts($data['current_period_start'] ?? null) ?? $this->current_period_start,
            'current_period_end' => self::ts($data['current_period_end'] ?? null) ?? $this->current_period_end,
            'trial_ends_at' => self::ts($data['trial_end'] ?? null) ?? $this->trial_ends_at,
            'canceled_at' => self::ts($data['canceled_at'] ?? null) ?? $this->canceled_at,
        ]);
        $this->save();

        return $this;
    }

    private static function ts(mixed $epoch): ?Carbon
    {
        return is_int($epoch) ? Carbon::createFromTimestamp($epoch) : null;
    }

    private function client(): BillKitClient
    {
        return app(BillKitClient::class);
    }
}
