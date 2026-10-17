<?php

namespace Preuvia\Laravel\Tests\Testing;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Preuvia\Laravel\Exceptions\NotFoundException;
use Preuvia\Laravel\Exceptions\RateLimitException;
use Preuvia\Laravel\Exceptions\ValidationException;
use Preuvia\Laravel\Facades\Preuvia;
use Preuvia\Laravel\Preuvia as PreuviaClient;
use Preuvia\Laravel\Testing\PreuviaFake;
use Preuvia\Laravel\Tests\TestCase;

class PreuviaFakeTest extends TestCase
{
    #[Test]
    public function relative_stubs_are_prefixed_with_the_base_url(): void
    {
        Preuvia::fake([
            'dossiers/*' => PreuviaFake::ok(['uuid' => 'd-1']),
            '/users' => PreuviaFake::paginated([['uuid' => 'u-1']]),
        ]);

        $this->assertSame('d-1', Preuvia::dossiers()->show('d-1')['data']['uuid']);
        $this->assertSame('u-1', Preuvia::users()->list()->data('0.uuid'));

        Http::assertSentCount(2);
    }

    #[Test]
    public function a_prefixed_stub_does_not_catch_another_host(): void
    {
        Preuvia::fake([
            'dossiers/*' => PreuviaFake::ok(['from' => 'preuvia']),
            '*' => PreuviaFake::ok(['from' => 'fallback']),
        ]);

        $this->assertSame('preuvia', Preuvia::dossiers()->show('d-1')->data('from'));
        $this->assertSame('fallback', Preuvia::withBaseUrl('https://elsewhere.test/v1')->dossiers()->show('d-1')->data('from'));
    }

    #[Test]
    public function absolute_urls_and_wildcards_are_kept(): void
    {
        Preuvia::fake([
            'https://other.test/api/v1/me' => PreuviaFake::ok(['who' => 'other']),
            '*/tokens/balance' => PreuviaFake::ok(['balance' => 12]),
        ]);

        $this->assertSame('other', Preuvia::withBaseUrl('https://other.test/api/v1')->me()->data('who'));
        $this->assertSame(12, Preuvia::tokens()->balance()->data('balance'));
    }

    #[Test]
    public function without_stubs_every_request_gets_an_empty_success(): void
    {
        Preuvia::fake();

        $response = Preuvia::dossiers()->create(['template_uuid' => 't-1']);

        $this->assertTrue($response->successful());
        $this->assertNull($response->data());
        Http::assertSent(fn (Request $request) => $request->url() === 'https://preuvia.test/api/v1/dossiers'
            && $request['template_uuid'] === 't-1');
    }

    #[Test]
    public function it_works_when_the_client_was_resolved_before_faking(): void
    {
        $client = $this->app->make(PreuviaClient::class);
        $facadeRoot = Preuvia::getFacadeRoot();

        Preuvia::fake(['me' => PreuviaFake::ok(['name' => 'Neo Elite'])]);

        $this->assertSame('Neo Elite', $client->me()->data('name'));
        $this->assertSame('Neo Elite', $facadeRoot->me()->data('name'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function the_builders_produce_preuvia_envelopes(): void
    {
        Preuvia::fake([
            'templates/custom' => PreuviaFake::created(['uuid' => 't-1']),
            'users' => PreuviaFake::validation(['email' => ['Adresse déjà utilisée.']]),
            'dossiers/missing' => PreuviaFake::notFound(),
            'dossiers/d-1/documents/doc-1/download' => PreuviaFake::file('bytes', 'image/png', 'Façade.png'),
        ]);

        $created = Preuvia::customTemplates()->create(['name' => 'Visite', 'category' => 'x', 'config' => []]);
        $this->assertSame(201, $created->status());
        $this->assertSame(['success' => true, 'message' => null, 'data' => ['uuid' => 't-1'], 'meta' => [], 'errors' => null], $created->toArray());

        try {
            Preuvia::users()->create(['first_name' => 'Ana', 'email' => 'ana@example.test']);
            $this->fail('A ValidationException was expected.');
        } catch (ValidationException $exception) {
            $this->assertSame('validation_failed', $exception->errorCode());
            $this->assertSame(['email' => ['Adresse déjà utilisée.']], $exception->errors());
        }

        try {
            Preuvia::dossiers()->show('missing');
            $this->fail('A NotFoundException was expected.');
        } catch (NotFoundException $exception) {
            $this->assertSame('not_found', $exception->errorCode());
        }

        $download = Preuvia::documents()->download('d-1', 'doc-1');
        $this->assertSame('Façade.png', $download->filename());
        $this->assertSame('image/png', $download->mimeType());
    }

    #[Test]
    public function paginated_computes_a_plausible_total(): void
    {
        Preuvia::fake([
            'templates' => PreuviaFake::paginated([['id' => 1], ['id' => 2]], page: 3, lastPage: 3, perPage: 10),
            'users' => PreuviaFake::paginated([['id' => 1]], page: 1, lastPage: 4, perPage: 1, total: 99),
        ]);

        $this->assertSame(22, Preuvia::templates()->list()->pagination()->total);
        $this->assertSame(99, Preuvia::users()->list()->pagination()->total);
    }

    #[Test]
    public function error_carries_status_and_headers(): void
    {
        Preuvia::fake(['me' => PreuviaFake::error('too_many_requests', 'Slow down', 429, [], ['Retry-After' => '120'])]);

        try {
            Preuvia::me();
            $this->fail('An exception was expected.');
        } catch (RateLimitException $exception) {
            $this->assertSame(120, $exception->retryAfter());
            $this->assertSame('Slow down', $exception->response()['message']);
        }
    }
}
