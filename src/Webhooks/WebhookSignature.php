<?php

namespace Preuvia\Laravel\Webhooks;

use Preuvia\Laravel\Webhooks\Exceptions\InvalidSignatureException;
use Preuvia\Laravel\Webhooks\Exceptions\WebhookNotConfiguredException;

/**
 * X-Preuvia-Signature: t={unix timestamp},sha256={hex HMAC-SHA256 of "{timestamp}.{raw body}"}
 */
final class WebhookSignature
{
    public const HEADER = 'X-Preuvia-Signature';

    public const DEFAULT_TOLERANCE = 300;

    /**
     * @throws InvalidSignatureException
     * @throws WebhookNotConfiguredException when the secret is empty
     */
    public static function verify(string $payload, ?string $header, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): void
    {
        self::ensureSecret($secret);

        if ($header === null || trim($header) === '') {
            throw InvalidSignatureException::missingHeader();
        }

        [$timestamp, $signatures] = self::parse($header);

        $now ??= time();

        // Both directions: an old signature is a replay, a far-future one would stay replayable.
        if (abs($now - $timestamp) > max(0, $tolerance)) {
            throw InvalidSignatureException::timestampOutsideTolerance($timestamp, $now, $tolerance);
        }

        $expected = self::compute($payload, $secret, $timestamp);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw InvalidSignatureException::signatureMismatch();
    }

    public static function isValid(string $payload, ?string $header, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): bool
    {
        try {
            self::verify($payload, $header, $secret, $tolerance, $now);
        } catch (InvalidSignatureException|WebhookNotConfiguredException) {
            return false;
        }

        return true;
    }

    /**
     * Full X-Preuvia-Signature header value, as Preuvia sends it.
     */
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        self::ensureSecret($secret);

        $timestamp ??= time();

        return 't='.$timestamp.',sha256='.self::compute($payload, $secret, $timestamp);
    }

    private static function compute(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private static function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            $key = strtolower(trim($key));
            $value = trim((string) $value);

            if ($key === 't') {
                if ($timestamp !== null || preg_match('/^\d{1,12}$/', $value) !== 1) {
                    throw InvalidSignatureException::malformedHeader();
                }

                $timestamp = (int) $value;
            } elseif ($key === 'sha256') {
                if (preg_match('/^[a-f0-9]{64}$/i', $value) !== 1) {
                    throw InvalidSignatureException::malformedHeader();
                }

                $signatures[] = strtolower($value);
            }
            // Other keys are ignored so a future signing scheme can be added alongside sha256.
        }

        if ($timestamp === null || $signatures === []) {
            throw InvalidSignatureException::malformedHeader();
        }

        return [$timestamp, $signatures];
    }

    private static function ensureSecret(string $secret): void
    {
        // An empty HMAC key would let anyone forge valid signatures.
        if ($secret === '') {
            throw WebhookNotConfiguredException::missingSecret();
        }
    }
}
