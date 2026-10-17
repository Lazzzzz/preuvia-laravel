<?php

namespace Preuvia\Laravel\Webhooks\Exceptions;

use RuntimeException;

/**
 * Thrown by a listener that cannot process the callback yet: the built-in receiver
 * answers 503 with Retry-After and Preuvia redelivers it later.
 */
class RetryWebhookLater extends RuntimeException
{
    private readonly int $retryAfter;

    public function __construct(int $seconds = 60, ?string $message = null)
    {
        $this->retryAfter = max(0, $seconds);

        parent::__construct($message ?? 'Webhook postponed, retry later.');
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
