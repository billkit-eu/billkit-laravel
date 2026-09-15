# Changelog

All notable changes to the BillKit Laravel package will be documented in this
file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versioned independently of the other SDKs; requires `billkit-eu/billkit-php`.

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
