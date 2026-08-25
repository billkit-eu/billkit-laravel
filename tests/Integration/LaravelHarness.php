<?php

declare(strict_types=1);

namespace BillKit\Laravel\Tests\Integration;

/**
 * Live-API harness for the Laravel package's integration suite.
 *
 * Mirrors `sdk/php/tests/Integration/IntegrationHarness.php`: raw curl
 * against the env-gated `_test` surfaces, deliberately not going through the
 * SDK so setup still works when the thing under test is broken.
 *
 * Kept separate from the php SDK's copy rather than shared: the Laravel
 * package depends on `billkit-eu/billkit-php` as a *published* package (path repo
 * with symlink), and reaching into its `tests/` (which composer does not
 * autoload for a dependency) would only work by accident of the symlink.
 */
final class LaravelHarness
{
    public static function baseUrl(): string
    {
        $value = getenv('BILLKIT_INTEGRATION_BASE_URL');

        return is_string($value) ? $value : '';
    }

    public static function enabled(): bool
    {
        return self::baseUrl() !== '';
    }

    /**
     * Provision a brand-new tenant (fresh email => fresh tenant).
     *
     * @return array{api_key: string, tenant_id: string, mollie_route_id: string}
     */
    public static function provisionTenant(): array
    {
        [$status, $body] = self::postJson('/v1/console/auth/_test/login', [
            'email' => 'laravel-it-' . bin2hex(random_bytes(12)) . '@sdk-it.example.com',
            'mode' => 'test',
            'tenant_name' => 'Laravel SDK IT',
        ]);
        if ($status === 404) {
            throw new \RuntimeException(
                'test-login backdoor returned 404; boot the API with '
                . 'BILLKIT_E2E_TEST_LOGIN=1 (see sdk/integration/boot-api.sh).',
            );
        }
        if ($status !== 200) {
            throw new \RuntimeException("test-login failed: {$status} {$body}");
        }

        /** @var array{api_key: string, mollie_route_id: string, operator: array{tenant_id: string}} $d */
        $d = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return [
            'api_key' => $d['api_key'],
            'tenant_id' => $d['operator']['tenant_id'],
            'mollie_route_id' => $d['mollie_route_id'],
        ];
    }

    /**
     * Create a product + price with the tenant's key and return the price id.
     *
     * Products and prices are catalogue setup, not part of the Cashier-shaped
     * surface the package exposes, so the harness does them directly.
     */
    public static function createPrice(string $apiKey, int $amountCents = 2500): string
    {
        $auth = ['Authorization: Bearer ' . $apiKey];
        [, $productBody] = self::postJson('/v1/products', ['name' => 'Laravel Plan'], $auth);
        /** @var array{id: string} $product */
        $product = json_decode($productBody, true, 512, JSON_THROW_ON_ERROR);

        [$status, $priceBody] = self::postJson('/v1/prices', [
            'product_id' => $product['id'],
            'amount_cents' => $amountCents,
            'currency' => 'EUR',
            'interval' => 'month',
        ], $auth);
        if ($status !== 200) {
            throw new \RuntimeException("price create failed: {$status} {$priceBody}");
        }
        /** @var array{id: string} $price */
        $price = json_decode($priceBody, true, 512, JSON_THROW_ON_ERROR);

        return $price['id'];
    }

    /** Recover the provider payment id from a checkout session's Mollie URL. */
    public static function paymentIdFromCheckoutUrl(string $url): string
    {
        $parts = explode('/', $url);
        $candidate = (string) end($parts);
        if (! str_starts_with($candidate, 'tr_')) {
            throw new \RuntimeException("Expected a Mollie payment id in checkout URL, got: {$url}");
        }

        return $candidate;
    }

    /** Flip a fake payment to a terminal status at the in-process fake Mollie. */
    public static function settle(string $paymentId, string $status = 'paid'): void
    {
        [$code, $body] = self::postJson('/v1/console/auth/_test/mollie/settle', [
            'payment_id' => $paymentId,
            'status' => $status,
        ]);
        if ($code !== 200) {
            throw new \RuntimeException("mollie settle failed: {$code} {$body}");
        }
    }

    /**
     * Post the provider webhook the way Mollie does, form-encoded `id=tr_...`.
     *
     * The API re-fetches payment state from the provider and ignores the body,
     * so `settle()` must run first; this call only nudges the saga.
     */
    public static function deliverMollieWebhook(string $routeId, string $providerPaymentId): void
    {
        $ch = curl_init(self::baseUrl() . '/internal/webhooks/mollie/' . $routeId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => 'id=' . rawurlencode($providerPaymentId),
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code !== 200) {
            throw new \RuntimeException("mollie webhook delivery failed: {$code} {$body}");
        }
    }

    /**
     * GET a path with the tenant's key and return the decoded body.
     *
     * @return array<string, mixed>
     */
    public static function get(string $apiKey, string $path): array
    {
        $ch = curl_init(self::baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = (string) curl_exec($ch);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $headers
     *
     * @return array{0: int, 1: string}
     */
    private static function postJson(string $path, array $payload, array $headers = []): array
    {
        $ch = curl_init(self::baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [$code, $body];
    }
}
