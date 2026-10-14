<?php

declare(strict_types=1);

namespace BillKit\Laravel;

use BillKit\BillKitClient;
use BillKit\Exception\BillKitException;
use BillKit\Model\Customer;
use BillKit\Model\OneShotPayment;
use BillKit\Model\Refund;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Route;

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
 * Unlike Cashier there are no subscription "types" (`'default'`, `'swimming'`):
 * nothing in BillKit records which slot a subscription fills. A customer may
 * hold several subscriptions; {@see self::subscription()} is the one serving
 * them. `subscription()`, `subscribed()`, `onTrial()` and `onGracePeriod()`
 * still accept Cashier's `$type` argument, only to refuse it: PHP would
 * otherwise drop an extra argument silently, and `subscribed('pro')` ported
 * from Cashier (or from 0.x, which filtered on a `type` column the
 * application set) would quietly answer for any plan.
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
     * ``email`` / ``name`` fall back to the model's own attributes;
     * ``country_code`` and ``metadata`` are passed through.
     *
     * @param array<string, mixed> $options
     */
    public function createAsBillKitCustomer(array $options = []): Customer
    {
        $payload = array_filter([
            'email' => $options['email'] ?? $this->getAttribute('email'),
            'name' => $options['name'] ?? $this->getAttribute('name'),
            'country_code' => $options['country_code'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ], static fn ($v): bool => $v !== null);

        $customer = $this->billkitClient()->customers->create($payload);
        $id = $customer['id'];
        if (! is_string($id) || $id === '') {
            // Every caller feeds this straight into a `customer_id` field.
            // Storing '' would send an empty id and surface as a puzzling
            // 400 one call later, with nothing pointing back to here.
            throw new BillKitException(
                'BillKit returned a customer without an id, so this model could not be linked to one.',
            );
        }
        $this->forceFill(['billkit_customer_id' => $id])->save();

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
        // createAsBillKitCustomer() refuses a customer without an id and
        // stores the one it got, so the stored id is the answer.
        $this->createAsBillKitCustomer($options);

        return $this->billkitIdOrFail();
    }

    /** Retrieve this model's BillKit customer object. */
    public function asBillKitCustomer(): Customer
    {
        return $this->billkitClient()->customers->retrieve($this->billkitIdOrFail());
    }

    /**
     * Every local subscription row for this model, newest first.
     *
     * @return MorphMany<Subscription, $this>
     */
    public function billkitSubscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'billable')->latest('created_at')->latest('id');
    }

    /**
     * The subscription serving this model: the newest one that is
     * {@see Subscription::valid()}, or failing that the newest of any state,
     * or null when there is none.
     *
     * Preferring a valid one matters when a customer has an abandoned or
     * ended subscription newer than the one they are paying for: the action
     * verbs on the result (`swap()`, `cancel()`) then act on the live one.
     *
     * @param null $type not supported: BillKit has no subscription types, and passing one throws
     *
     * @throws \InvalidArgumentException when a type is passed
     */
    public function subscription(?string $type = null): ?Subscription
    {
        self::refuseSubscriptionType('subscription', $type);
        $subscriptions = $this->billkitSubscriptions;

        return $subscriptions->first(static fn (Subscription $s): bool => $s->valid())
            ?? $subscriptions->first();
    }

    /**
     * Whether any of this model's subscriptions serves it right now.
     *
     * This follows the API's `serves_customer`, so `past_due` counts: the
     * customer keeps access while BillKit retries the payment. Cashier's
     * default revokes on `past_due`; check {@see Subscription::pastDue()} if
     * you want that.
     *
     * @param null $type not supported: BillKit has no subscription types, and passing one throws
     *
     * @throws \InvalidArgumentException when a type is passed
     */
    public function subscribed(?string $type = null): bool
    {
        self::refuseSubscriptionType('subscribed', $type);

        return $this->subscription()?->valid() ?? false;
    }

    /**
     * @param null $type not supported: BillKit has no subscription types, and passing one throws
     *
     * @throws \InvalidArgumentException when a type is passed
     */
    public function onTrial(?string $type = null): bool
    {
        self::refuseSubscriptionType('onTrial', $type);

        return $this->subscription()?->onTrial() ?? false;
    }

    /**
     * @param null $type not supported: BillKit has no subscription types, and passing one throws
     *
     * @throws \InvalidArgumentException when a type is passed
     */
    public function onGracePeriod(?string $type = null): bool
    {
        self::refuseSubscriptionType('onGracePeriod', $type);

        return $this->subscription()?->onGracePeriod() ?? false;
    }

    /**
     * Refuse Cashier's subscription `$type`. Ignoring it would answer for
     * whichever subscription serves the customer, so a gate written as
     * `subscribed('pro')` would let a customer on any plan through.
     */
    private static function refuseSubscriptionType(string $method, ?string $type): void
    {
        if ($type === null) {
            return;
        }
        throw new \InvalidArgumentException(sprintf(
            "BillKit: %s() takes no subscription type, and was given '%s'. BillKit has no subscription "
            . 'types: call %s() without an argument for the subscription serving the customer, and check '
            . "the plan yourself where it matters (\$user->subscription()?->price_id === 'price_...').",
            $method,
            $type,
            $method,
        ));
    }

    /**
     * Start a hosted checkout for a price and return a redirectable Checkout.
     *
     * Options: ``success_url``, ``cancel_url`` (else config defaults; each
     * may be a route name or an absolute URL), ``trial_days`` (replaces the
     * price's trial for this checkout), ``coupon_code``, ``payment_method``
     * (creditcard|directdebit|ideal|eps|applepay|paypal), ``ui_mode``,
     * ``country``, ``metadata``, ``idempotency_key``, plus
     * ``email``/``name``/``country_code`` used when creating the customer.
     *
     * ``metadata`` is attached to the checkout session, as in Cashier: it is
     * echoed on the session and on the ``checkout.session.completed`` event,
     * which is how you join the completed checkout back to your own order or
     * plan selection. It is never written to the customer, so a per-checkout
     * key cannot end up on every later checkout's customer record.
     *
     * ``country`` is the buyer's ISO-3166-1 alpha-2 code, and it is worth
     * passing whenever your app already knows it: it is what lets VAT apply
     * to the **first** charge, because on the hosted flow the buyer only
     * reaches a country-collecting page after the charge exists. It never
     * overwrites a country the customer already has.
     *
     * The method option is ``payment_method``, the API's own name for it. The
     * 0.x ``method`` is refused rather than ignored: dropping it would let the
     * buyer pick any method, which is a different checkout from the one asked for.
     *
     * @param array<string, mixed> $options
     *
     * @throws \InvalidArgumentException for `trial_days_override`, the API's name for `trial_days`,
     *                                   and for the 0.x `method` option, now `payment_method`
     */
    public function checkout(string $priceId, array $options = []): Checkout
    {
        if (array_key_exists('trial_days_override', $options)) {
            throw new \InvalidArgumentException(
                'checkout() takes the trial as trial_days. trial_days_override is the API field it is sent as.',
            );
        }
        if (array_key_exists('method', $options)) {
            throw new \InvalidArgumentException(
                'checkout() takes the payment method as payment_method, the API field. method was its 0.x name.',
            );
        }
        $config = $this->billkitConfig();

        $payload = array_filter([
            'customer_id' => $this->createOrGetBillKitCustomer(array_diff_key($options, ['metadata' => null])),
            'price_id' => $priceId,
            'success_url' => self::checkoutUrl($options['success_url'] ?? ($config['success_url'] ?? null)),
            'cancel_url' => self::checkoutUrl($options['cancel_url'] ?? ($config['cancel_url'] ?? null)),
            'trial_days_override' => $options['trial_days'] ?? null,
            'coupon_code' => $options['coupon_code'] ?? null,
            'payment_method' => $options['payment_method'] ?? null,
            'ui_mode' => $options['ui_mode'] ?? null,
            'country' => $options['country'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ], static fn ($v): bool => $v !== null);

        return new Checkout(
            $this->billkitClient()->checkoutSessions->create($payload, self::requestOptions($options)),
        );
    }

    /**
     * Take a single, mandate-less payment through the hosted checkout and
     * return a redirectable Checkout.
     *
     * Named after Cashier's `checkoutCharge()`, which is the same flow: the
     * buyer is sent to a hosted page and pays there. There is no BillKit
     * equivalent of Cashier's `charge()`, an off-session charge to a stored
     * card, because a one-shot stores no mandate: nothing is saved for reuse
     * and the charge cannot be replayed later.
     *
     * The payment reaches a terminal state via the ``one_shot_payment.succeeded``
     * / ``one_shot_payment.failed`` webhooks; your webhook listener decides what
     * happens on success/failure.
     *
     * ``refund_window_days`` controls how long the charge stays refundable:
     * ``0`` disables refunds entirely, the default is ``30`` and the maximum is
     * ``365``. Refund a settled one-shot via {@see self::refundOneShot()}.
     *
     * ``banktransfer`` is the one method here that settles in DAYS rather than
     * seconds: the payer is handed bank details and Mollie holds the payment
     * open for about a fortnight. The returned ``Checkout`` is not a completed
     * sale, and a pending bank transfer is neither a failure nor something to
     * poll: wait for the webhook.
     *
     * Options: ``success_url`` / ``cancel_url`` (else config defaults; a
     * route name or an absolute URL), ``description``, ``refund_window_days``, ``tax_behavior``, ``metadata``,
     * ``idempotency_key``. ``metadata`` is attached to the payment only, never
     * to the customer. ``email``/``name``/``country_code`` are used only when a
     * BillKit customer must be created for this model.
     *
     * @param int                  $amountCents amount to charge, in the currency's minor unit
     * @param string               $currency    ISO-4217 code, e.g. ``EUR``
     * @param string               $paymentMethod creditcard|ideal|bancontact|eps|applepay|paypal|banktransfer,
     *                                            sent as the API's ``payment_method``
     * @param array<string, mixed> $options
     */
    public function checkoutCharge(int $amountCents, string $currency, string $paymentMethod, array $options = []): Checkout
    {
        $payload = $this->oneShotPayload($amountCents, $currency, $options, ['payment_method' => $paymentMethod]);

        return new Checkout(
            $this->billkitClient()->oneShotPayments->create($payload, self::requestOptions($options)),
        );
    }

    /**
     * Start a one-off payment the buyer completes inside your own page, and
     * return the one-shot payment carrying its ``client_secret``.
     *
     * The embedded sibling of {@see self::checkoutCharge()}. There is no
     * ``$paymentMethod``: the buyer picks it in the BillKit payment element, which
     * you mount in the browser with ``client_secret``
     * (``mountOneShotPaymentElement`` in ``@billkit-eu/js``, or
     * ``<OneShotPaymentElement/>`` in ``@billkit-eu/react``). Hand the browser
     * the secret and nothing else.
     *
     * It returns the payment rather than a {@see Checkout}, because there is
     * nothing to redirect to: ``redirect_url`` is null, ``payment_method`` stays null
     * until the buyer confirms, and ``expires_at`` says when the secret and the
     * unconfirmed charge lapse. The secret is only on this response. Terminal
     * state arrives via the same webhooks as a hosted charge.
     *
     * Options are those of {@see self::checkoutCharge()}. ``success_url`` is
     * still needed: a card that asks for 3-D Secure or a bank redirect leaves
     * the page and comes back to it.
     *
     * @param int                  $amountCents amount to charge, in the currency's minor unit
     * @param string               $currency    ISO-4217 code, e.g. ``EUR``
     * @param array<string, mixed> $options
     */
    public function checkoutChargeEmbedded(int $amountCents, string $currency, array $options = []): OneShotPayment
    {
        $payload = $this->oneShotPayload($amountCents, $currency, $options, ['ui_mode' => 'embedded']);

        return $this->billkitClient()->oneShotPayments->create($payload, self::requestOptions($options));
    }

    /**
     * The ``POST /v1/checkout/one_shot`` body shared by the two charge methods,
     * which differ only in ``$mode``.
     *
     * @param array<string, mixed>  $options
     * @param array<string, string> $mode    either ``payment_method`` (hosted) or ``ui_mode`` (embedded)
     *
     * @return array<string, mixed>
     */
    private function oneShotPayload(int $amountCents, string $currency, array $options, array $mode): array
    {
        $config = $this->billkitConfig();

        return array_filter([
            // ``metadata`` is payment-scoped (PaymentIntent semantics), so it is
            // kept out of customer creation, otherwise the same bag would leak
            // onto a newly-created customer record.
            'customer_id' => $this->createOrGetBillKitCustomer(
                array_diff_key($options, ['metadata' => null]),
            ),
            'amount_cents' => $amountCents,
            'currency' => $currency,
            ...$mode,
            'success_url' => self::checkoutUrl($options['success_url'] ?? ($config['success_url'] ?? null)),
            'cancel_url' => self::checkoutUrl($options['cancel_url'] ?? ($config['cancel_url'] ?? null)),
            'description' => $options['description'] ?? null,
            'refund_window_days' => $options['refund_window_days'] ?? null,
            // 'inclusive' (default) charges $amountCents and backs the VAT
            // out of it; 'exclusive' reads it as net and charges
            // $amountCents + tax, so the returned object's amount_cents is
            // larger than the one passed in; it is always what was charged.
            'tax_behavior' => $options['tax_behavior'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * A return URL as the API needs it: a registered route name becomes its
     * absolute URL, anything else is sent unchanged.
     *
     * Cashier accepts both, and the config's ``success_url`` / ``cancel_url``
     * say so. A string that is not a route name (an absolute URL, or a typo)
     * is passed through, and the API refuses a value that is not a URL with a
     * 400 naming the field. Only parameterless routes can be named this way.
     */
    private static function checkoutUrl(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && Route::has($value)) {
            return route($value);
        }

        return $value;
    }

    /**
     * The caller's ``idempotency_key`` option, as the PHP client's request
     * options.
     *
     * The bodies above are built from a fixed list of keys, so without this a
     * key passed in ``$options`` would be dropped and the client would mint
     * its own. That protects a retry of one HTTP request but not a retry of
     * the caller's own code (a queued job that runs twice), which is exactly
     * what a caller-chosen key is for. It is never forwarded to the customer
     * create these methods may make first: that is a different request, and
     * reusing the key there would collide with this one.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, string>
     */
    private static function requestOptions(array $options): array
    {
        $key = $options['idempotency_key'] ?? null;

        return is_string($key) && $key !== '' ? ['idempotency_key' => $key] : [];
    }

    /**
     * Refund a one-shot payment made by this model.
     *
     * Only valid while the charge is still inside its ``refund_window_days``.
     * ``amount_cents`` makes it partial; ``idempotency_key`` is sent as the
     * request's key; anything else is forwarded to the API as-is.
     *
     * Like Cashier's own ``refund($paymentId)``, this does not check that the
     * payment belongs to this model. The API enforces that it belongs to your
     * account.
     *
     * @param array<string, mixed> $options
     */
    public function refundOneShot(string $oneShotPaymentId, array $options = []): Refund
    {
        return $this->billkitClient()->refunds->create(
            ['one_shot_payment_id' => $oneShotPaymentId] + array_diff_key($options, ['idempotency_key' => null]),
            self::requestOptions($options),
        );
    }

    /**
     * Refund a payment (e.g. a subscription renewal) by its payment id.
     *
     * Cashier-shaped: ``$user->refund($paymentId)`` refunds the charge you
     * name. Omit ``amount_cents`` for a full refund, or pass it for a partial
     * one. Refunds settle asynchronously: handle ``refund.succeeded`` and
     * ``refund.failed``.
     *
     * @param array<string, mixed> $options
     */
    public function refund(string $paymentId, array $options = []): Refund
    {
        return $this->billkitClient()->refunds->create(
            ['payment_id' => $paymentId] + array_diff_key($options, ['idempotency_key' => null]),
            self::requestOptions($options),
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
        $out = [];
        foreach (is_array($config) ? $config : [] as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
