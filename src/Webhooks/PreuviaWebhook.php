<?php

namespace Preuvia\Laravel\Webhooks;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Throwable;

/**
 * A verified Preuvia callback. Build it only from a request whose signature was checked
 * (VerifyPreuviaWebhook stores one under the PreuviaWebhook::class request attribute).
 *
 * @implements Arrayable<string, mixed>
 */
final class PreuviaWebhook implements Arrayable
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $eventName,
        private readonly array $payload,
        private readonly ?string $deliveryId = null,
        private readonly int $attempt = 1,
        private readonly bool $sandboxHeader = false,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $decoded = json_decode($request->getContent(), true);
        $payload = is_array($decoded) ? $decoded : [];

        // The body is signed, the headers are not: the payload wins.
        $event = $payload['event'] ?? null;
        $event = is_string($event) && $event !== '' ? $event : (string) $request->header('X-Preuvia-Event', '');

        $delivery = $request->header('X-Preuvia-Delivery') ?: $request->header('Idempotency-Key');
        $attempt = (string) $request->header('X-Preuvia-Attempt', '');

        return new self(
            eventName: $event,
            payload: $payload,
            deliveryId: is_string($delivery) && $delivery !== '' ? $delivery : null,
            attempt: ctype_digit($attempt) ? max(1, (int) $attempt) : 1,
            sandboxHeader: $request->header('X-Preuvia-Sandbox') === '1',
        );
    }

    /**
     * Null for an event this SDK version does not know yet: eventName() still has it.
     */
    public function event(): ?WebhookEvent
    {
        return WebhookEvent::tryFrom($this->eventName);
    }

    public function eventName(): string
    {
        return $this->eventName;
    }

    public function is(WebhookEvent|string $event): bool
    {
        return $this->eventName === ($event instanceof WebhookEvent ? $event->value : $event);
    }

    /**
     * Delivery UUID, stable across retries: deduplicate on it.
     */
    public function deliveryId(): ?string
    {
        return $this->deliveryId;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function isSandbox(): bool
    {
        return $this->sandboxHeader || $this->payload('sandbox') === true;
    }

    public function occurredAt(): ?CarbonImmutable
    {
        $value = $this->payload('occurred_at');

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whole payload, or one value in dot notation ("document.client_capture_id").
     */
    public function payload(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->payload : data_get($this->payload, $key, $default);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function client(): ?array
    {
        return $this->section('client');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dossier(): ?array
    {
        return $this->section('dossier');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function document(): ?array
    {
        return $this->section('document');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function certification(): ?array
    {
        return $this->section('certification');
    }

    /**
     * Only for the eIDAS certificate and signed PDF events.
     *
     * @return array<string, mixed>|null
     */
    public function artifact(): ?array
    {
        return $this->section('artifact');
    }

    public function dossierUuid(): ?string
    {
        return $this->string('dossier.uuid');
    }

    public function documentUuid(): ?string
    {
        return $this->string('document.uuid');
    }

    public function certificationUuid(): ?string
    {
        return $this->string('certification.uuid');
    }

    public function artifactUuid(): ?string
    {
        return $this->string('artifact.uuid');
    }

    /**
     * The client_capture_id your app sent with the upload, to find its own record.
     */
    public function clientCaptureId(): ?string
    {
        return $this->string('document.client_capture_id');
    }

    /**
     * Public error message of a failure event (artifact first, then certification).
     */
    public function errorMessage(): ?string
    {
        return $this->string('artifact.error_message') ?? $this->string('certification.error_message');
    }

    /**
     * @return array{event: string, delivery_id: string|null, attempt: int, sandbox: bool, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'event' => $this->eventName,
            'delivery_id' => $this->deliveryId,
            'attempt' => $this->attempt,
            'sandbox' => $this->isSandbox(),
            'payload' => $this->payload,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function section(string $key): ?array
    {
        $value = $this->payload[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    private function string(string $key): ?string
    {
        $value = data_get($this->payload, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
