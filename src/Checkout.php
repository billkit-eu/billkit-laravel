<?php

declare(strict_types=1);

namespace BillKit\Laravel;

use BillKit\Exception\BillKitException;
use BillKit\Model\CheckoutSession;
use BillKit\Model\OneShotPayment;
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
     * @internal built by {@see Billable::checkout()} and {@see Billable::checkoutCharge()}
     */
    public function __construct(private readonly CheckoutSession|OneShotPayment $session)
    {
    }

    /** The checkout session, or the one-shot payment for {@see Billable::checkoutCharge()}. */
    public function session(): CheckoutSession|OneShotPayment
    {
        return $this->session;
    }

    /**
     * @throws BillKitException when the API's response carried no id
     */
    public function id(): string
    {
        $id = $this->session->id;
        if ($id === null || $id === '') {
            throw new BillKitException('BillKit returned a checkout without an id.');
        }

        return $id;
    }

    /**
     * The hosted-checkout URL (null for embedded ``ui_mode`` sessions).
     *
     * Checkout sessions expose it as ``url``; a one-shot payment
     * ({@see Billable::checkoutCharge()}) exposes the same hosted redirect as
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
     * The response as a plain array.
     *
     * @return array<string, mixed>
     */
    public function asArray(): array
    {
        return $this->session->toArray();
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
