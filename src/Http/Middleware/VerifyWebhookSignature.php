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
 */
final class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('billkit.webhook.secret');
        if (! is_string($secret) || $secret === '') {
            throw new AccessDeniedHttpException('BillKit webhook secret is not configured (billkit.webhook.secret).');
        }

        try {
            Webhooks::verifySignature(
                payload: $request->getContent(),
                signatureHeader: $request->header('BillKit-Signature'),
                secret: $secret,
                toleranceSeconds: (int) config('billkit.webhook.tolerance', 300),
            );
        } catch (WebhookVerificationException $exception) {
            throw new AccessDeniedHttpException($exception->getMessage(), $exception);
        }

        return $next($request);
    }
}
