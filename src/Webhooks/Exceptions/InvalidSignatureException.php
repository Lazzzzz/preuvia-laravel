<?php

namespace Preuvia\Laravel\Webhooks\Exceptions;

use RuntimeException;

class InvalidSignatureException extends RuntimeException
{
    public const MISSING_HEADER = 'missing_header';

    public const MALFORMED_HEADER = 'malformed_header';

    public const TIMESTAMP_OUTSIDE_TOLERANCE = 'timestamp_outside_tolerance';

    public const SIGNATURE_MISMATCH = 'signature_mismatch';

    public function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function missingHeader(): self
    {
        return new self(self::MISSING_HEADER, 'The X-Preuvia-Signature header is missing.');
    }

    public static function malformedHeader(): self
    {
        return new self(self::MALFORMED_HEADER, 'The X-Preuvia-Signature header is malformed.');
    }

    public static function timestampOutsideTolerance(int $timestamp, int $now, int $tolerance): self
    {
        return new self(self::TIMESTAMP_OUTSIDE_TOLERANCE, sprintf(
            'The webhook was signed %d seconds away from now (tolerance: %d seconds).',
            abs($now - $timestamp),
            $tolerance,
        ));
    }

    public static function signatureMismatch(): self
    {
        return new self(self::SIGNATURE_MISMATCH, 'The webhook signature does not match its body.');
    }

    /**
     * One of the class constants, safe to log.
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
