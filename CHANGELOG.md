# Changelog

All notable changes to the BillKit Laravel package will be documented in this
file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versioned independently of the other SDKs; requires `billkit-eu/billkit-php`.

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

[Unreleased]: https://github.com/billkit-eu/billkit-laravel/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/billkit-eu/billkit-laravel/releases/tag/v0.1.0
