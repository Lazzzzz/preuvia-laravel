<?php

namespace Preuvia\Laravel\Webhooks\Events;

/**
 * Every verified callback, known event or not. Dispatched before the specific event.
 */
final class WebhookReceived extends PreuviaWebhookEvent {}
