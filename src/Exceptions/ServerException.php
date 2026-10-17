<?php

namespace Preuvia\Laravel\Exceptions;

/**
 * HTTP 5xx: Preuvia or one of its dependencies failed (artifact_generation_failed, document_storage_unavailable, ...).
 */
class ServerException extends PreuviaException {}
