<?php

namespace Preuvia\Laravel\Exceptions;

/**
 * Missing or invalid SDK configuration, detected before any request is sent.
 */
class ConfigurationException extends PreuviaException
{
    public static function missingApiKey(): self
    {
        return new self('The Preuvia API key is not configured: set PREUVIA_API_KEY or call withApiKey().');
    }

    public static function missingBaseUrl(): self
    {
        return new self('The Preuvia API base URL is not configured: set PREUVIA_API_BASE_URL or call withBaseUrl().');
    }
}
