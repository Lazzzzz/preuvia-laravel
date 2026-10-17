<?php

namespace Preuvia\Laravel\Exceptions;

/**
 * HTTP 429: too many requests, wait retryAfter() seconds (too_many_requests).
 */
class RateLimitException extends PreuviaException {}
