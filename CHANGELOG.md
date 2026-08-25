# Changelog

All notable changes to the BillKit Laravel package will be documented in this
file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versioned independently of the other SDKs; requires `billkit-eu/billkit-php`.

## [Unreleased]

### Added
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

[Unreleased]: https://github.com/billkit-eu/billstack/compare/sdk-laravel-v0.1.0...HEAD
[0.1.0]: https://github.com/billkit-eu/billstack/releases/tag/sdk-laravel-v0.1.0
