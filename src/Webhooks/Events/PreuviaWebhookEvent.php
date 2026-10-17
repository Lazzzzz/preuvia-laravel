<?php

namespace Preuvia\Laravel\Webhooks\Events;

use Preuvia\Laravel\Webhooks\PreuviaWebhook;

abstract class PreuviaWebhookEvent
{
    public function __construct(public readonly PreuviaWebhook $webhook) {}
}
