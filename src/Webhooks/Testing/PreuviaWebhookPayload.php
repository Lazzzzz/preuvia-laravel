<?php

namespace Preuvia\Laravel\Webhooks\Testing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Preuvia\Laravel\Webhooks\WebhookEvent;

/**
 * Realistic callback payloads for your tests. Overrides are merged recursively:
 * PreuviaWebhookPayload::certificationSucceeded(['document' => ['client_capture_id' => 'photo-42']]).
 */
final class PreuviaWebhookPayload
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function make(WebhookEvent|string $event, array $overrides = []): array
    {
        $name = $event instanceof WebhookEvent ? $event->value : $event;
        $event = WebhookEvent::tryFrom($name);
        $now = Carbon::now()->toISOString();
        $failed = $event?->isFailure() ?? false;

        $certificationStatus = match ($event) {
            WebhookEvent::CertificationFailed => 'failed',
            WebhookEvent::CertificationInsufficientTokens => 'insufficient_tokens',
            default => 'success',
        };
        $certified = $certificationStatus === 'success';

        $payload = [
            'event' => $name,
            'occurred_at' => $now,
            'sandbox' => false,
            'client' => [
                'uuid' => (string) Str::uuid(),
                'name' => 'Acme',
                'slug' => 'acme',
                'token_balance' => $certificationStatus === 'insufficient_tokens' ? 0 : 100,
            ],
            'dossier' => [
                'uuid' => (string) Str::uuid(),
                'reference' => 'DOS-0001',
                'name' => 'Test dossier',
                'status' => 'active',
                'category' => null,
                'source' => 'api',
                'created_at' => $now,
                'completed_at' => null,
                'archived_at' => null,
            ],
            'document' => [
                'uuid' => (string) Str::uuid(),
                'step_index' => 0,
                'proof_index' => 0,
                'proof_name' => 'Photo',
                'source' => 'api',
                'status' => match ($certificationStatus) {
                    'success' => 'valid',
                    'insufficient_tokens' => 'insufficient_tokens',
                    default => 'failed',
                },
                'original_name' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
                'size' => 123456,
                'hash' => hash('sha256', 'photo.jpg'),
                'captured_at' => $now,
                'device' => null,
                'geolocation' => null,
                'retry_count' => 0,
                'failure_reason' => $certificationStatus === 'failed' ? 'Certification failed.' : null,
                'processing_started_at' => $now,
                'certified_at' => $certified ? $now : null,
                'client_capture_id' => null,
            ],
            'certification' => [
                'uuid' => (string) Str::uuid(),
                'provider' => 'datasure',
                'mode' => 'production',
                'status' => $certificationStatus,
                'hash_algorithm' => 'sha256',
                'fingerprint' => $certified ? hash('sha256', 'photo.jpg') : null,
                'serial_number' => $certified ? '0x1A2B3C' : null,
                'error_message' => $certificationStatus === 'failed' ? 'Certification failed.' : null,
                'requested_at' => $now,
                'completed_at' => $certificationStatus === 'insufficient_tokens' ? null : $now,
                'charged_at' => $certified ? $now : null,
                'refunded_at' => null,
            ],
        ];

        if ($type = $event?->artifactType()) {
            $payload['artifact'] = [
                'uuid' => (string) Str::uuid(),
                'type' => $type,
                'status' => $failed ? 'failed' : 'success',
                'filename' => $failed ? null : ($type === 'pades_pdf' ? 'document-signed.pdf' : 'eidas-certificate.pdf'),
                'error_message' => $failed ? 'Artifact generation failed.' : null,
                'requested_at' => $now,
                'generated_at' => $failed ? null : $now,
            ];
        }

        return array_replace_recursive($payload, $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function certificationSucceeded(array $overrides = []): array
    {
        return self::make(WebhookEvent::CertificationSucceeded, $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function certificationFailed(array $overrides = []): array
    {
        return self::make(WebhookEvent::CertificationFailed, $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function certificationInsufficientTokens(array $overrides = []): array
    {
        return self::make(WebhookEvent::CertificationInsufficientTokens, $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function eidasCertificateGenerated(array $overrides = []): array
    {
        return self::make(WebhookEvent::EidasCertificateGenerated, $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function eidasCertificateFailed(array $overrides = []): array
    {
        return self::make(WebhookEvent::EidasCertificateFailed, $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function pdfSigned(array $overrides = []): array
    {
        return self::make(WebhookEvent::PdfSigned, $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function pdfSignatureFailed(array $overrides = []): array
    {
        return self::make(WebhookEvent::PdfSignatureFailed, $overrides);
    }
}
