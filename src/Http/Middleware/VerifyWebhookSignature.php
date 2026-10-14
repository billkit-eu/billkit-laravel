<?php

declare(strict_types=1);

namespace BillKit\Laravel\Http\Middleware;

use BillKit\Exception\WebhookVerificationException;
use BillKit\Webhooks;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Verify the ``BillKit-Signature`` header before the webhook controller runs,
 * reusing the SDK's {@see Webhooks::verifySignature()} (constant-time,
 * replay-protected, multi-``v1`` for secret rotation).
 *
 * The verified, typed event is left on the request as the
 * {@see self::EVENT_ATTRIBUTE} attribute, which is what the webhook
 * controller reads: a request that did not come through here has none, and
 * the controller refuses it.
 */
final class VerifyWebhookSignature
{
    public const EVENT_ATTRIBUTE = 'billkit.event';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('billkit.webhook.secret');
        if (! is_string($secret) || trim($secret) === '') {
            throw new AccessDeniedHttpException('BillKit webhook secret is not configured (billkit.webhook.secret).');
        }

        $tolerance = config('billkit.webhook.tolerance', 300);

        try {
            $event = Webhooks::verifySignature(
                payload: $request->getContent(),
                signatureHeader: $request->header('BillKit-Signature'),
                secret: $secret,
                toleranceSeconds: is_numeric($tolerance) ? (int) $tolerance : 300,
            );
        } catch (WebhookVerificationException $exception) {
            throw new AccessDeniedHttpException($exception->getMessage(), $exception);
        }

        $request->attributes->set(self::EVENT_ATTRIBUTE, $event);

        $response = $next($request);
        if (! $response instanceof Response) {
            throw new \UnexpectedValueException('The BillKit webhook route did not return a response.');
        }

        return $response;
    }
}
