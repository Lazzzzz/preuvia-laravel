<?php

namespace Preuvia\Laravel\Webhooks;

use Preuvia\Laravel\Webhooks\Events\CertificationFailed;
use Preuvia\Laravel\Webhooks\Events\CertificationInsufficientTokens;
use Preuvia\Laravel\Webhooks\Events\CertificationSucceeded;
use Preuvia\Laravel\Webhooks\Events\EidasCertificateFailed;
use Preuvia\Laravel\Webhooks\Events\EidasCertificateGenerated;
use Preuvia\Laravel\Webhooks\Events\PdfSignatureFailed;
use Preuvia\Laravel\Webhooks\Events\PdfSigned;
use Preuvia\Laravel\Webhooks\Events\PreuviaWebhookEvent;

enum WebhookEvent: string
{
    case CertificationSucceeded = 'certification.success';
    case CertificationFailed = 'certification.failed';
    case CertificationInsufficientTokens = 'certification.insufficient_tokens';
    case EidasCertificateGenerated = 'certification.eidas_certificate.generated';
    case EidasCertificateFailed = 'certification.eidas_certificate.failed';
    case PdfSigned = 'certification.pdf_signed';
    case PdfSignatureFailed = 'certification.pdf_signature_failed';

    /**
     * Outcome of the proof's timestamp itself (as opposed to a generated artifact).
     */
    public function isCertification(): bool
    {
        return match ($this) {
            self::CertificationSucceeded, self::CertificationFailed, self::CertificationInsufficientTokens => true,
            default => false,
        };
    }

    /**
     * The payload carries an "artifact" object (eIDAS certificate or PAdES-signed PDF).
     */
    public function hasArtifact(): bool
    {
        return ! $this->isCertification();
    }

    public function isSuccess(): bool
    {
        return match ($this) {
            self::CertificationSucceeded, self::EidasCertificateGenerated, self::PdfSigned => true,
            default => false,
        };
    }

    public function isFailure(): bool
    {
        return ! $this->isSuccess();
    }

    /**
     * Artifact type as sent in payload.artifact.type, null for certification events.
     */
    public function artifactType(): ?string
    {
        return match ($this) {
            self::EidasCertificateGenerated, self::EidasCertificateFailed => 'eidas_certificate',
            self::PdfSigned, self::PdfSignatureFailed => 'pades_pdf',
            default => null,
        };
    }

    /**
     * Laravel event dispatched by the built-in receiver for this callback.
     *
     * @return class-string<PreuviaWebhookEvent>
     */
    public function eventClass(): string
    {
        return match ($this) {
            self::CertificationSucceeded => CertificationSucceeded::class,
            self::CertificationFailed => CertificationFailed::class,
            self::CertificationInsufficientTokens => CertificationInsufficientTokens::class,
            self::EidasCertificateGenerated => EidasCertificateGenerated::class,
            self::EidasCertificateFailed => EidasCertificateFailed::class,
            self::PdfSigned => PdfSigned::class,
            self::PdfSignatureFailed => PdfSignatureFailed::class,
        };
    }
}
