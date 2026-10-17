<?php

namespace Preuvia\Laravel\Exceptions;

/**
 * A successful answer whose body is not the expected JSON envelope.
 */
class InvalidResponseException extends PreuviaException
{
    public static function notJson(string $method, string $endpoint, int $status): self
    {
        $method = strtoupper($method);

        return new self(
            sprintf('Preuvia API %s %s returned an invalid JSON response (%d).', $method, $endpoint, $status),
            $method,
            $endpoint,
            $status,
        );
    }
}
