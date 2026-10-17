<?php

namespace Preuvia\Laravel\Exceptions;

/**
 * HTTP 422: invalid input, see errors() (validation_failed, capture_hash_mismatch).
 */
class ValidationException extends PreuviaException {}
