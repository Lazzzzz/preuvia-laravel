<?php

namespace Preuvia\Laravel\Exceptions;

/**
 * HTTP 403: missing scope or permission, or the resource cannot be changed (missing_api_scope, forbidden, ...).
 */
class AuthorizationException extends PreuviaException {}
