# Changelog

All notable changes to the BillKit Laravel package will be documented in this
file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versioned independently of the other SDKs; requires `billkit-eu/billkit-php`.

## [Unreleased]

## [1.0.0] - 2026-10-14

The first stable release, and a deliberate break from 0.x. It requires
`billkit-eu/billkit-php` ^1.0, whose own 1.0 is a break too (typed responses,
request options, renamed exceptions): read its CHANGELOG alongside this one.
Composer's `<1` / `^0.9` constraints do not admit 1.0, so nothing upgrades by
accident.

### The 1.0 API contract

The API froze its `/v1` contract for 1.0; through `billkit-eu/billkit-php`
1.0 it reaches this package unchanged. Request validation is a `400`, new
error codes (`parameter_unknown`, `route_not_found`, `method_not_allowed`,
`state_conflict`, `mode_mismatch`) arrive on `ApiException::$errorCode`, a
checkout session's `object` is `checkout.session`, `reportUsage()` is
answered with `200`, and `expand` needs the expanded resource's read scope
(otherwise a `403 insufficient_scope`). The API's re-cut 1.0 renames
reach the models too: `payment_method` on checkout sessions and one-off
payments (was `method`), `reversed_at` on disputes, `test_clock_id` on
customers, a fractional `percent_off`, and a `402` provider refusal is
`payment_error` / `payment_refused` (a `PaymentException`). A query key the
php client does not know is forwarded to the API, so a filter added in a
later API version works without upgrading.

### Hardening

- The webhook middleware answers `403` for a whitespace-only
  `billkit.webhook.secret`, as it already did for an empty one; such a
  secret is an unset variable, and an HMAC keyed with it is one anyone can
  compute.

### Upgrading from 0.x

**0. The checkout method option is `payment_method`.** `checkout()` sends it as
the API's `payment_method` field, and the 0.x `method` option now throws
`\InvalidArgumentException` instead of being sent under a name the API
refuses. `checkoutCharge()`'s third parameter is renamed `$paymentMethod`, which
matters only to a call that passed it by name.

```php
// 0.x
$user->checkout('price_pro', ['method' => 'ideal']);
// 1.0
$user->checkout('price_pro', ['payment_method' => 'ideal']);
```

**1. Requirements.** Laravel 12 or 13 (11 is dropped), PHP 8.2+ (Laravel 13
needs 8.3). Then run `php artisan migrate`: 1.0 adds a migration,
`2026_10_13_000040_add_sync_watermark_and_serves_customer_to_billkit_subscriptions_table`,
with two nullable columns on `billkit_subscriptions` (`last_event_created` and
`serves_customer`), and writes both unconditionally. If you publish the
package's migrations, run `php artisan vendor:publish --tag=billkit-migrations`
first. Existing rows fill both on their next `subscription.*` webhook or
action verb; until then `valid()` answers from `status` and `renewal_state` by
the API's own rule. The earlier migrations are unchanged.

**2. `charge()` is `checkoutCharge()`, `chargeEmbedded()` is
`checkoutChargeEmbedded()`.** Same arguments. Cashier's `charge()` bills a
stored card off-session, which a BillKit one-shot cannot do; `checkoutCharge()`
is Cashier's name for the hosted flow this actually is.

```php
// 0.x
return $user->charge(1999, 'EUR', 'ideal', $options);
$payment = $user->chargeEmbedded(1999, 'EUR', $options);
$secret = $payment['client_secret'];

// 1.0
return $user->checkoutCharge(1999, 'EUR', 'ideal', $options);
$payment = $user->checkoutChargeEmbedded(1999, 'EUR', $options);
$secret = $payment->client_secret;   // $payment['client_secret'] still works
```

**3. No subscription types, and passing one throws.** `subscription()`,
`subscribed()`, `onTrial()` and `onGracePeriod()` take no type. 0.x filtered
on the row's `type` column, which the webhook set to `'default'` and your own
code could change; nothing in BillKit records a type. **Passing one now throws
`\InvalidArgumentException`** rather than being ignored: PHP drops an extra
argument silently, so an ignored `subscribed('pro')` would answer for any
plan and open a gate meant for one. Gate on a plan with
`$user->subscription()?->price_id`. `subscription()` now returns the newest
*valid* subscription, falling back to the newest of any state, so it is the
one serving the customer even when an abandoned checkout left a newer row.

```php
// 0.x
$user->subscribed('default');
$user->subscribed('pro');
// 1.0
$user->subscribed();
$user->subscription()?->price_id === 'price_pro';
```

**4. Positional options became arrays.**

```php
// 0.x
$sub->usageRecords('pending', 25);
// 1.0
$sub->usageRecords(['invoice_id' => 'pending', 'limit' => 25]);
```

`usageRecords()` returns a `BillKit\Collection` (`->data`, `->has_more`,
`->autoPagingIterator()`); array reads of the page still work.

**5. One trial key.** `checkout()` takes `trial_days` only; passing
`trial_days_override` (the API field it is sent as) throws
`\InvalidArgumentException` instead of being quietly preferred or ignored.

**6. Typed returns.** `createAsBillKitCustomer()` and `asBillKitCustomer()`
return `BillKit\Model\Customer`; `refund()` / `refundOneShot()` a `Refund`;
`previewSwap()` a `PlanChangePreview`; `reportUsage()` a `UsageRecord`;
`usageSummary()` a `UsageSummary`. Array access keeps working; use
`->toArray()` where a function wants a real array. `Checkout::asArray()` still
returns an array, and `Checkout::session()` the object.

**7. `Subscription` and `WebhookController` are `final`.** Extend through
events instead:

| 0.x | 1.0 |
|---|---|
| subclass `WebhookController`, override `syncSubscription()` | listen for `SubscriptionSynced` (or `WebhookReceived`) |
| subclass `Subscription` to add a relation | `Subscription::resolveRelationUsing('team', fn ($s) => ...)` |
| the controller decoded the body itself | it reads the event the `VerifyWebhookSignature` middleware verified, and refuses a request without one |

**8. Webhook events carry the typed event.** `WebhookReceived` and
`WebhookHandled` gained `->event` (a `BillKit\Model\Event`, typed per event
type); `->payload` is still the array. Both constructors now take
`(array $payload, Event $event)`. `previous_attributes` is always present and
`null` when nothing changed, rather than absent.

**8a. `catch (ApiException)` now catches 4xx errors too.** This comes from
`billkit-eu/billkit-php` 1.0, but it changes what a Laravel exception handler
or `report()` callback catches: `ApiException` is now the parent of every
HTTP error class, not of `ServerException` alone. Catch `ServerException`
where you meant "BillKit had a server error".

**9. Action verbs take request options.** `cancel()`, `pause()`, `resume()`,
`reactivate()`, `swap()`, `previewSwap()` and `reportUsage()` take the PHP
client's request options last: `$sub->swap('price_2', ['idempotency_key' => 'k'])`.

### Added
- `checkout()` sends `$options['metadata']` to the checkout session, as
  Cashier does: it is echoed on the session and on
  `checkout.session.completed`, so a completed checkout can be joined back to
  your own order. It is no longer written to a newly created customer.
- `success_url` and `cancel_url`, in the config or in `$options`, may be a
  route name; `checkout()`, `checkoutCharge()` and `checkoutChargeEmbedded()`
  resolve one with `route()`. Anything else is sent unchanged.
- `Events\SubscriptionSynced`, dispatched after a local row is written from a
  webhook (with the event) or an action verb (with `null`), once committed. It
  implements `ShouldDispatchAfterCommit`, so an action verb inside your own
  `DB::transaction()` dispatches it when that transaction commits and never
  if it rolls back.
- Laravel 13 support; CI covers PHP 8.2 to 8.5 against Laravel 12 and 13, with
  `--prefer-lowest` legs and a coverage leg.
- The README's webhook section documents the envelope (`previous_attributes`
  sits beside `data` and is `null` when there is no claim), forward-tolerant
  matching on `type`, and the ordering rule and its limit for listeners that
  store state of their own.
- Documented UUID and ULID billable keys (`Schema::morphUsingUuids()` before
  migrating) and the deliberate differences from Cashier.
- `.gitattributes`, so the Packagist dist ships only the package, its licence
  and its docs.
- A refused charge or refund, and any money-moving call on a suspended
  account, throws the php client's new `PaymentException` (402; `errorCode`
  `payment_refused` or `tenant_suspended`) from `refund()`,
  `refundOneShot()` and every other verb, instead of an
  `InvalidRequestException`. Catch it where a refund is offered.

### Fixed
- `'metadata' => []` passed to `createAsBillKitCustomer()`, `checkout()` or
  `checkoutCharge()` reaches the API as `{}`. It went out as the JSON list
  `[]` and the API refused the call with a 400. The conversion is the php
  client's (`billkit-eu/billkit-php` 1.0.0), so it covers every route.
- **A stale webhook no longer rolls a subscription back.** Deliveries are
  unordered, so a retried `subscription.updated` from hours earlier could land
  after `subscription.canceled`, set the row back to `active`, and leave
  `subscribed()` true for good. The controller records the `created` of the
  newest event it applied in `last_event_created` and skips anything older.
  An event with the same `created` is applied (a transition and its paired
  `subscription.updated` share one). The check and the write run in one
  transaction with the row locked. Action verbs do not move the watermark.
- **`valid()` and `subscribed()` read the API's `serves_customer`**, mirrored
  onto the row, rather than `status`. Reading `status` served a canceling or
  paused customer past the end of the period they had paid for. A
  non-renewing row is also held to `current_period_end` locally, because the
  API's answer turns false at that moment without an event.
  **Behaviour change:** a `canceled` subscription is never `valid()`, even
  with `cancel_at_period_end` and a future period end.
- **`onGracePeriod()` is false once the API reports `status: canceled`.** An
  immediate cancel that overtakes a scheduled one leaves
  `cancel_at_period_end` set beside a future period end, so the Cashier-style
  `subscribed() || onGracePeriod()` still granted access that `valid()`
  refused. **Behaviour change:** `canceled()` keeps Cashier's meaning and is
  also true on the grace period; `ended()` is the terminal case alone.
- **Synced timestamps no longer shift by the app timezone.** Under Carbon 3,
  `createFromTimestamp()` builds a UTC instance, and Eloquent stores the
  wall-clock string and reads it back in `app.timezone`, so an app on
  `Europe/Amsterdam` saw every period end, trial end and cancellation one or
  two hours early, so `valid()`, `onGracePeriod()` and `onTrial()` turned
  false that long before the boundary (and west of UTC stayed true that long
  after it). Epochs are now converted in the app timezone.
- `checkoutCharge()`, `checkoutChargeEmbedded()` and `checkout()` forward
  `$options['idempotency_key']` as the `Idempotency-Key` header. They built
  the request body from a fixed key list, so the option was dropped and the
  client minted its own key, which does not protect a retry of your own code.
- `updatePaymentMethod()`, `billingPortalUrl()` and `Checkout::id()` throw
  `BillKitException` when the response lacks the URL or id, rather than
  returning an empty string to redirect to.
- `Subscription::updatePaymentMethod()` returned `''` for every call: it read
  `url` and `redirect_url`, and the API's reauthorisation response carries
  `checkout_url`. The test staged `url` and pinned the bug. It now returns the
  `checkout_url`, so `redirect($sub->updatePaymentMethod(...))` sends the
  customer somewhere.

### Changed
- `composer.json` requires `ext-curl`. `billkit-eu/billkit-php` 1.0 moved it
  to `suggest` because a host that injects a PSR-18 client does not need it,
  but this package's service provider always builds the client on the
  bundled curl transport, so without the extension every resolve of
  `BillKitClient` threw. Composer now refuses the install instead.
- `valid()` and `subscribed()` are documented as counting `past_due` as
  entitled, following the API's `serves_customer`; Cashier's default does not.
- PHPStan (larastan) runs at level max.

## [0.9.0] - 2026-10-09

### Added
- `Billable::chargeEmbedded(int $amountCents, string $currency, array $options = []): array`, the embedded sibling of `charge()`. It creates a one-shot with `ui_mode: embedded` and no method (the buyer picks it in the BillKit payment element) and returns the one-shot array with its `client_secret` for `@billkit-eu/js` / `@billkit-eu/react`, rather than a redirect `Checkout`, because there is nothing to redirect to. Options, customer creation, webhooks and `refundOneShot()` work as for `charge()`, which is unchanged. **Requires the matching API release.**

## [0.8.0] - 2026-09-25

### Upgrading
- **Run `php artisan migrate`.** This release adds a migration,
  `2026_09_25_000030_add_discount_and_payment_method_to_billkit_subscriptions_table`,
  which adds seven nullable columns to `billkit_subscriptions`. The package
  loads its migrations itself, so `migrate` alone picks it up. If you publish
  the package's migrations instead, run
  `php artisan vendor:publish --tag=billkit-migrations` first to copy the new
  file (files you already published are left alone), then `migrate`.
- Existing rows start with the new columns empty. They fill on the next
  `subscription.*` webhook for that subscription, or on the next action verb
  (`cancel()`, `swap()` and so on), since both re-sync the row.

### Added
- **The `Subscription` row mirrors the subscription's `discount`.** Columns
  `coupon_id` and `discount_ends_at` (a datetime), and `hasDiscount()`,
  `couponId()` and `discountEndsAt()`. `discountEndsAt()` is null for a
  `forever` coupon, and for a `once` or `repeating` coupon until its first
  discounted charge fixes the window. `hasDiscount()` also answers false once a
  stored end date has passed, so it is right before the
  `subscription.coupon_expired` webhook lands as well as after.
- **The `Subscription` row mirrors the subscription's `payment_method`.**
  Columns `payment_method_type` (`creditcard`, `directdebit` or `paypal`),
  `card_brand`, `card_last_four`, `card_exp_month` and `card_exp_year`, and
  `hasPaymentMethod()`, `paymentMethodType()`, `hasCard()`, `cardBrand()` and
  `cardLastFour()`.
  - It lives on the subscription, not on the billable as Cashier's `pm_type` /
    `pm_last_four` do, because a BillKit mandate belongs to a subscription: one
    customer can renew two subscriptions on two different cards.
  - `payment_method_type` is the mandate's rail, never a card brand (Cashier's
    `pm_type` holds the brand for a card). An iDEAL or EPS checkout reads
    `directdebit`, because that is what it mints.
  - `hasPaymentMethod()` is false only while an imported subscription awaits
    activation.
- The webhook controller writes both on every `subscription.*` event, which
  covers `subscription.coupon_applied`, `subscription.coupon_expired`,
  `subscription.payment_method_updated` and the `subscription.updated` each is
  paired with. A `null` clears the columns: an expired coupon empties both
  discount columns, and a switch from a card to SEPA clears every card field
  rather than leaving the old card's digits next to `directdebit`. A payload
  that does not mention either field leaves them as they are.

## [0.7.0] - 2026-09-23

### Added
- **`checkout()` takes a `country` option** and forwards it to the checkout
  session. It is what lets VAT apply to the **first** charge: on the hosted
  flow the buyer only reaches a country-collecting page after the charge
  exists. Stored on the customer when they have no country yet, and never
  overwrites one they do.
- `AGENTS.md` and the README now name the PHP SDK surface a Laravel app reaches
  through `billkit()` without a wrapper here: `apiKeys`,
  `invoices->sendEmail()`, `payments->retrieveProvider()`,
  `tenant->billingProfile()` / `setBillingProfile()` / `export()`,
  `webhookEndpoints->listEventTypes()`, and `expand` on the list and retrieve
  routes.

### Changed
- **Requires `billkit-eu/billkit-php` `>=0.7.0 <1`**, up from `>=0.4.0`. The
  lower bound is raised by hand because `check_sibling_range()` cannot see that
  a range is too *loose*: an install could otherwise satisfy the old constraint
  and still lack what the docs above point at.

## [0.6.0] - 2026-09-23

### Added
- **`eps` and `paypal` can now start a subscription**, not just take a one-off
  charge. Pass either as `checkout()`'s `method` option.
  - **EPS** mints a *SEPA* mandate, exactly as iDEAL does, so the subscription
    renews on `directdebit`.
  - EPS is **Austria-only** and carries a **EUR 1.00 minimum**, a hundred
    times iDEAL's. A fully-discounted first charge on a price offering it is
    raised to that floor.
  - **PayPal** mints a `paypal` mandate and renews on itself. No country
    restriction.
  - `bancontact` stays one-off only: Mollie's recurring guide and its
    Bancontact method page disagree about whether it mints a mandate, and that
    is being settled against a live profile rather than guessed.

- **`banktransfer` is accepted by `charge()`.** One-off only, like
  `bancontact`: it mints no mandate, so it cannot back a subscription.
  - No code changed, `$method` is forwarded as a plain string. The `@param`
    line listing the methods had fallen a release behind, which for a
    Cashier-shaped package is the documentation most callers actually read.
  - **It settles in days, not seconds.** The payer is handed bank details and
    pays on their own schedule, so Mollie holds the payment `open` for about a
    fortnight.
  - The `Checkout` this returns is **not a completed sale**. A pending bank
    transfer is neither a failure nor something to poll, let your
    `one_shot_payment.succeeded` / `.failed` listener decide.

- **`tax_behavior` is documented in `charge()`'s options list.** Already
  forwarded, and already explained in a comment beside the payload; it was
  just missing from the list callers read.

## [0.5.0] - 2026-09-22

### Fixed
- **`Subscription::syncFromApi()` can clear a field.** It read every value with
  `??`, which treats an explicit `null` as an absent key, so a field the API
  cleared kept its old value forever, a trial that converted sends
  `trial_end: null` and left the stale trial date on the row. Absent keys still
  preserve the current value; `null` is now written as `null`.
- **`createOrGetBillKitCustomer()` throws instead of returning `''`.** A create
  response without an `id` used to yield an empty string, which every caller
  fed straight into a `customer_id` field and saw as a puzzling `400` one call
  later, with nothing pointing back at the cause.

### Removed
- The `currency` config key (`BILLKIT_CURRENCY`). Nothing read it: `charge()`
  takes the ISO-4217 code as a required argument, because a one-shot in the
  wrong currency is charged rather than rejected.

## [0.4.0]

### Changed
- **Requires `billkit-eu/billkit-php` 0.4.0 or later** (was 0.3.0 or later).
  0.3.x sent an empty request body as `[]` instead of `{}`, which the API
  rejects with a 422. That reached this package through
  `createAsBillKitCustomer()`: the payload drops null values, so a Billable
  with neither an email nor a name, a Team model, a placeholder user, sent
  an empty body, and creating a customer with no attributes is otherwise a
  perfectly valid call.

  The floor is raised rather than left at 0.3.0 so that updating this package
  actually moves you onto the fixed client. Composer will not update a
  transitive dependency on its own.

### Added
- Nothing here, but the PHP client this wraps gains `$client->creditNotes` and
  `$client->invoices->void($id)`. Both are reachable through `billkit()`.

## [0.3.0]

### Added
- **Metered usage on `Subscription`**, in Cashier's shape:
  - `reportUsage(int $quantity = 1, ?string $identifier = null, ?int $occurredAt = null, array $metadata = [])`
  - `usageRecords(?string $invoiceId = null, ?int $limit = null)`
  - `usageSummary()`

  `$identifier` is the dedupe an idempotency key cannot do. The key covers a
  retry of one HTTP request; `$identifier` covers a retry of *your* call, which
  in a Laravel app usually means a queued job replaying or a webhook handled
  twice. Those reach the API as a genuinely new request with a new key, so pass
  something derived from the job (`$job->uuid()`, the domain event's primary
  key) rather than a value that changes per attempt.

  `usageSummary()` is the money view of pending usage, and **`will_charge` is
  the field to read before showing a customer an amount**: a period under
  `minimum_charge_cents` (EUR 1.00) is not charged at all, because the payment
  provider would refuse it, and the usage rolls into the next period instead. A
  dashboard that renders `gross_cents` as "your next invoice" is wrong exactly
  when the number is small.

  None of the three re-syncs the local row, unlike `cancel()` / `swap()` /
  `pause()`. A usage record is not a subscription state change, and syncing a
  model from a response that describes a usage record would corrupt it. There
  is a test pinning that.

### Changed
- Requires `billkit-eu/billkit-php` `>=0.3.0`, for the metered surface above.
  The old range (`>=0.2.1 <1`) would have resolved either version, which means
  an install could have satisfied the constraint and still lacked
  `retrieveUsageSummary`.

## [0.2.1]

### Changed
- Documentation only. API keys are now `bk_live_…` / `bk_test_…` and webhook
  signing secrets `bkwhsec_…`; every example here used the previous
  Stripe-shaped `sk_`/`whsec_` spelling. No code in this package changed: it
  never parsed the prefix, it forwards the key as a bearer token.

## [0.2.0]

### Fixed
- `Subscription::paused()` read `status === 'paused'`, a value no BillKit
  subscription ever carries, so it answered "no" for every paused subscription.
  It now reads `renewal_state === 'paused'`, which is where a pause actually
  lands: pausing stops the renewal and leaves `status` at `active`, because the
  customer has paid for the period they are in. A paused subscription is
  therefore still `valid()`, and the model test now asserts that wire shape
  instead of an impossible row.

### Changed
- The README covers the paused shape, and points at
  `billkit()->prices->update($id, ['active' => false])` for retiring a price.
  Catalog work has no Cashier-shaped equivalent, so it goes through the
  underlying PHP client; archiving keeps the price readable and leaves existing
  subscriptions renewing on it.

## [0.1.0]

First public release.

### Added
- `Billable` trait: `checkout()` (hosted redirect), `subscribed()`, `onTrial()`,
  `onGracePeriod()`, `createAsBillKitCustomer()`, and customer helpers.
- Webhook-synced `Subscription` Eloquent model with state helpers (`valid`,
  `active`, `onTrial`, `onGracePeriod`, `canceled`, `paused`, `pastDue`) and
  actions (`cancel`, `pause`, `resume`, `reactivate`, `swap`, `previewSwap`,
  `updatePaymentMethod`, `billingPortalUrl` / `redirectToBillingPortal`).
- `Checkout` (Responsable) redirect wrapper.
- `WebhookController` + `VerifyWebhookSignature` middleware (reuses the SDK's
  `BillKit\Webhooks::verifySignature`), auto-registered at `POST /billkit/webhook`.
- `WebhookReceived` / `WebhookHandled` events.
- Publishable config + migrations (`billkit_subscriptions`, `billkit_customer_id`).
- Laravel 11 & 12, PHP 8.2+.
- **`billkit.log_channel` config key** (`BILLKIT_LOG_CHANNEL`). Name a channel
  from `config/logging.php` and the service provider resolves it to a PSR-3
  logger for the SDK; leave it unset (the default) and the SDK writes
  nowhere. Installing the package does not switch on log output.

  The SDK logs one `debug` record per HTTP attempt and per response, and one
  `warning` per retry. API keys, request/response bodies and query strings are
  never logged.

  A bad channel name can't take the container down: it degrades to Laravel's
  emergency logger rather than turning a logging typo into a 500 on every
  request that touches billing.

[Unreleased]: https://github.com/billkit-eu/billkit-laravel/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.9.0...v1.0.0
[0.9.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/billkit-eu/billkit-laravel/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/billkit-eu/billkit-laravel/releases/tag/v0.1.0
