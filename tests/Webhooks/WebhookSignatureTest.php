<?php

namespace Preuvia\Laravel\Tests\Webhooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preuvia\Laravel\Webhooks\Exceptions\InvalidSignatureException;
use Preuvia\Laravel\Webhooks\Exceptions\WebhookNotConfiguredException;
use Preuvia\Laravel\Webhooks\WebhookSignature;

class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const BODY = '{"event":"certification.success","dossier":{"uuid":"abc"},"url":"https://x.test/a"}';

    private const NOW = 1_800_000_000;

    public function test_sign_produces_the_header_preuvia_sends(): void
    {
        $expected = 't='.self::NOW.',sha256='.hash_hmac('sha256', self::NOW.'.'.self::BODY, self::SECRET);

        $this->assertSame($expected, WebhookSignature::sign(self::BODY, self::SECRET, self::NOW));
    }

    public function test_accepts_a_valid_signature(): void
    {
        $header = WebhookSignature::sign(self::BODY, self::SECRET, self::NOW);

        WebhookSignature::verify(self::BODY, $header, self::SECRET, 300, self::NOW);

        $this->assertTrue(WebhookSignature::isValid(self::BODY, $header, self::SECRET, 300, self::NOW));
    }

    public function test_sign_defaults_to_the_current_time(): void
    {
        $this->assertTrue(WebhookSignature::isValid(self::BODY, WebhookSignature::sign(self::BODY, self::SECRET), self::SECRET));
    }

    public function test_tolerates_whitespace_key_order_and_uppercase_hex(): void
    {
        $hmac = hash_hmac('sha256', self::NOW.'.'.self::BODY, self::SECRET);

        $this->assertTrue(WebhookSignature::isValid(self::BODY, ' sha256='.strtoupper($hmac).' , t='.self::NOW.' ', self::SECRET, 300, self::NOW));
    }

    public function test_accepts_any_matching_entry_when_several_signatures_are_sent(): void
    {
        $hmac = hash_hmac('sha256', self::NOW.'.'.self::BODY, self::SECRET);
        $other = hash_hmac('sha256', self::NOW.'.'.self::BODY, 'rotated-secret');

        $this->assertTrue(WebhookSignature::isValid(self::BODY, 't='.self::NOW.",sha256={$other},sha256={$hmac}", self::SECRET, 300, self::NOW));
    }

    public function test_ignores_unknown_keys(): void
    {
        $header = WebhookSignature::sign(self::BODY, self::SECRET, self::NOW).',v2=whatever';

        $this->assertTrue(WebhookSignature::isValid(self::BODY, $header, self::SECRET, 300, self::NOW));
    }

    public function test_rejects_a_tampered_body(): void
    {
        $header = WebhookSignature::sign(self::BODY, self::SECRET, self::NOW);

        $this->assertReason(InvalidSignatureException::SIGNATURE_MISMATCH, str_replace('abc', 'abd', self::BODY), $header);
    }

    public function test_rejects_a_reencoded_body(): void
    {
        // Verification must run on the raw body: re-encoding escapes the slashes.
        $header = WebhookSignature::sign(self::BODY, self::SECRET, self::NOW);
        $reencoded = json_encode(json_decode(self::BODY, true));

        $this->assertReason(InvalidSignatureException::SIGNATURE_MISMATCH, $reencoded, $header);
    }

    public function test_rejects_a_wrong_secret(): void
    {
        $this->assertReason(InvalidSignatureException::SIGNATURE_MISMATCH, self::BODY, WebhookSignature::sign(self::BODY, 'other-secret', self::NOW));
    }

    public function test_rejects_a_signature_bound_to_another_timestamp(): void
    {
        $hmac = hash_hmac('sha256', self::NOW.'.'.self::BODY, self::SECRET);

        $this->assertReason(InvalidSignatureException::SIGNATURE_MISMATCH, self::BODY, 't='.(self::NOW + 1).',sha256='.$hmac);
    }

    public function test_rejects_a_stale_signature(): void
    {
        $this->assertReason(InvalidSignatureException::TIMESTAMP_OUTSIDE_TOLERANCE, self::BODY, WebhookSignature::sign(self::BODY, self::SECRET, self::NOW - 301));
    }

    public function test_rejects_a_far_future_signature(): void
    {
        $this->assertReason(InvalidSignatureException::TIMESTAMP_OUTSIDE_TOLERANCE, self::BODY, WebhookSignature::sign(self::BODY, self::SECRET, self::NOW + 301));
    }

    public function test_accepts_exactly_the_tolerance_both_ways(): void
    {
        foreach ([self::NOW - 300, self::NOW + 300] as $timestamp) {
            $this->assertTrue(WebhookSignature::isValid(self::BODY, WebhookSignature::sign(self::BODY, self::SECRET, $timestamp), self::SECRET, 300, self::NOW));
        }
    }

    public function test_uses_the_given_tolerance(): void
    {
        $header = WebhookSignature::sign(self::BODY, self::SECRET, self::NOW - 30);

        $this->assertFalse(WebhookSignature::isValid(self::BODY, $header, self::SECRET, 10, self::NOW));
        $this->assertTrue(WebhookSignature::isValid(self::BODY, $header, self::SECRET, 30, self::NOW));
    }

    public function test_rejects_a_missing_header(): void
    {
        $this->assertReason(InvalidSignatureException::MISSING_HEADER, self::BODY, null);
        $this->assertReason(InvalidSignatureException::MISSING_HEADER, self::BODY, '  ');
    }

    #[DataProvider('malformedHeaders')]
    public function test_rejects_a_malformed_header(string $header): void
    {
        $this->assertReason(InvalidSignatureException::MALFORMED_HEADER, self::BODY, $header);
    }

    public static function malformedHeaders(): array
    {
        $hmac = str_repeat('a', 64);

        return [
            'legacy bare hmac' => [$hmac],
            'no timestamp' => ['sha256='.$hmac],
            'no signature' => ['t='.self::NOW],
            'non numeric timestamp' => ['t=abc,sha256='.$hmac],
            'negative timestamp' => ['t=-5,sha256='.$hmac],
            'short hmac' => ['t='.self::NOW.',sha256=abcd'],
            'non hex hmac' => ['t='.self::NOW.',sha256='.str_repeat('z', 64)],
            'duplicate timestamp' => ['t='.self::NOW.',t='.self::NOW.',sha256='.$hmac],
            'garbage' => ['nonsense'],
        ];
    }

    public function test_exception_exposes_its_reason(): void
    {
        try {
            WebhookSignature::verify(self::BODY, null, self::SECRET);
            $this->fail('Expected an InvalidSignatureException.');
        } catch (InvalidSignatureException $exception) {
            $this->assertSame('missing_header', $exception->reason());
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function test_refuses_an_empty_secret(): void
    {
        $this->assertFalse(WebhookSignature::isValid(self::BODY, WebhookSignature::sign(self::BODY, 'x', self::NOW), '', 300, self::NOW));

        $this->expectException(WebhookNotConfiguredException::class);
        WebhookSignature::verify(self::BODY, 't=1,sha256='.str_repeat('a', 64), '');
    }

    public function test_sign_refuses_an_empty_secret(): void
    {
        $this->expectException(WebhookNotConfiguredException::class);

        WebhookSignature::sign(self::BODY, '');
    }

    private function assertReason(string $reason, string $body, ?string $header): void
    {
        $this->assertFalse(WebhookSignature::isValid($body, $header, self::SECRET, 300, self::NOW));

        try {
            WebhookSignature::verify($body, $header, self::SECRET, 300, self::NOW);
            $this->fail("Expected an InvalidSignatureException ({$reason}).");
        } catch (InvalidSignatureException $exception) {
            $this->assertSame($reason, $exception->reason());
        }
    }
}
