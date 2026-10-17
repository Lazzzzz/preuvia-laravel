<?php

namespace Preuvia\Laravel\Tests\Webhooks;

use PHPUnit\Framework\TestCase;
use Preuvia\Laravel\Webhooks\Events\PreuviaWebhookEvent;
use Preuvia\Laravel\Webhooks\WebhookEvent;

class WebhookEventTest extends TestCase
{
    public function test_values_match_the_events_preuvia_sends(): void
    {
        $this->assertSame([
            'certification.success',
            'certification.failed',
            'certification.insufficient_tokens',
            'certification.eidas_certificate.generated',
            'certification.eidas_certificate.failed',
            'certification.pdf_signed',
            'certification.pdf_signature_failed',
        ], array_column(WebhookEvent::cases(), 'value'));
    }

    public function test_helpers(): void
    {
        $this->assertTrue(WebhookEvent::CertificationSucceeded->isCertification());
        $this->assertTrue(WebhookEvent::CertificationInsufficientTokens->isCertification());
        $this->assertFalse(WebhookEvent::PdfSigned->isCertification());

        $this->assertTrue(WebhookEvent::EidasCertificateFailed->hasArtifact());
        $this->assertFalse(WebhookEvent::CertificationFailed->hasArtifact());

        $this->assertSame(
            ['certification.failed', 'certification.insufficient_tokens', 'certification.eidas_certificate.failed', 'certification.pdf_signature_failed'],
            array_column(array_values(array_filter(WebhookEvent::cases(), fn (WebhookEvent $event) => $event->isFailure())), 'value'),
        );
        $this->assertTrue(WebhookEvent::PdfSigned->isSuccess());

        $this->assertSame('eidas_certificate', WebhookEvent::EidasCertificateGenerated->artifactType());
        $this->assertSame('pades_pdf', WebhookEvent::PdfSignatureFailed->artifactType());
        $this->assertNull(WebhookEvent::CertificationSucceeded->artifactType());
    }

    public function test_every_event_maps_to_its_own_laravel_event(): void
    {
        $classes = array_map(fn (WebhookEvent $event) => $event->eventClass(), WebhookEvent::cases());

        $this->assertCount(7, array_unique($classes));

        foreach ($classes as $class) {
            $this->assertTrue(is_subclass_of($class, PreuviaWebhookEvent::class), $class);
        }
    }
}
