<?php

namespace Preuvia\Laravel\Exceptions;

/**
 * HTTP 401: the API key is unknown, expired or revoked (invalid_api_key, api_key_not_valid).
 */
class AuthenticationException extends PreuviaException {}
