<?php

declare(strict_types=1);

namespace BillKit\Laravel;

use BillKit\BillKitClient;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Give an Eloquent model (typically your ``User`` or ``Team``) a BillKit
 * customer and access to its subscriptions.
 *
 * Because BillKit provisions subscriptions from the inbound Mollie webhook
 * (there is no synchronous ``subscriptions.create``), the entry point is
 * {@see self::checkout()}. It returns a redirect to the hosted checkout,
 * and the local {@see Subscription} row materialises when your webhook
 * controller receives ``subscription.created``.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait Billable
{
    /** The BillKit customer id (``cus_...``) linked to this model, if any. */
    public function billkitId(): ?string
    {
        $id = $this->billkit_customer_id;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function hasBillKitId(): bool
    {
        return $this->billkitId() !== null;
    }

    /**
     * Create a BillKit customer for this model and persist its id.
     *
     * ``email`` / ``name`` fall back to the model's own attributes.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed> the created customer object
     */
    public function createAsBillKitCustomer(array $options = []): array
    {
        $payload = array_filter([
            'email' => $options['email'] ?? $this->getAttribute('email'),
            'name' => $options['name'] ?? $this->getAttribute('name'),
            'country_code' => $options['country_code'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ], static fn ($v): bool => $v !== null);

        $customer = $this->billkitClient()->customers->create($payload);
        $this->forceFill(['billkit_customer_id' => $customer['id'] ?? null])->save();

        return $customer;
    }

    /**
     * Return this model's customer id, creating the customer if needed.
     *
     * @param array<string, mixed> $options
     */
    public function createOrGetBillKitCustomer(array $options = []): string
    {
        $id = $this->billkitId();
        if ($id !== null) {
            return $id;
        }

        return (string) ($this->createAsBillKitCustomer($options)['id'] ?? '');
    }

    /**
     * Retrieve this model's BillKit customer object.
     *
     * @return array<string, mixed>
     */
    public function asBillKitCustomer(): array
    {
        return $this->billkitClient()->customers->retrieve($this->billkitIdOrFail());
    }

    /**
     * @return MorphMany<Subscription, $this>
     */
    public function billkitSubscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'billable')->latest('created_at');
    }

    /** The subscription of the given type (default: "default"), if any. */
    public function subscription(string $type = 'default'): ?Subscription
    {
        return $this->billkitSubscriptions->firstWhere('type', $type);
    }

    /** True when the model has a usable subscription of the given type. */
    public function subscribed(string $type = 'default'): bool
    {
        return $this->subscription($type)?->valid() ?? false;
    }

    public function onTrial(string $type = 'default'): bool
    {
        return $this->subscription($type)?->onTrial() ?? false;
    }

    public function onGracePeriod(string $type = 'default'): bool
    {
        return $this->subscription($type)?->onGracePeriod() ?? false;
    }

    /**
     * Start a hosted checkout for a price and return a redirectable Checkout.
     *
     * Options: ``success_url``, ``cancel_url`` (else config defaults),
     * ``trial_days``, ``coupon_code``, ``method`` (creditcard|directdebit|ideal),
     * ``ui_mode``, plus ``email``/``name``/``metadata`` used when creating the
     * customer.
     *
     * @param array<string, mixed> $options
     */
    public function checkout(string $priceId, array $options = []): Checkout
    {
        $config = $this->billkitConfig();

        $payload = array_filter([
            'customer_id' => $this->createOrGetBillKitCustomer($options),
            'price_id' => $priceId,
            'success_url' => $options['success_url'] ?? ($config['success_url'] ?? null),
            'cancel_url' => $options['cancel_url'] ?? ($config['cancel_url'] ?? null),
            'trial_days_override' => $options['trial_days'] ?? $options['trial_days_override'] ?? null,
            'coupon_code' => $options['coupon_code'] ?? null,
            'method' => $options['method'] ?? null,
            'ui_mode' => $options['ui_mode'] ?? null,
        ], static fn ($v): bool => $v !== null);

        return new Checkout($this->billkitClient()->checkoutSessions->create($payload));
    }

    /**
     * Charge this model a single, mandate-less amount and return a redirectable Checkout.
     *
     * A one-shot is BillKit's one-time-payment analog (Cashier's ``charge()``):
     * a single charge with **no** stored mandate. Nothing is saved for future
     * off-session reuse and the charge cannot be replayed later. Like
     * {@see self::checkout()} it is redirect-based, so this returns the same
     * {@see Checkout} responsable (wrapping the one-shot's ``redirect_url``),
     * NOT a synchronous charge object; send the buyer to the hosted Mollie page.
     *
     * The payment reaches a terminal state via the ``one_shot_payment.succeeded``
     * / ``one_shot_payment.failed`` webhooks; your webhook listener decides what
     * happens on success/failure.
     *
     * ``refund_window_days`` controls how long the charge stays refundable:
     * ``0`` disables refunds entirely, the default is ``30`` and the maximum is
     * ``365``. Refund a settled one-shot via {@see self::refundOneShot()}.
     *
     * Options: ``success_url`` / ``cancel_url`` (else config defaults),
     * ``description``, ``refund_window_days``, ``metadata``. ``metadata`` is
     * PaymentIntent-semantic: it is attached to the one-shot payment only,
     * never to the customer. ``email``/``name``/``country_code`` are used only
     * when a BillKit customer must be created for this model.
     *
     * @param int    $amountCents amount to charge, in the currency's minor unit
     * @param string $currency    ISO-4217 code, e.g. ``EUR``
     * @param string $method      creditcard|directdebit|ideal|bancontact|eps
     *
     * @param array<string, mixed> $options
     */
    public function charge(int $amountCents, string $currency, string $method, array $options = []): Checkout
    {
        $config = $this->billkitConfig();

        $payload = array_filter([
            // ``metadata`` is payment-scoped (PaymentIntent semantics), so it is
            // kept out of customer creation, otherwise the same bag would leak
            // onto a newly-created customer record.
            'customer_id' => $this->createOrGetBillKitCustomer(
                array_diff_key($options, ['metadata' => null]),
            ),
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'method' => $method,
            'success_url' => $options['success_url'] ?? ($config['success_url'] ?? null),
            'cancel_url' => $options['cancel_url'] ?? ($config['cancel_url'] ?? null),
            'description' => $options['description'] ?? null,
            'refund_window_days' => $options['refund_window_days'] ?? null,
            // 'inclusive' (default) charges $amountCents and backs the VAT
            // out of it; 'exclusive' reads it as net and charges
            // $amountCents + tax, so the returned object's amount_cents is
            // larger than the one passed in; it is always what was charged.
            'tax_behavior' => $options['tax_behavior'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ], static fn ($v): bool => $v !== null);

        return new Checkout($this->billkitClient()->oneShotPayments->create($payload));
    }

    /**
     * Refund a one-shot payment made by this model.
     *
     * Thin delegate to the BillKit refunds API; only valid while the charge is
     * still inside its ``refund_window_days``. Extra ``$options`` (e.g.
     * ``amount_cents`` for a partial refund, ``metadata``) are forwarded as-is.
     *
     * Note: this does not verify the one-shot belongs to this model. Like
     * Cashier's own ``refund($paymentId)``, it refunds the id you pass. The API
     * enforces tenant scoping; pass an id this tenant owns.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed> the created refund object
     */
    public function refundOneShot(string $oneShotPaymentId, array $options = []): array
    {
        return $this->billkitClient()->refunds->create(
            ['one_shot_payment_id' => $oneShotPaymentId] + $options,
        );
    }

    /**
     * Refund a payment (e.g. a subscription renewal) by its payment id.
     *
     * Cashier-shaped: ``$user->refund($paymentId)`` refunds the charge you
     * name. Omit ``amount_cents`` in ``$options`` for a full refund, or pass it
     * for a partial one. As with ``refundOneShot()``, the API enforces tenant
     * scoping, so pass a payment id this tenant owns.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed> the created refund object
     */
    public function refund(string $paymentId, array $options = []): array
    {
        return $this->billkitClient()->refunds->create(
            ['payment_id' => $paymentId] + $options,
        );
    }

    /** The shared BillKit API client. */
    public function billkitClient(): BillKitClient
    {
        return app(BillKitClient::class);
    }

    private function billkitIdOrFail(): string
    {
        $id = $this->billkitId();
        if ($id === null) {
            throw new \LogicException(
                'This model has no associated BillKit customer. Call createAsBillKitCustomer() first.',
            );
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function billkitConfig(): array
    {
        $config = config('billkit');

        return is_array($config) ? $config : [];
    }
}
