<?php

namespace Preuvia\Laravel\Webhooks\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Preuvia\Laravel\Webhooks\Events\WebhookReceived;
use Preuvia\Laravel\Webhooks\Exceptions\RetryWebhookLater;
use Preuvia\Laravel\Webhooks\PreuviaWebhook;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Built-in receiver: dispatches WebhookReceived then the event matching the callback.
 * Any 2xx acknowledges the delivery; a listener exception becomes a 500 and Preuvia retries.
 */
class WebhookController
{
    // Method injection: the router caches controller instances, constructor deps could go stale.
    public function __invoke(Request $request, Dispatcher $events, LoggerInterface $logger, Container $container): Response
    {
        if (! $request->attributes->get(PreuviaWebhook::class) instanceof PreuviaWebhook) {
            // Routed without the middleware: verify here instead of trusting an unsigned body.
            return $container->make(VerifyPreuviaWebhook::class)->handle(
                $request,
                fn (Request $request): Response => $this->handle($request->attributes->get(PreuviaWebhook::class), $events, $logger),
            );
        }

        return $this->handle($request->attributes->get(PreuviaWebhook::class), $events, $logger);
    }

    private function handle(PreuviaWebhook $webhook, Dispatcher $events, LoggerInterface $logger): Response
    {
        try {
            $events->dispatch(new WebhookReceived($webhook));

            if ($event = $webhook->event()) {
                $class = $event->eventClass();
                $events->dispatch(new $class($webhook));
            }
        } catch (RetryWebhookLater $exception) {
            $logger->info('Preuvia webhook postponed by a listener.', [
                'delivery_id' => $webhook->deliveryId(),
                'event' => $webhook->eventName(),
                'attempt' => $webhook->attempt(),
                'retry_after' => $exception->retryAfter(),
                'message' => $exception->getMessage(),
            ]);

            return new JsonResponse(
                ['message' => $exception->getMessage(), 'retry_after' => $exception->retryAfter()],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => (string) $exception->retryAfter()],
            );
        }

        return new JsonResponse(['received' => true]);
    }
}
