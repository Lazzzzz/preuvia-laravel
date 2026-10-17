<?php

namespace Preuvia\Laravel\Webhooks\Events;

/**
 * certification.insufficient_tokens: the client has no token left; the certification waits for a top-up.
 */
final class CertificationInsufficientTokens extends PreuviaWebhookEvent {}
