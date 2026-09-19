# BillKit for Laravel

First-class Laravel integration for [BillKit](https://billkit.eu), a
Cashier-shaped billing layer on top of the `billkit-eu/billkit-php` PHP SDK.

- A `Billable` trait for your `User`/`Team` model
- Hosted checkout (`$user->checkout(...)`) and per-subscription billing portal
- A webhook-synced `Subscription` Eloquent model + entitlement helpers
- Signature-verified webhook controller wired out of the box

BillKit provisions subscriptions from the inbound Mollie webhook (there is no
synchronous "create subscription" call), so the flow is **checkout → redirect →
your webhook creates the local `Subscription`**, exactly the Cashier model.

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
    'trial_days'  => 14,        // optional per-checkout trial override
    'coupon_code' => 'LAUNCH',  // optional
]);
```

The buyer is redirected to the hosted Mollie checkout. When they finish, BillKit
sends `subscription.created`; the package's webhook controller creates the local
`Subscription` and links it to the billable by customer id.

Catalog work has no Cashier-shaped equivalent, so reach for the underlying PHP
client through the `billkit()` helper. Retiring a price, for example, is an
archive rather than a delete: the price keeps its id and stays readable, because
subscriptions renew against it by id and keep renewing at it.

```php
billkit()->prices->update('price_pro_monthly', ['active' => false]);
```

## One-shot payments

For a single, mandate-less charge (Cashier's `charge()`, a one-time purchase
with nothing stored for future reuse), use `charge()`. Like `checkout()` it is
redirect-based and returns the same `Checkout` responsable:

```php
// In a controller. Checkout is Responsable, so return it directly:
return $request->user()->charge(1999, 'EUR', 'creditcard', [
    'success_url'        => route('thanks'),
    'cancel_url'         => route('cart'),
    'description'        => 'One premium widget',
    'refund_window_days' => 30,   // 0 disables refunds; default 30; max 365
]);
```

The buyer completes the payment on the hosted Mollie page. The charge reaches a
terminal state via the `one_shot_payment.succeeded` / `one_shot_payment.failed`
webhooks, so react to them like any other event.

Refund a settled one-shot (within its `refund_window_days`):

```php
$user->refundOneShot('osp_123');                       // full refund
$user->refundOneShot('osp_123', ['amount_cents' => 500]); // partial
```

## Gate access

```php
if ($user->subscribed()) {
    // active, trialing, past_due, or within a cancel grace period
}

$user->onTrial();
$user->onGracePeriod();
```

## Manage a subscription

```php
$sub = $user->subscription();      // BillKit\Laravel\Subscription

$sub->swap('price_pro_yearly');    // change plan (proration handled server-side)
$sub->previewSwap('price_pro_yearly'); // preview the proration first
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

## Metered usage

For a subscription on a metered price, report what was consumed and BillKit
invoices the period's total at each close.

```php
$sub = $user->subscription();

// Report consumption. Pass an identifier derived from the job, not uniqid().
$sub->reportUsage(quantity: 1200, identifier: $job->uuid());

// Reconcile: what has been reported but not yet billed.
$sub->usageRecords('pending');

// And what it comes to.
$summary = $sub->usageSummary();
$summary['pending_quantity'];     // 1200
$summary['gross_cents'];          // 2400
$summary['will_charge'];          // true
$summary['minimum_charge_cents']; // 100
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
`BILLKIT_WEBHOOK_SECRET`. React to any event with the dispatched Laravel events:

```php
use BillKit\Laravel\Events\WebhookReceived;

Event::listen(function (WebhookReceived $event) {
    if ($event->payload['type'] === 'invoice.paid') {
        // ...
    }
});
```

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

**Never logged:** your API key or the `Authorization` header; request and response **bodies** (they carry customer PII); the **query string** (list filters carry values like `email=`). Failed calls throw a typed `BillKitException` rather than being logged, so you never get a duplicate entry. Catch it and log it your own way.

## Development

```bash
composer install
composer test      # PHPUnit via orchestra/testbench
composer analyse   # PHPStan + larastan
composer cs:check  # php-cs-fixer (@PSR12)
```

## License

Apache-2.0
