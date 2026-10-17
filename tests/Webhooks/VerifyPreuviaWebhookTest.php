<?php

namespace Preuvia\Laravel\Tests\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Preuvia\Laravel\Tests\TestCase;
use Preuvia\Laravel\Webhooks\Http\VerifyPreuviaWebhook;
use Preuvia\Laravel\Webhooks\PreuviaWebhook;
use Preuvia\Laravel\Webhooks\Testing\SignsPreuviaWebhooks;
use Preuvia\Laravel\Webhooks\WebhookEvent;
use Preuvia\Laravel\Webhooks\WebhookSignature;

class VerifyPreuviaWebhookTest extends TestCase
{
    use SignsPreuviaWebhooks;

    protected function defineRoutes($router): void
    {
        $router->post('custom-hook', function (Request $request) {
            $webhook = $request->attributes->get(PreuviaWebhook::class);

            return ['webhook' => $webhook instanceof PreuviaWebhook ? $webhook->toArray() : null];
        })->middleware(VerifyPreuviaWebhook::class);
    }

    public function test_passes_a_signed_callback_and_exposes_the_webhook(): void
    {
        $this->postPreuviaWebhook('custom-hook', WebhookEvent::CertificationSucceeded, ['sandbox' => true, 'dossier' => ['uuid' => 'd-1']])
            ->assertOk()
            ->assertJsonPath('webhook.event', 'certification.success')
            ->assertJsonPath('webhook.sandbox', true)
            ->assertJsonPath('webhook.attempt', 1)
            ->assertJsonPath('webhook.payload.dossier.uuid', 'd-1');
    }

    public function test_answers_503_when_no_secret_is_configured(): void
    {
        config(['preuvia.webhooks.secret' => null]);

        $this->postPreuviaWebhook('custom-hook', WebhookEvent::CertificationSucceeded, secret: 'whsec_test')
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Webhook not configured.']);
    }

    public function test_answers_401_on_a_wrong_secret_and_logs_the_reason_only(): void
    {
        Log::spy();

        $this->postPreuviaWebhook('custom-hook', WebhookEvent::CertificationSucceeded, ['document' => ['hash' => 'secret-body-value']], [
            'X-Preuvia-Delivery' => 'delivery-uuid',
        ], secret: 'wrong-secret')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid signature.']);

        Log::shouldHaveReceived('warning')->once()->with('Preuvia webhook rejected: invalid signature.', Mockery::on(function (array $context): bool {
            $logged = json_encode($context);

            return $context['reason'] === 'signature_mismatch'
                && $context['delivery_id'] === 'delivery-uuid'
                && ! str_contains($logged, 'whsec_test')
                && ! str_contains($logged, 'secret-body-value');
        }));
    }

    public function test_answers_401_on_a_missing_signature(): void
    {
        Log::spy();

        $this->call('POST', 'custom-hook', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"event":"certification.success"}')
            ->assertUnauthorized();

        Log::shouldHaveReceived('warning')->once()->with(Mockery::any(), Mockery::on(fn (array $context) => $context['reason'] === 'missing_header'));
    }

    public function test_answers_401_on_a_stale_signature(): void
    {
        $body = '{"event":"certification.success"}';

        $this->call('POST', 'custom-hook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PREUVIA_SIGNATURE' => WebhookSignature::sign($body, 'whsec_test', time() - 301),
        ], $body)->assertUnauthorized();
    }

    public function test_uses_the_configured_tolerance(): void
    {
        config(['preuvia.webhooks.tolerance' => 1000]);
        $body = '{"event":"certification.success"}';

        $this->call('POST', 'custom-hook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PREUVIA_SIGNATURE' => WebhookSignature::sign($body, 'whsec_test', time() - 900),
        ], $body)->assertOk();
    }

    public function test_answers_401_on_a_tampered_body(): void
    {
        $signed = '{"event":"certification.success","sandbox":true}';

        $this->call('POST', 'custom-hook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PREUVIA_SIGNATURE' => WebhookSignature::sign($signed, 'whsec_test'),
        ], '{"event":"certification.success","sandbox":false}')->assertUnauthorized();
    }

    public function test_verifies_the_exact_raw_body(): void
    {
        // Unescaped slashes and unicode, as Preuvia encodes them: a re-encoded body would not match.
        $body = '{"event":"certification.success","document":{"original_name":"été/photo.jpg"}}';

        $this->call('POST', 'custom-hook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PREUVIA_SIGNATURE' => WebhookSignature::sign($body, 'whsec_test'),
        ], $body)
            ->assertOk()
            ->assertJsonPath('webhook.payload.document.original_name', 'été/photo.jpg');
    }
}
