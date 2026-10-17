<?php

namespace Preuvia\Laravel\Webhooks\Testing;

use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Preuvia\Laravel\Webhooks\WebhookEvent;
use Preuvia\Laravel\Webhooks\WebhookSignature;

/**
 * For your feature tests: sends a callback exactly as Preuvia does (raw JSON body, signed headers).
 *
 * @mixin MakesHttpRequests
 */
trait SignsPreuviaWebhooks
{
    /**
     * An empty $payload is replaced by PreuviaWebhookPayload::make($event). $headers override
     * the generated ones (e.g. a wrong X-Preuvia-Signature). $secret defaults to the configured one.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function postPreuviaWebhook(string $uri, WebhookEvent|string $event, array $payload = [], array $headers = [], ?string $secret = null): TestResponse
    {
        $name = $event instanceof WebhookEvent ? $event->value : $event;
        $payload = $payload === [] ? PreuviaWebhookPayload::make($name) : $payload + ['event' => $name];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $headers = array_merge(
            $this->preuviaWebhookHeaders($body, $name, $secret, ($payload['sandbox'] ?? false) === true),
            $headers,
        );

        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /**
     * @return array<string, string>
     */
    protected function preuviaWebhookHeaders(string $body, string $event, ?string $secret = null, bool $sandbox = false, int $attempt = 1): array
    {
        $secret ??= (string) config('preuvia.webhooks.secret', '');
        $delivery = (string) Str::uuid();

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Idempotency-Key' => $delivery,
            'X-Preuvia-Delivery' => $delivery,
            'X-Preuvia-Attempt' => (string) $attempt,
            'X-Preuvia-Event' => $event,
            // Placeholder key so a test can still hit an unconfigured receiver and assert its 503.
            'X-Preuvia-Signature' => WebhookSignature::sign($body, $secret !== '' ? $secret : 'unconfigured'),
        ];

        if ($sandbox) {
            $headers['X-Preuvia-Sandbox'] = '1';
        }

        return $headers;
    }
}
