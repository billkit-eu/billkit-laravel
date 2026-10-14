<?php

declare(strict_types=1);

namespace BillKit\Laravel;

use BillKit\BillKitClient;
use BillKit\Collection;
use BillKit\Exception\BillKitException;
use BillKit\Laravel\Events\SubscriptionSynced;
use BillKit\Model\PlanChangePreview;
use BillKit\Model\Subscription as SubscriptionObject;
use BillKit\Model\UsageRecord;
use BillKit\Model\UsageSummary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/**
 * Local mirror of a BillKit subscription, kept in sync by the webhook
 * controller. Action methods delegate to the API and re-sync from the
 * response; every sync dispatches {@see SubscriptionSynced}.
 *
 * BillKit subscriptions are single-price, so there is no SubscriptionItem.
 *
 * The class is `final`. React to changes by listening for
 * {@see SubscriptionSynced}, and add relations from your own code with
 * Eloquent's `Subscription::resolveRelationUsing('team', fn ($s) => ...)`.
 * The `type` column exists for schema compatibility and is always
 * `'default'`; see {@see Billable::subscription()}.
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
 * @property string|null $coupon_id
 * @property Carbon|null $discount_ends_at
 * @property string|null $payment_method_type
 * @property string|null $card_brand
 * @property string|null $card_last_four
 * @property int|null $card_exp_month
 * @property int|null $card_exp_year
 * @property bool|null $serves_customer
 * @property int|null $last_event_created
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Subscription extends Model
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
            'discount_ends_at' => 'datetime',
            'card_exp_month' => 'integer',
            'card_exp_year' => 'integer',
            'serves_customer' => 'boolean',
            'last_event_created' => 'integer',
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

    /**
     * Should the customer be served right now? What `subscribed()` reads.
     *
     * The API answers this itself as `serves_customer`, and the row mirrors
     * it. Reading `status` alone over-grants: cancelling or pausing stops the
     * renewals but leaves `status` at `active` until the period runs out, so
     * a status check keeps serving a customer from whom no money will arrive.
     *
     * The mirrored answer is a snapshot taken when the row was last synced,
     * and for a subscription that is not renewing (`canceling`, `paused`,
     * `stopped`) the API's answer turns false at `current_period_end` without
     * any event marking the moment. So a non-renewing row is also held to its
     * period end here, which is the same rule the API applies.
     *
     * `serves_customer` is null only on a row no subscription payload has
     * reached since the column was added. That row is answered from its
     * status and renewal state by the same rule until the next webhook or
     * action verb fills the column.
     *
     * `past_due` counts as served, because the API's `serves_customer` does:
     * the customer keeps access while the payment is retried. Cashier's
     * default revokes on `past_due`; add `&& ! $sub->pastDue()` if you want
     * that.
     */
    public function valid(): bool
    {
        $served = $this->serves_customer ?? in_array($this->status, self::ENTITLED, true);
        if (! $served) {
            return false;
        }
        if ($this->renewal_state === null || $this->renewal_state === 'auto_renew') {
            return true;
        }

        return $this->current_period_end !== null && $this->current_period_end->isFuture();
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

    /**
     * Cashier's meaning: the customer has cancelled, whether or not the
     * paid-through period is still running. True for a scheduled
     * cancellation still on its grace period and for a subscription the API
     * has ended (`status: canceled`). {@see self::ended()} is the terminal
     * half alone.
     */
    public function canceled(): bool
    {
        return $this->terminated() || $this->onGracePeriod();
    }

    /**
     * Cancellation is scheduled for the period end and that end is still
     * ahead. BillKit's shape for this is `status: active` with
     * `renewal_state: canceling` and `cancel_at_period_end: true`.
     *
     * Never true once the API reports `status: canceled`. The API keeps
     * `cancel_at_period_end` set when a scheduled cancellation is overtaken
     * by an immediate one, so the flag and a future period end can both
     * survive on a terminal row; answering true there would let Cashier's
     * `subscribed() || onGracePeriod()` serve a customer the API has cut
     * off, and disagree with {@see self::valid()}.
     */
    public function onGracePeriod(): bool
    {
        return ! $this->terminated()
            && $this->cancel_at_period_end
            && $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }

    /** Cancelled and no longer on a grace period: the API has ended it. */
    public function ended(): bool
    {
        return $this->canceled() && ! $this->onGracePeriod();
    }

    /**
     * The API has ended the subscription. `canceled` is its only terminal
     * status, and `canceled_at` is stamped only by that transition.
     */
    private function terminated(): bool
    {
        return $this->status === 'canceled' || $this->canceled_at !== null;
    }

    // ─── Discount ───────────────────────────────────────────────────

    /**
     * A coupon is bound and its window has not closed.
     *
     * `discount_ends_at` is null for a `forever` coupon, and also for a
     * `once` or `repeating` coupon until its first discounted charge, since
     * the window is measured from that charge. Both read as "still applies".
     * The time check covers a `subscription.coupon_expired` webhook that has
     * not arrived yet; the webhook itself clears the columns.
     */
    public function hasDiscount(): bool
    {
        return $this->coupon_id !== null
            && ($this->discount_ends_at === null || $this->discount_ends_at->isFuture());
    }

    /** The bound coupon's id, or null. Read the coupon itself through `billkit()->coupons`. */
    public function couponId(): ?string
    {
        return $this->coupon_id;
    }

    /**
     * When the discount stops applying (exclusive): a charge dated at or
     * after it is billed at list price. Null when there is no discount, for a
     * `forever` coupon, and before the first discounted charge fixes it.
     */
    public function discountEndsAt(): ?Carbon
    {
        return $this->coupon_id === null ? null : $this->discount_ends_at;
    }

    // ─── Payment method ─────────────────────────────────────────────
    //
    // What the next renewal charges. Kept per subscription rather than on
    // the billable (Cashier's `pm_type` / `pm_last_four`), because a BillKit
    // mandate belongs to a subscription.

    /** False only while an imported subscription awaits activation. */
    public function hasPaymentMethod(): bool
    {
        return $this->payment_method_type !== null;
    }

    /**
     * The mandate's rail: `creditcard`, `directdebit` (SEPA) or `paypal`.
     *
     * Never the checkout method that minted it: an iDEAL or EPS checkout
     * mints a SEPA mandate, so it reads `directdebit`. Unlike Cashier's
     * `pm_type` this is never a card brand; that is {@see self::cardBrand()}.
     */
    public function paymentMethodType(): ?string
    {
        return $this->payment_method_type;
    }

    /** Renews on a card. The card fields may still be null if the provider did not report them. */
    public function hasCard(): bool
    {
        return $this->payment_method_type === 'creditcard';
    }

    /** The card's brand as the provider reports it, or null for a non-card mandate. */
    public function cardBrand(): ?string
    {
        return $this->card_brand;
    }

    /** Cashier's `pm_last_four`: the card's last four digits, or null for a non-card mandate. */
    public function cardLastFour(): ?string
    {
        return $this->card_last_four;
    }

    // ─── Actions (delegate to the API, then re-sync) ────────────────

    /**
     * Cancel at period end.
     *
     * While a renewal payment is still settling this, {@see self::pause()}
     * and {@see self::swap()} throw a ``ConflictException`` (``409
     * operation_pending``, ``reason`` is ``'renewal_processing'``) whose
     * message names when to retry, and the row is not touched.
     *
     * @param array<string, mixed> $opts the PHP client's request options (`idempotency_key`, ...)
     */
    public function cancel(array $opts = []): self
    {
        return $this->syncFromAction($this->client()->subscriptions->cancel($this->billkit_id, [], $opts));
    }

    /**
     * Pause renewals. `paused()` turns true; `status` stays `active` for the
     * period already paid for.
     *
     * @param array<string, mixed> $opts
     */
    public function pause(array $opts = []): self
    {
        return $this->syncFromAction($this->client()->subscriptions->pause($this->billkit_id, [], $opts));
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function resume(array $opts = []): self
    {
        return $this->syncFromAction($this->client()->subscriptions->resume($this->billkit_id, [], $opts));
    }

    /**
     * Undo a scheduled cancellation while still inside the current period.
     *
     * @param array<string, mixed> $opts
     */
    public function reactivate(array $opts = []): self
    {
        return $this->syncFromAction($this->client()->subscriptions->reactivate($this->billkit_id, [], $opts));
    }

    /**
     * Preview the proration of switching to another price (no state change).
     *
     * @param array<string, mixed> $opts
     */
    public function previewSwap(string $priceId, array $opts = []): PlanChangePreview
    {
        return $this->client()->subscriptions->previewUpdate($this->billkit_id, ['target_price_id' => $priceId], $opts);
    }

    /**
     * Switch to another price.
     *
     * The row usually keeps the old `price_id` after this returns: the switch
     * lands when the proration charge settles, and the `subscription.updated`
     * webhook moves the row then. Pass an `idempotency_key` derived from your
     * own request when the swap can be retried.
     *
     * @param array<string, mixed> $opts
     */
    public function swap(string $priceId, array $opts = []): self
    {
        return $this->syncFromAction(
            $this->client()->subscriptions->update($this->billkit_id, ['target_price_id' => $priceId], $opts),
        );
    }

    /**
     * Start a hosted re-authorisation of the payment mandate and return the
     * URL to send the customer to (the response's `checkout_url`). Mandate
     * re-authorisation is always a hosted redirect.
     */
    public function updatePaymentMethod(string $returnUrl): string
    {
        return self::requireUrl($this->client()->subscriptions->reauthorizePaymentMethod(
            $this->billkit_id,
            ['return_url' => $returnUrl],
        )->checkout_url, 'reauthorisation', 'checkout_url');
    }

    /** Mint a customer-facing billing-portal URL scoped to this subscription. */
    public function billingPortalUrl(string $returnUrl): string
    {
        return self::requireUrl($this->client()->billingPortalSessions->create([
            'subscription_id' => $this->billkit_id,
            'return_url' => $returnUrl,
        ])->url, 'billing portal session', 'url');
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
     * one HTTP request; `$identifier` covers a retry of *your* call (a
     * queued job replaying, a webhook you handle twice, a retried
     * `dispatch()`), which reaches the API as a genuinely new request with
     * a new key. A second report of the same identifier returns the first
     * record unchanged rather than billing twice.
     *
     * In a Laravel app that almost always means: pass something derived
     * from the job, not `uniqid()`. `$job->uuid()` or the domain event's
     * primary key both work; a value that changes per attempt does not.
     *
     * @param array<string, string> $metadata
     * @param array<string, mixed>  $opts     the PHP client's request options
     *
     * @return UsageRecord the created (or already-existing) usage record
     */
    public function reportUsage(
        int $quantity = 1,
        ?string $identifier = null,
        ?int $occurredAt = null,
        array $metadata = [],
        array $opts = [],
    ): UsageRecord {
        return $this->client()->subscriptions->createUsageRecord($this->billkit_id, array_filter([
            'quantity' => $quantity,
            'identifier' => $identifier,
            'occurred_at' => $occurredAt,
            'metadata' => $metadata === [] ? null : $metadata,
        ], static fn ($v): bool => $v !== null), $opts);
    }

    /**
     * One page of usage records, newest first, with the PHP client's
     * `listUsageRecords()` params: `invoice_id` filters by billing state
     * (`'pending'` is what the next period close will bill, a concrete
     * `inv_…` id is what that invoice billed), `limit` and `starting_after`
     * page. `->autoPagingIterator()` on the result walks every page.
     *
     * @param array<string, mixed> $params
     *
     * @return Collection<UsageRecord>
     */
    public function usageRecords(array $params = []): Collection
    {
        return $this->client()->subscriptions->listUsageRecords($this->billkit_id, $params);
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
     */
    public function usageSummary(): UsageSummary
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
     * two with ``??`` made a cleared field unclearable: a trial that
     * converts sends ``trial_end: null`` and would have left the old trial
     * date on the row forever.
     *
     * This never moves ``last_event_created``. Only the webhook controller
     * does, from the event's own ``created``. An action response carries no
     * event time, and stamping it with this server's clock would let a skewed
     * clock reject genuine later events. The action's own webhook arrives
     * with a ``created`` newer than anything it superseded, so the watermark
     * catches up from there.
     *
     * It dispatches nothing; the action verbs and the webhook controller
     * dispatch {@see SubscriptionSynced} once the write is committed.
     *
     * @param SubscriptionObject|array<string, mixed> $data
     */
    public function syncFromApi(SubscriptionObject|array $data): self
    {
        if ($data instanceof SubscriptionObject) {
            $data = $data->toArray();
        }
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
        if (array_key_exists('serves_customer', $data)) {
            $attributes['serves_customer'] = is_bool($data['serves_customer']) ? $data['serves_customer'] : null;
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

        if (array_key_exists('discount', $data)) {
            $attributes += self::discountAttributes($data['discount']);
        }
        if (array_key_exists('payment_method', $data)) {
            $attributes += self::paymentMethodAttributes($data['payment_method']);
        }

        $this->fill($attributes);
        $this->save();

        return $this;
    }

    /**
     * The wire `discount` onto its two columns. Anything that is not a
     * discount object, `null` included, clears both: a coupon that expired
     * or was removed is sent as `discount: null`.
     *
     * @return array{coupon_id: string|null, discount_ends_at: Carbon|null}
     */
    private static function discountAttributes(mixed $discount): array
    {
        $couponId = is_array($discount) && is_string($discount['coupon_id'] ?? null)
            ? $discount['coupon_id']
            : null;

        return [
            'coupon_id' => $couponId,
            'discount_ends_at' => $couponId !== null ? self::ts($discount['ends_at'] ?? null) : null,
        ];
    }

    /**
     * The wire `payment_method` onto its five columns. All five are written
     * every time, so a switch from a card to SEPA clears the card details
     * rather than leaving the old card's digits next to `directdebit`, and a
     * `null` (an import awaiting activation) clears everything.
     *
     * @return array<string, string|int|null>
     */
    private static function paymentMethodAttributes(mixed $method): array
    {
        $card = is_array($method) && is_string($method['type'] ?? null) ? $method : [];
        $type = is_string($card['type'] ?? null) ? $card['type'] : null;

        return [
            'payment_method_type' => $type,
            'card_brand' => is_string($card['brand'] ?? null) ? $card['brand'] : null,
            'card_last_four' => is_string($card['last4'] ?? null) ? $card['last4'] : null,
            'card_exp_month' => is_int($card['exp_month'] ?? null) ? $card['exp_month'] : null,
            'card_exp_year' => is_int($card['exp_year'] ?? null) ? $card['exp_year'] : null,
        ];
    }

    /** Sync from an action verb's response and announce it. */
    private function syncFromAction(SubscriptionObject $data): self
    {
        $this->syncFromApi($data);
        event(new SubscriptionSynced($this, null));

        return $this;
    }

    /**
     * A URL the caller is about to redirect to. An absent one is thrown on
     * rather than returned as '', which would redirect to the current page.
     */
    private static function requireUrl(?string $url, string $what, string $field): string
    {
        if ($url === null || $url === '') {
            throw new BillKitException("BillKit returned a {$what} without a {$field}.");
        }

        return $url;
    }

    /**
     * Epoch seconds to a Carbon in the app's timezone, or null for anything
     * that is not one.
     *
     * The timezone is passed explicitly because Carbon 3's
     * `createFromTimestamp()` defaults to UTC where Carbon 2 used the PHP
     * default. Eloquent stores a datetime as its wall-clock string with no
     * offset and reads it back in the app timezone, so a UTC instance in an
     * app on `Europe/Amsterdam` moved every period boundary by the offset:
     * `valid()`, `onGracePeriod()` and `onTrial()` turned false an hour or
     * two before the real end (and, west of UTC, stayed true that long
     * after it). Laravel sets the PHP default from `app.timezone`.
     */
    private static function ts(mixed $epoch): ?Carbon
    {
        return is_int($epoch) ? Carbon::createFromTimestamp($epoch, date_default_timezone_get()) : null;
    }

    private function client(): BillKitClient
    {
        return app(BillKitClient::class);
    }
}
