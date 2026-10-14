# BillKit for Laravel

First-class Laravel integration for [BillKit](https://billkit.eu), a
Cashier-shaped billing layer on top of the `billkit-eu/billkit-php` PHP SDK.

- A `Billable` trait for your `User`/`Team` model
- Hosted checkout (`$user->checkout(...)`) and per-subscription billing portal
- A webhook-synced `Subscription` Eloquent model + entitlement helpers
- Signature-verified webhook controller wired out of the box

BillKit provisions subscriptions from the inbound Mollie webhook (there is no
synchronous "create subscription" call), so the flow is **checkout, redirect,
then your webhook creates the local `Subscription`**, exactly the Cashier model.

Requires PHP 8.2+ with the `curl` extension, and Laravel 12 or 13 (Laravel 13
itself needs PHP 8.3).
Upgrading from 0.x? [CHANGELOG.md](CHANGELOG.md) has the "Upgrading from 0.x"
list, and the PHP SDK it builds on changed too.

## Install

```bash
composer require billkit-eu/billkit-laravel
php artisan migrate      # creates billkit_subscriptions + adds billkit_customer_id
```

Publish the config if you want to tweak it:

```bash
php artisan vendor:publish --tag=billkit-config
```

Set your credentials in `.env`:

```dotenv
BILLKIT_API_KEY=bk_test_...
BILLKIT_WEBHOOK_SECRET=bkwhsec_...
```

## Make a model billable

```php
use BillKit\Laravel\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

## Start a subscription (checkout)

```php
// In a controller. Checkout is Responsable, so you can return it directly:
return $request->user()->checkout('price_pro_monthly', [
    'success_url' => route('billing.done'),
    'cancel_url'  => route('pricing'),
    'trial_days'  => 14,        // optional per-checkout trial override (the only trial key)
    'coupon_code' => 'LAUNCH',  // optional
    'country'     => 'NL',      // optional, but see below
    'metadata'    => ['order_id' => $order->id], // optional, echoed on the session
    // 'payment_method' => 'ideal', // optional: pin the method; omit to let the buyer pick
]);
```

`success_url` and `cancel_url` may also be route names (`'billing.done'`),
here or as the `BILLKIT_SUCCESS_URL` / `BILLKIT_CANCEL_URL` defaults; a name
that matches a registered route is resolved with `route()`. `metadata` goes on
the checkout session, as in Cashier, and comes back on the session and on the
`checkout.session.completed` event, so a completed checkout can be joined back
to your own order. It is not copied onto the customer.

Option names follow the API's own field names, so the method is
`payment_method` (`creditcard`, `directdebit`, `ideal`, `eps`, `applepay`,
`paypal`). The 0.x `method` option throws `\InvalidArgumentException` rather
than being dropped, because a dropped method is a checkout that lets the buyer
pick any. `trial_days` is the one option with its own name (it is sent as
`trial_days_override`).

The buyer is redirected to the hosted Mollie checkout. When they finish, BillKit
sends `subscription.created`; the package's webhook controller creates the local
`Subscription` and links it to the billable by customer id.

Pass `country` whenever your app already knows where the buyer is. It is what
lets VAT apply to the **first** charge: on the hosted flow the buyer only
reaches a country-collecting page after the charge exists. It is stored on the
customer when they have no country yet, and never overwrites one they do.

Catalog work has no Cashier-shaped equivalent, so reach for the underlying PHP
client through the `billkit()` helper. Retiring a price, for example, is an
archive rather than a delete: the price keeps its id and stays readable, because
subscriptions renew against it by id and keep renewing at it.

```php
billkit()->prices->update('price_pro_monthly', ['active' => false]);
```

## One-shot payments

For a single, mandate-less payment use `checkoutCharge()`, Cashier's name for
the same hosted flow. There is no `charge()`: Cashier's `charge()` bills a
stored card off-session, and a BillKit one-shot stores nothing to bill later.
Like `checkout()` it returns a redirectable `Checkout`. The third argument is
the payment method, sent as the API's `payment_method`:

```php
return $request->user()->checkoutCharge(1999, 'EUR', 'creditcard', [
    'success_url'        => route('thanks'),
    'cancel_url'         => route('cart'),
    'description'        => 'One premium widget',
    'refund_window_days' => 30,   // 0 disables refunds; default 30; max 365
]);
```

The charge reaches a terminal state via the `one_shot_payment.succeeded` /
`one_shot_payment.failed` webhooks, so react to them like any other event.

To take the payment inside your own page, use `checkoutChargeEmbedded()`. It
takes no payment method (the buyer picks it in the BillKit payment element) and returns
the `BillKit\Model\OneShotPayment` with a short-lived `client_secret`, the only
thing you hand to the browser for `@billkit-eu/js` or `@billkit-eu/react`:

```php
$payment = $request->user()->checkoutChargeEmbedded(1999, 'EUR', [
    'success_url' => route('thanks'), // where 3-D Secure and bank redirects return
]);

return view('pay', ['clientSecret' => $payment->client_secret]);
```

Refunds, within the charge's `refund_window_days`:

```php
$user->refundOneShot('osp_123');                            // full
$user->refundOneShot('osp_123', ['amount_cents' => 500]);   // partial
$user->refund('pay_123', ['amount_cents' => 500]);          // a subscription payment
```

Every Billable method that takes `$options` accepts `idempotency_key`, which is
sent as the request's `Idempotency-Key`. Derive it from your own order or job
id so a retried job cannot charge or refund twice.

## Gate access

```php
if ($user->subscribed()) {
    // the API's `serves_customer`: entitled, and for a canceling or paused
    // subscription only until the end of the period already paid for
}

$user->onTrial();
$user->onGracePeriod();
```

There are **no subscription types**. Cashier's `subscription('default')` and
`subscribed('swimming')` name a slot that BillKit never records, and 0.x only
filtered on a `type` column your own code had to set. In 1.0 the methods take
no type, and passing one throws `\InvalidArgumentException` instead of being
silently ignored, which would let a customer on any plan through a gate written
for one. To gate on a plan, check `$user->subscription()?->price_id`.
`$user->subscription()` is the subscription serving the customer: the newest
`valid()` one, or the newest of any state when none is valid.

**`past_due` counts as subscribed.** `valid()` follows the API's
`serves_customer`, which keeps a customer served while BillKit retries a failed
renewal. Cashier's default revokes on `past_due`; if you want that, check
`! $user->subscription()?->pastDue()` as well.

The `Subscription` helpers keep Cashier's meanings. `onGracePeriod()` is a
cancellation scheduled for the period end with that end still ahead
(`renewal_state: canceling`). `canceled()` is true on the grace period and
once the API has ended the subscription (`status: canceled`); `ended()` is the
second half alone. A `status: canceled` subscription is never on a grace
period, so `subscribed() || onGracePeriod()` grants exactly what `valid()`
does.

## Manage a subscription

```php
$sub = $user->subscription();      // BillKit\Laravel\Subscription

$sub->previewSwap('price_pro_yearly'); // quote the proration first (a PlanChangePreview)
$sub->swap('price_pro_yearly', ['idempotency_key' => "swap-{$order->id}"]); // change plan
$sub->cancel();                    // cancel at period end
$sub->resume();                    // un-pause
$sub->reactivate();                // undo a scheduled cancellation
$sub->pause();

// Pausing stops the renewal, not the current period. `status` stays
// `active` (the customer paid for the period they are in) and the pause
// shows up in `renewal_state`, which is what `paused()` reads. A paused
// subscription is therefore still `valid()`.
$sub->paused();

// Mandate re-auth + hosted billing portal both return a redirect URL:
return redirect($sub->updatePaymentMethod(route('billing')));
return $sub->redirectToBillingPortal(route('billing'));
```

`cancel()`, `pause()`, `resume()`, `reactivate()`, `swap()` and `previewSwap()`
take the PHP client's request options as their last argument
(`['idempotency_key' => ...]`). The verbs re-sync the row from the response and
dispatch `SubscriptionSynced` once the write is committed: inside your own
`DB::transaction()` that is when your transaction commits, and never if it
rolls back (the event implements `ShouldDispatchAfterCommit`). After `swap()` the row usually still has the old
`price_id`: the switch lands when the proration charge settles, and the
`subscription.updated` webhook moves it.

## Discounts and the payment method

The `Subscription` row mirrors the subscription's coupon and the payment method
its next renewal charges, kept current by the webhook like every other column.

```php
$sub = $user->subscription();

$sub->hasDiscount();        // a coupon is bound and its window has not closed
$sub->couponId();           // 'co_...' or null
$sub->discountEndsAt();     // Carbon, or null for a forever coupon

$sub->hasPaymentMethod();   // false only while an import awaits activation
$sub->paymentMethodType();  // 'creditcard', 'directdebit' (SEPA) or 'paypal'
$sub->hasCard();
$sub->cardBrand();          // null for a non-card mandate
$sub->cardLastFour();       // Cashier's pm_last_four
$sub->card_exp_month;       // int|null
$sub->card_exp_year;        // int|null
```

Two differences from Cashier are deliberate:

- **It is on the subscription, not the billable.** A BillKit mandate belongs to
  a subscription, so one customer can renew two subscriptions on two cards.
- **`paymentMethodType()` is the rail, never a card brand.** Cashier's
  `pm_type` holds the brand for a card; here the brand is `cardBrand()`. An
  iDEAL or EPS checkout reads `directdebit`, because that is the mandate it
  mints.

`discountEndsAt()` is also null for a `once` or `repeating` coupon until its
first discounted charge, since the window is measured from that charge. To send
the customer somewhere to change their card, use `updatePaymentMethod()` above.

## Metered usage

For a subscription on a metered price, report what was consumed and BillKit
invoices the period's total at each close.

```php
$sub = $user->subscription();

// Report consumption. Pass an identifier derived from the job, not uniqid().
$sub->reportUsage(quantity: 1200, identifier: $job->uuid());

// Reconcile: what has been reported but not yet billed (one page; the
// result's autoPagingIterator() walks them all).
$sub->usageRecords(['invoice_id' => 'pending']);

// And what it comes to.
$summary = $sub->usageSummary();
$summary->pending_quantity;     // 1200
$summary->gross_cents;          // 2400
$summary->will_charge;          // true
$summary->minimum_charge_cents; // 100
```

**`$identifier` is the dedupe an idempotency key cannot do.** The key the SDK
sends covers a retry of that HTTP request. `$identifier` covers a retry of *your*
call, which in a Laravel app is usually a queued job replaying or a webhook you
handle twice: those reach the API as a genuinely new request with a new key, so
only a natural key stops the second report becoming a second charge. `$job->uuid()`
or the domain event's primary key both work; a value that changes per attempt
does not.

```php
class ReportApiCalls implements ShouldQueue
{
    public function handle(): void
    {
        $this->user->subscription()->reportUsage(
            quantity: $this->calls,
            identifier: $this->job->uuid(),   // survives a retry
        );
    }
}
```

**Read `will_charge` before showing a customer an amount.** A period whose total
is under `minimum_charge_cents` (EUR 1.00) is not charged, because the payment
provider would refuse it. The usage is not lost: it stays pending and rolls into
the next period, which is then billed for both. A dashboard that renders
`gross_cents` as "your next invoice" is wrong exactly when the number is small.

None of these three touches the local `Subscription` row, unlike `swap()` or
`cancel()`. A usage record is not a subscription state change.

## Webhooks

The package registers `POST /billkit/webhook` (signature-verified) and keeps
`Subscription` rows in sync. Point a BillKit webhook endpoint at that URL and set
`BILLKIT_WEBHOOK_SECRET`. Three Laravel events are the extension points:

| Event | When | Carries |
|---|---|---|
| `WebhookReceived` | every verified delivery, before any sync | `event` (typed), `payload` (array) |
| `SubscriptionSynced` | a local row was written and committed, from a webhook or an action verb | `subscription`, `event` (null for an action verb) |
| `WebhookHandled` | after the package is done with the delivery | `event`, `payload` |

```php
use BillKit\Laravel\Events\WebhookReceived;
use BillKit\Model\InvoicePaidEvent;

Event::listen(function (WebhookReceived $event) {
    if ($event->event instanceof InvoicePaidEvent) {
        $invoice = $event->event->data;   // a BillKit\Model\Invoice
    }
});
```

`$event->event` is the delivery decoded into its typed class (one per event
type the SDK knows, each with a typed `data`), or a plain `BillKit\Model\Event`
for a type it does not know. `$event->payload` is the same delivery as an array,
as in Cashier. `previous_attributes` sits beside `data`, holds the old value of
each field that changed, and is always present: `null` when the event makes no
claim about what changed.

```php
$previous = $event->event->previous_attributes;
if ($previous !== null && array_key_exists('price_id', $previous)) {
    // plan changed from $previous['price_id'] to $event->event->data['price_id']
}
```

Let anything you do not recognise fall through: new event types are added
without a package release.

**Deliveries are not ordered.** Each is retried on its own schedule, so an old
`subscription.updated` can arrive after the `subscription.canceled` that
replaced it. The package handles this for the `Subscription` row: it stores
the `created` of the newest event it applied (`last_event_created`) and skips
anything older, and a skipped event dispatches no `SubscriptionSynced`. An
event with the same `created` is applied, since BillKit sends a transition and
its paired `subscription.updated` with one timestamp. `WebhookReceived`
listeners see every delivery, stale ones included, so apply the same check to
anything you store from them, and deduplicate on the event `id`.

`created` is whole epoch seconds, so it is a coarse clock: two changes inside
the same second are applied in arrival order, and the next event about that
subscription puts the row right. If your application cannot tolerate that
window, re-fetch on arrival
(`$sub->syncFromApi(billkit()->subscriptions->retrieve($sub->billkit_id))`).

`Subscription` and `WebhookController` are `final`. To add a relation to the
model, use Eloquent's `Subscription::resolveRelationUsing()`; to own the route,
set `billkit.path` to `null` and register your own, keeping the
`VerifyWebhookSignature` middleware in front of it (the controller refuses a
request the middleware did not verify).

## UUID and ULID billable keys

`billkit_subscriptions.billable_id` is created by the package migration with
Laravel's `nullableMorphs()`, which follows the schema builder's default morph
key type. If your billable model uses UUID or ULID keys, set that default
before the migration runs, for example in `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Schema;

Schema::morphUsingUuids();   // or Schema::morphUsingUlids()
```

An app that already ran the migration with integer keys has to change the
column itself.

## Logging

Off by default: installing the package does not switch on log output. Name a channel from `config/logging.php` to opt in:

```dotenv
BILLKIT_LOG_CHANNEL=stack
```

or in `config/billkit.php`:

```php
'log_channel' => env('BILLKIT_LOG_CHANNEL'),
```

The SDK then logs one `debug` record per HTTP attempt and per response (`method`, `url`, `attempt`, `status`, `duration_ms`, `request_id`) and one `warning` per retry. The channel's own level still applies, so a channel at `info` shows the retry warnings and drops the debug lines.

A dedicated channel keeps it out of your main log:

```php
// config/logging.php
'billkit' => [
    'driver' => 'daily',
    'path' => storage_path('logs/billkit.log'),
    'level' => 'debug',
    'days' => 7,
],
```

**Never logged:** your API key or the `Authorization` header; request and response **bodies** (they carry customer PII); the **query string** (list filters carry values like `email=`). Failed calls throw rather than being logged, so you never get a duplicate entry: a `BillKit\Exception\BillKitException` subclass, which is `ApiException` for an HTTP error and `ApiConnectionException` (or its `ApiTimeoutException`) when the API could not be reached. Catch `BillKitException` and log it your own way.

## Differences from Cashier, on purpose

- No subscription types; `subscription()` is the one serving the customer.
- `past_due` is entitled, following the API's `serves_customer`.
- `checkoutCharge()` instead of `charge()`: there is no off-session charge.
- Subscriptions are created by checkout and the webhook, never directly.
- The payment method lives on the subscription, not the billable.
- `Subscription` and the webhook controller are final; extend through events.
- Responses from the PHP client are typed objects (`$customer->id`); array
  access (`$customer['id']`) works too.

## Development

```bash
composer install
composer test      # PHPUnit via orchestra/testbench
composer analyse   # PHPStan + larastan (level max)
composer cs:check  # php-cs-fixer (@PSR12)
```

## License

Apache-2.0
