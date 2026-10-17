<?php

namespace Preuvia\Laravel\Webhooks\Http;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Preuvia\Laravel\Webhooks\Exceptions\InvalidSignatureException;
use Preuvia\Laravel\Webhooks\PreuviaWebhook;
use Preuvia\Laravel\Webhooks\WebhookSignature;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects callbacks whose signature does not match, then exposes the verified
 * PreuviaWebhook as $request->attributes->get(PreuviaWebhook::class).
 */
class VerifyPreuviaWebhook
{
    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) $this->config->get('preuvia.webhooks.secret', '');

        // 503 rather than 401: a misconfiguration on our side, Preuvia keeps retrying until it is fixed.
        if ($secret === '') {
            $this->logger->error('Preuvia webhook rejected: the webhook secret is not configured.', [
                'delivery_id' => $this->deliveryId($request),
                'event' => $request->header('X-Preuvia-Event'),
            ]);

            return new JsonResponse(['message' => 'Webhook not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            WebhookSignature::verify(
                $request->getContent(),
                $request->header(WebhookSignature::HEADER),
                $secret,
                (int) $this->config->get('preuvia.webhooks.tolerance', WebhookSignature::DEFAULT_TOLERANCE),
            );
        } catch (InvalidSignatureException $exception) {
            $this->logger->warning('Preuvia webhook rejected: invalid signature.', [
                'reason' => $exception->reason(),
                'delivery_id' => $this->deliveryId($request),
                'event' => $request->header('X-Preuvia-Event'),
                'content_length' => strlen($request->getContent()),
                'ip' => $request->ip(),
            ]);

            return new JsonResponse(['message' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set(PreuviaWebhook::class, PreuviaWebhook::fromRequest($request));

        return $next($request);
    }

    private function deliveryId(Request $request): ?string
    {
        return $request->header('X-Preuvia-Delivery') ?: $request->header('Idempotency-Key');
    }
}
