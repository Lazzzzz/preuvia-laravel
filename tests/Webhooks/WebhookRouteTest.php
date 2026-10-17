<?php

namespace Preuvia\Laravel\Tests\Webhooks;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Preuvia\Laravel\Tests\TestCase;
use Preuvia\Laravel\Webhooks\Http\VerifyPreuviaWebhook;
use Preuvia\Laravel\Webhooks\Http\WebhookController;

class WebhookRouteTest extends TestCase
{
    public function test_no_route_without_a_configured_path(): void
    {
        $this->assertFalse(Route::has('preuvia.webhook'));

        $this->postJson('webhooks/preuvia', [])->assertNotFound();
    }

    #[DefineEnvironment('withWebhookPath')]
    public function test_registers_the_receiver_on_the_configured_path(): void
    {
        $route = Route::getRoutes()->getByName('preuvia.webhook');

        $this->assertNotNull($route);
        $this->assertSame('hooks/preuvia', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(WebhookController::class, $route->getActionName());
        $this->assertSame(['throttle:120,1', VerifyPreuviaWebhook::class], $route->gatherMiddleware());
    }

    #[DefineEnvironment('withWebhookPath')]
    public function test_the_registered_route_rejects_unsigned_callbacks(): void
    {
        $this->postJson('hooks/preuvia', ['event' => 'certification.success'])->assertUnauthorized();
    }

    protected function withWebhookPath($app): void
    {
        $app['config']->set('preuvia.webhooks.path', 'hooks/preuvia');
        $app['config']->set('preuvia.webhooks.middleware', ['throttle:120,1']);
    }
}
