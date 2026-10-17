<?php

namespace Preuvia\Laravel\Webhooks\Exceptions;

use RuntimeException;

class WebhookNotConfiguredException extends RuntimeException
{
    public static function missingSecret(): self
    {
        return new self('The Preuvia webhook secret is not configured (PREUVIA_WEBHOOK_SECRET).');
    }
}
