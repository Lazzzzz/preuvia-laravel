<?php

namespace Preuvia\Laravel\Exceptions;

/**
 * HTTP 409: the resource state forbids the operation (capture_id_conflict, template_snapshot_diverged, ...).
 */
class ConflictException extends PreuviaException {}
