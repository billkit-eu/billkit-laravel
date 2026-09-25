# Changelog

All notable changes to the BillKit Laravel package will be documented in this
file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versioned independently of the other SDKs; requires `billkit-eu/billkit-php`.

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
  - EPS is **Austria-only** and carries a **EUR 1.00 minimum** — a hundred
    times iDEAL's. A fully-discounted first charge on a price offering it is
    raised to that floor.
  - **PayPal** mints a `paypal` mandate and renews on itself. No country
    restriction.
  - `bancontact` stays one-off only: Mollie's recurring guide and its
    Bancontact method page disagree about whether it mints a mandate, and that
    is being settled against a live profile rather than guessed.

- **`banktransfer` is accepted by `charge()`.** One-off only, like
  `bancontact`: it mints no mandate, so it cannot back a subscription.
  - No code changed — `$method` is forwarded as a plain string. The `@param`
    line listing the methods had fallen a release behind, which for a
    Cashier-shaped package is the documentation most callers actually read.
  - **It settles in days, not seconds.** The payer is handed bank details and
    pays on their own schedule, so Mollie holds the payment `open` for about a
    fortnight.
  - The `Checkout` this returns is **not a completed sale**. A pending bank
    transfer is neither a failure nor something to poll — let your
    `one_shot_payment.succeeded` / `.failed` listener decide.

- **`tax_behavior` is documented in `charge()`'s options list.** Already
  forwarded, and already explained in a comment beside the payload; it was
  just missing from the list callers read.

## [0.5.0] - 2026-09-22

### Fixed
- **`Subscription::syncFromApi()` can clear a field.** It read every value with
  `??`, which treats an explicit `null` as an absent key, so a field the API
  cleared kept its old value forever — a trial that converted sends
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
  with neither an email nor a name — a Team model, a placeholder user — sent
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

[Unreleased]: https://github.com/billkit-eu/billkit-laravel/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/billkit-eu/billkit-laravel/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/billkit-eu/billkit-laravel/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/billkit-eu/billkit-laravel/releases/tag/v0.1.0
