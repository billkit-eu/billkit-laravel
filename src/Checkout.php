<?php

declare(strict_types=1);

namespace BillKit\Laravel;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;

/**
 * A thin, redirectable wrapper around a BillKit checkout session.
 *
 * Implements {@see Responsable} so a controller can simply
 * ``return $user->checkout($priceId)`` to send the buyer to the hosted
 * Mollie checkout page.
 */
final class Checkout implements Responsable
{
    /**
     * @param array<string, mixed> $session the raw checkout-session object
     */
    public function __construct(private readonly array $session)
    {
    }

    public function id(): string
    {
        return (string) ($this->session['id'] ?? '');
    }

    /**
     * The hosted-checkout URL (null for embedded ``ui_mode`` sessions).
     *
     * Checkout sessions expose it as ``url``; a one-shot payment
     * ({@see Billable::charge()}) exposes the same hosted redirect as
     * ``redirect_url``; both are surfaced here.
     */
    public function url(): ?string
    {
        $url = $this->session['url'] ?? $this->session['redirect_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** The one-time client secret for an embedded (js.billkit.eu) session. */
    public function clientSecret(): ?string
    {
        $secret = $this->session['client_secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function asArray(): array
    {
        return $this->session;
    }

    public function redirect(): RedirectResponse
    {
        $url = $this->url();
        if ($url === null) {
            throw new \LogicException(
                'This checkout session has no redirect URL. Embedded (ui_mode: "embedded") '
                . 'sessions are driven by the js.billkit.eu element via the client secret, not a redirect.',
            );
        }

        return new RedirectResponse($url);
    }

    public function toResponse($request): RedirectResponse
    {
        return $this->redirect();
    }
}
