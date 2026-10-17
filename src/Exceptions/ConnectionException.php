<?php

namespace Preuvia\Laravel\Exceptions;

use Throwable;

/**
 * Transport failure: DNS, TLS, refused connection or timeout. No HTTP answer was received.
 */
class ConnectionException extends PreuviaException
{
    public static function fromTransport(string $method, string $endpoint, Throwable $previous): self
    {
        $method = strtoupper($method);

        return new self(
            sprintf('Preuvia API %s %s failed (connection error): %s', $method, $endpoint, $previous->getMessage()),
            $method,
            $endpoint,
            previous: $previous,
        );
    }

    public function isTemporary(): bool
    {
        return true;
    }
}
