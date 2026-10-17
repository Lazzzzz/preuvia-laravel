<?php

namespace Preuvia\Laravel\Tests\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Preuvia\Laravel\Tests\TestCase;
use Preuvia\Laravel\Webhooks\Events\CertificationFailed;
use Preuvia\Laravel\Webhooks\Events\CertificationSucceeded;
use Preuvia\Laravel\Webhooks\Events\PdfSigned;
use Preuvia\Laravel\Webhooks\Events\PreuviaWebhookEvent;
use Preuvia\Laravel\Webhooks\Events\WebhookReceived;
use Preuvia\Laravel\Webhooks\Exceptions\RetryWebhookLater;
use Preuvia\Laravel\Webhooks\Http\WebhookController;
use Preuvia\Laravel\Webhooks\Testing\PreuviaWebhookPayload;
use Preuvia\Laravel\Webhooks\Testing\SignsPreuviaWebhooks;
use Preuvia\Laravel\Webhooks\WebhookEvent;
use Preuvia\Laravel\Webhooks\WebhookSignature;
use RuntimeException;

class WebhookControllerTest extends TestCase
{
    use SignsPreuviaWebhooks;

    private const URI = 'webhooks/preuvia';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('preuvia.webhooks.path', self::URI);
    }

    protected function defineRoutes($router): void
    {
        // The controller wired without the middleware must still verify the signature.
        $router->post('bare-hook', WebhookController::class);
    }

    public function test_dispatches_the_generic_and_the_specific_event(): void
    {
        Event::fake();

        $this->postPreuviaWebhook(self::URI, WebhookEvent::CertificationSucceeded, PreuviaWebhookPayload::certificationSucceeded([
            'document' => ['client_capture_id' => 'photo-42'],
        ]))->assertOk()->assertExactJson(['received' => true]);

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->webhook->clientCaptureId() === 'photo-42');
        Event::assertDispatched(CertificationSucceeded::class, fn (CertificationSucceeded $event) => $event->webhook->clientCaptureId() === 'photo-42'
            && $event->webhook->deliveryId() !== null);
        Event::assertNotDispatched(CertificationFailed::class);
    }

    public function test_dispatches_the_matching_class_for_every_known_event(): void
    {
        foreach (WebhookEvent::cases() as $case) {
            Event::fake();

            $this->postPreuviaWebhook(self::URI, $case)->assertOk();

            Event::assertDispatchedTimes(WebhookReceived::class, 1);
            Event::assertDispatched($case->eventClass(), fn (PreuviaWebhookEvent $event) => $event->webhook->event() === $case);
        }
    }

    public function test_an_unknown_event_is_acknowledged_with_the_generic_event_only(): void
    {
        Event::fake();

        $this->postPreuviaWebhook(self::URI, 'dossier.completed', ['dossier' => ['uuid' => 'd-1']])
            ->assertOk()
            ->assertExactJson(['received' => true]);

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->webhook->eventName() === 'dossier.completed'
            && $event->webhook->event() === null);
        Event::assertDispatchedTimes(WebhookReceived::class, 1);
        foreach (WebhookEvent::cases() as $case) {
            Event::assertNotDispatched($case->eventClass());
        }
    }

    public function test_a_listener_can_ask_for_a_redelivery(): void
    {
        Event::listen(PdfSigned::class, function (): void {
            throw new RetryWebhookLater(600, 'Report busy.');
        });

        $this->postPreuviaWebhook(self::URI, WebhookEvent::PdfSigned)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '600')
            ->assertExactJson(['message' => 'Report busy.', 'retry_after' => 600]);
    }

    public function test_retry_later_defaults_to_sixty_seconds(): void
    {
        Event::listen(WebhookReceived::class, function (): void {
            throw new RetryWebhookLater;
        });

        $this->postPreuviaWebhook(self::URI, WebhookEvent::CertificationFailed)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60');
    }

    public function test_other_listener_exceptions_bubble_up_as_a_server_error(): void
    {
        Event::listen(CertificationSucceeded::class, function (): void {
            throw new RuntimeException('Database down.');
        });

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database down.');

        $this->postPreuviaWebhook(self::URI, WebhookEvent::CertificationSucceeded);
    }

    public function test_listener_exceptions_return_500_so_preuvia_retries(): void
    {
        Event::listen(CertificationSucceeded::class, function (): void {
            throw new RuntimeException('Database down.');
        });

        $this->postPreuviaWebhook(self::URI, WebhookEvent::CertificationSucceeded)->assertStatus(500);
    }

    public function test_listeners_receive_the_full_callback(): void
    {
        $received = null;
        Event::listen(CertificationSucceeded::class, function (CertificationSucceeded $event) use (&$received): void {
            $received = $event->webhook;
        });

        $this->postPreuviaWebhook(self::URI, WebhookEvent::CertificationSucceeded, PreuviaWebhookPayload::certificationSucceeded(['sandbox' => true]), [
            'X-Preuvia-Delivery' => 'delivery-uuid',
            'X-Preuvia-Attempt' => '2',
        ])->assertOk();

        $this->assertSame('delivery-uuid', $received?->deliveryId());
        $this->assertSame(2, $received->attempt());
        $this->assertTrue($received->isSandbox());
        $this->assertSame('success', $received->certification()['status']);
    }

    public function test_the_controller_verifies_the_signature_when_routed_without_the_middleware(): void
    {
        Event::fake();

        $this->postJson('bare-hook', ['event' => 'certification.success'])->assertUnauthorized();
        Event::assertNotDispatched(WebhookReceived::class);

        $this->postPreuviaWebhook('bare-hook', WebhookEvent::CertificationSucceeded)->assertOk();
        Event::assertDispatched(CertificationSucceeded::class);
    }

    public function test_the_route_answers_503_when_the_secret_is_missing(): void
    {
        Event::fake();
        config(['preuvia.webhooks.secret' => '']);

        $this->postPreuviaWebhook(self::URI, WebhookEvent::CertificationSucceeded)->assertStatus(503);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_the_trait_signs_like_preuvia(): void
    {
        $seen = null;
        $this->app['router']->post('inspect-hook', function (Request $request) use (&$seen) {
            $seen = $request;

            return ['ok' => true];
        });

        $this->postPreuviaWebhook('inspect-hook', WebhookEvent::PdfSigned, ['sandbox' => true, 'path' => 'a/é'])->assertOk();

        $body = $seen->getContent();
        $this->assertSame('{"sandbox":true,"path":"a/é","event":"certification.pdf_signed"}', $body);
        $this->assertSame('application/json', $seen->header('Content-Type'));
        $this->assertSame('certification.pdf_signed', $seen->header('X-Preuvia-Event'));
        $this->assertSame('1', $seen->header('X-Preuvia-Attempt'));
        $this->assertSame('1', $seen->header('X-Preuvia-Sandbox'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $seen->header('X-Preuvia-Delivery'));
        $this->assertSame($seen->header('X-Preuvia-Delivery'), $seen->header('Idempotency-Key'));
        $this->assertTrue(WebhookSignature::isValid($body, $seen->header('X-Preuvia-Signature'), 'whsec_test'));
    }
}
