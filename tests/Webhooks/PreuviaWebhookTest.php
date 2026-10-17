<?php

namespace Preuvia\Laravel\Tests\Webhooks;

use Illuminate\Http\Request;
use Preuvia\Laravel\Tests\TestCase;
use Preuvia\Laravel\Webhooks\PreuviaWebhook;
use Preuvia\Laravel\Webhooks\Testing\PreuviaWebhookPayload;
use Preuvia\Laravel\Webhooks\WebhookEvent;

class PreuviaWebhookTest extends TestCase
{
    public function test_reads_a_certification_callback(): void
    {
        $payload = PreuviaWebhookPayload::certificationSucceeded([
            'occurred_at' => '2026-10-17T08:30:00.000000Z',
            'dossier' => ['uuid' => 'dossier-uuid'],
            'document' => ['uuid' => 'document-uuid', 'client_capture_id' => 'photo-42'],
            'certification' => ['uuid' => 'certification-uuid'],
        ]);

        $webhook = PreuviaWebhook::fromRequest($this->request($payload, [
            'X-Preuvia-Delivery' => 'delivery-uuid',
            'Idempotency-Key' => 'delivery-uuid',
            'X-Preuvia-Attempt' => '3',
            'X-Preuvia-Event' => 'certification.success',
        ]));

        $this->assertSame(WebhookEvent::CertificationSucceeded, $webhook->event());
        $this->assertSame('certification.success', $webhook->eventName());
        $this->assertTrue($webhook->is(WebhookEvent::CertificationSucceeded));
        $this->assertTrue($webhook->is('certification.success'));
        $this->assertSame('delivery-uuid', $webhook->deliveryId());
        $this->assertSame(3, $webhook->attempt());
        $this->assertFalse($webhook->isSandbox());
        $this->assertSame('2026-10-17T08:30:00+00:00', $webhook->occurredAt()?->toIso8601String());
        $this->assertSame('dossier-uuid', $webhook->dossierUuid());
        $this->assertSame('document-uuid', $webhook->documentUuid());
        $this->assertSame('certification-uuid', $webhook->certificationUuid());
        $this->assertSame('photo-42', $webhook->clientCaptureId());
        $this->assertSame('acme', $webhook->client()['slug']);
        $this->assertSame('dossier-uuid', $webhook->dossier()['uuid']);
        $this->assertSame('valid', $webhook->document()['status']);
        $this->assertSame('success', $webhook->certification()['status']);
        $this->assertNull($webhook->artifact());
        $this->assertNull($webhook->artifactUuid());
        $this->assertNull($webhook->errorMessage());
        $this->assertSame('photo-42', $webhook->payload('document.client_capture_id'));
        $this->assertSame('fallback', $webhook->payload('document.missing', 'fallback'));
        $this->assertSame($payload, $webhook->payload());
        $this->assertSame([
            'event' => 'certification.success',
            'delivery_id' => 'delivery-uuid',
            'attempt' => 3,
            'sandbox' => false,
            'payload' => $payload,
        ], $webhook->toArray());
    }

    public function test_reads_an_artifact_failure(): void
    {
        $webhook = PreuviaWebhook::fromRequest($this->request(PreuviaWebhookPayload::pdfSignatureFailed([
            'artifact' => ['uuid' => 'artifact-uuid', 'error_message' => 'Signing service unavailable.'],
        ])));

        $this->assertSame(WebhookEvent::PdfSignatureFailed, $webhook->event());
        $this->assertSame('artifact-uuid', $webhook->artifactUuid());
        $this->assertSame('pades_pdf', $webhook->artifact()['type']);
        $this->assertSame('Signing service unavailable.', $webhook->errorMessage());
    }

    public function test_keeps_unknown_events(): void
    {
        $webhook = PreuviaWebhook::fromRequest($this->request(['event' => 'dossier.completed']));

        $this->assertNull($webhook->event());
        $this->assertSame('dossier.completed', $webhook->eventName());
    }

    public function test_the_signed_payload_event_wins_over_the_header(): void
    {
        $webhook = PreuviaWebhook::fromRequest($this->request(['event' => 'certification.failed'], ['X-Preuvia-Event' => 'certification.success']));

        $this->assertSame(WebhookEvent::CertificationFailed, $webhook->event());
    }

    public function test_falls_back_to_the_event_header(): void
    {
        $webhook = PreuviaWebhook::fromRequest($this->request(['sandbox' => false], ['X-Preuvia-Event' => 'certification.pdf_signed']));

        $this->assertSame(WebhookEvent::PdfSigned, $webhook->event());
    }

    public function test_delivery_id_falls_back_to_the_idempotency_key(): void
    {
        $webhook = PreuviaWebhook::fromRequest($this->request([], ['Idempotency-Key' => 'key-uuid']));

        $this->assertSame('key-uuid', $webhook->deliveryId());
    }

    public function test_sandbox_comes_from_the_header_or_the_payload(): void
    {
        $this->assertTrue(PreuviaWebhook::fromRequest($this->request(['sandbox' => false], ['X-Preuvia-Sandbox' => '1']))->isSandbox());
        $this->assertTrue(PreuviaWebhook::fromRequest($this->request(['sandbox' => true]))->isSandbox());
        $this->assertFalse(PreuviaWebhook::fromRequest($this->request(['sandbox' => false]))->isSandbox());
    }

    public function test_tolerates_missing_or_unusable_values(): void
    {
        $webhook = PreuviaWebhook::fromRequest(Request::create('/hook', 'POST', [], [], [], [], 'not json'));

        $this->assertNull($webhook->event());
        $this->assertSame('', $webhook->eventName());
        $this->assertNull($webhook->deliveryId());
        $this->assertSame(1, $webhook->attempt());
        $this->assertNull($webhook->occurredAt());
        $this->assertNull($webhook->dossier());
        $this->assertNull($webhook->dossierUuid());
        $this->assertSame([], $webhook->payload());

        $webhook = PreuviaWebhook::fromRequest($this->request(['occurred_at' => 'not a date', 'dossier' => null], ['X-Preuvia-Attempt' => 'abc']));

        $this->assertNull($webhook->occurredAt());
        $this->assertNull($webhook->dossier());
        $this->assertSame(1, $webhook->attempt());
    }

    public function test_survives_serialization_for_queued_listeners(): void
    {
        $webhook = PreuviaWebhook::fromRequest($this->request(PreuviaWebhookPayload::certificationSucceeded(), ['X-Preuvia-Delivery' => 'delivery-uuid']));

        $this->assertEquals($webhook->toArray(), unserialize(serialize($webhook))->toArray());
    }

    private function request(array $payload, array $headers = []): Request
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/hook', 'POST', [], [], [], $server, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}
