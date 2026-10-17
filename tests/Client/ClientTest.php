<?php

namespace Preuvia\Laravel\Tests\Client;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Preuvia\Laravel\Exceptions\AuthenticationException;
use Preuvia\Laravel\Exceptions\AuthorizationException;
use Preuvia\Laravel\Exceptions\ConfigurationException;
use Preuvia\Laravel\Exceptions\ConflictException;
use Preuvia\Laravel\Exceptions\ConnectionException;
use Preuvia\Laravel\Exceptions\InsufficientTokensException;
use Preuvia\Laravel\Exceptions\InvalidResponseException;
use Preuvia\Laravel\Exceptions\NotFoundException;
use Preuvia\Laravel\Exceptions\PreuviaException;
use Preuvia\Laravel\Exceptions\RateLimitException;
use Preuvia\Laravel\Exceptions\RequestException;
use Preuvia\Laravel\Exceptions\ServerException;
use Preuvia\Laravel\Exceptions\ValidationException;
use Preuvia\Laravel\Http\Pagination;
use Preuvia\Laravel\Preuvia;
use Preuvia\Laravel\Testing\PreuviaFake;
use Preuvia\Laravel\Tests\TestCase;

class ClientTest extends TestCase
{
    private const BASE = 'https://preuvia.test/api/v1';

    private function client(): Preuvia
    {
        return $this->app->make(Preuvia::class);
    }

    #[Test]
    public function it_sends_the_bearer_token_and_the_sdk_headers(): void
    {
        Http::fake(fn () => PreuviaFake::ok());

        $this->client()->me();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer preuvia_test_key')
            && $request->hasHeader('User-Agent', 'preuvia-laravel/'.Preuvia::VERSION)
            && $request->hasHeader('Accept', 'application/json'));
    }

    #[Test]
    public function a_missing_api_key_fails_before_any_request(): void
    {
        Http::fake();

        try {
            $this->client()->withApiKey('  ')->dossiers()->list();
            $this->fail('A ConfigurationException was expected.');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString('API key', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function the_service_provider_reads_the_config(): void
    {
        $this->app['config']->set('preuvia.api_key', null);
        $this->app->forgetInstance(Preuvia::class);
        Http::fake();

        $this->expectException(ConfigurationException::class);

        $this->client()->me();
    }

    #[Test]
    public function with_api_key_returns_an_isolated_copy(): void
    {
        Http::fake(fn () => PreuviaFake::ok());

        $client = $this->client();
        $other = $client->withApiKey('other_key')->withBaseUrl('https://other.test/api/v1/');

        $this->assertNotSame($client, $other);

        $other->me();
        $client->me();

        $recorded = Http::recorded()->map(fn (array $pair) => [$pair[0]->url(), $pair[0]->header('Authorization')[0]])->all();

        $this->assertSame([
            ['https://other.test/api/v1/me', 'Bearer other_key'],
            [self::BASE.'/me', 'Bearer preuvia_test_key'],
        ], $recorded);
    }

    #[Test]
    public function with_timeout_and_retries_return_copies(): void
    {
        $client = $this->client();

        $this->assertNotSame($client, $client->withTimeout(5));
        $this->assertNotSame($client, $client->withRetries(4));
        $this->assertNotSame($client, $client->withoutRetries());
    }

    #[Test]
    public function the_response_reads_like_the_raw_envelope(): void
    {
        Http::fake(fn () => PreuviaFake::paginated([['uuid' => 'd-1'], ['uuid' => 'd-2']], page: 1, lastPage: 3, perPage: 2));

        $response = $this->client()->dossiers()->list();

        $this->assertTrue($response['success']);
        $this->assertSame('d-1', $response['data'][0]['uuid']);
        $this->assertSame(3, $response['meta']['pagination']['last_page']);
        $this->assertNull($response['missing'] ?? null);
        $this->assertTrue(isset($response['data']));
        $this->assertFalse(isset($response['errors']));

        $this->assertSame('d-2', $response->data('1.uuid'));
        $this->assertSame('fallback', $response->data('9.uuid', 'fallback'));
        $this->assertSame(2, $response->meta('pagination.per_page'));
        $this->assertEquals(new Pagination(1, 3, 2, 6), $response->pagination());
        $this->assertTrue($response->hasMorePages());
        $this->assertTrue($response->successful());
        $this->assertSame(200, $response->status());
        $this->assertSame([], $response->errors());
        $this->assertNull($response->code());
        $this->assertSame(['success', 'message', 'data', 'meta', 'errors'], array_keys($response->toArray()));
        $this->assertSame($response->toArray(), iterator_to_array($response));
        $this->assertSame(json_encode($response->toArray()), json_encode($response));
        $this->assertSame(200, $response->response()->status());
        $this->assertSame('application/json', $response->header('Content-Type'));
    }

    #[Test]
    public function the_response_is_read_only(): void
    {
        Http::fake(fn () => PreuviaFake::ok(['uuid' => 'd-1']));

        $response = $this->client()->me();

        $this->expectException(LogicException::class);

        $response['data'] = [];
    }

    /**
     * @return iterable<string, array{int, string, class-string<PreuviaException>, bool}>
     */
    public static function errors(): iterable
    {
        yield '401' => [401, 'invalid_api_key', AuthenticationException::class, false];
        yield '402' => [402, 'insufficient_tokens', InsufficientTokensException::class, false];
        yield '403' => [403, 'missing_api_scope', AuthorizationException::class, false];
        yield '404' => [404, 'not_found', NotFoundException::class, false];
        yield '409' => [409, 'template_snapshot_diverged', ConflictException::class, false];
        yield '422' => [422, 'validation_failed', ValidationException::class, false];
        yield '429' => [429, 'too_many_requests', RateLimitException::class, true];
        yield '400' => [400, 'bad_request', RequestException::class, false];
        yield '502' => [502, 'artifact_generation_failed', ServerException::class, true];
        yield '503' => [503, 'document_storage_unavailable', ServerException::class, true];
    }

    /**
     * @param  class-string<PreuviaException>  $class
     */
    #[Test]
    #[DataProvider('errors')]
    public function each_error_status_maps_to_its_exception(int $status, string $code, string $class, bool $temporary): void
    {
        Http::fake(fn () => PreuviaFake::error($code, 'Refusé.', $status, ['name' => ['Le nom est requis.']], ['Retry-After' => '30']));

        try {
            $this->client()->post('dossiers', ['template_uuid' => 't-1']);
            $this->fail("A {$class} was expected.");
        } catch (PreuviaException $exception) {
            $this->assertInstanceOf($class, $exception);
            $this->assertSame("Preuvia API POST /dossiers failed ({$status} {$code}): Refusé.", $exception->getMessage());
            $this->assertSame('POST', $exception->method());
            $this->assertSame('/dossiers', $exception->endpoint());
            $this->assertSame($status, $exception->status());
            $this->assertSame($code, $exception->errorCode());
            $this->assertSame(['name' => ['Le nom est requis.']], $exception->errors());
            $this->assertSame(30, $exception->retryAfter());
            $this->assertSame($temporary, $exception->isTemporary());
            $this->assertFalse($exception->response()['success']);
            $this->assertStringNotContainsString('preuvia_test_key', $exception->getMessage());
        }

        // POST is never retried, whatever the status.
        Http::assertSentCount(1);
    }

    #[Test]
    public function an_error_without_envelope_still_maps_on_the_status(): void
    {
        Http::fake(fn () => Http::response('<html>Bad Gateway</html>', 502, ['Content-Type' => 'text/html']));

        try {
            $this->client()->withoutRetries()->me();
            $this->fail('A ServerException was expected.');
        } catch (ServerException $exception) {
            $this->assertSame('Preuvia API GET /me failed (502).', $exception->getMessage());
            $this->assertNull($exception->response());
            $this->assertNull($exception->errorCode());
            $this->assertSame([], $exception->errors());
        }
    }

    #[Test]
    public function a_successful_status_with_success_false_throws(): void
    {
        Http::fake(fn () => Http::response(['success' => false, 'message' => 'Nope', 'data' => null, 'meta' => [], 'errors' => null]));

        try {
            $this->client()->me();
            $this->fail('A RequestException was expected.');
        } catch (RequestException $exception) {
            $this->assertSame(200, $exception->status());
            $this->assertSame('Preuvia API GET /me failed (200 success: false): Nope', $exception->getMessage());
        }
    }

    #[Test]
    public function a_non_json_body_throws_an_invalid_response_exception(): void
    {
        Http::fake(fn () => Http::response('<html>maintenance</html>', 200, ['Content-Type' => 'text/html']));

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('Preuvia API GET /me returned an invalid JSON response (200).');

        $this->client()->me();
    }

    #[Test]
    public function a_get_is_retried_after_a_503_then_succeeds(): void
    {
        Sleep::fake();
        Http::fakeSequence()
            ->push(['success' => false, 'message' => null, 'data' => null, 'meta' => ['code' => 'service_unavailable'], 'errors' => null], 503)
            ->push(['success' => true, 'message' => null, 'data' => ['uuid' => 'd-1'], 'meta' => [], 'errors' => null]);

        $response = $this->client()->dossiers()->show('d-1');

        $this->assertSame('d-1', $response['data']['uuid']);
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(500)->milliseconds()]);
    }

    #[Test]
    public function the_backoff_grows_with_each_attempt_then_gives_up(): void
    {
        Sleep::fake();
        Http::fake(fn () => PreuviaFake::error('bad_gateway', null, 502));

        $this->expectException(ServerException::class);

        try {
            $this->client()->withRetries(3)->me();
        } finally {
            Http::assertSentCount(4);
            Sleep::assertSequence([
                Sleep::for(500)->milliseconds(),
                Sleep::for(1000)->milliseconds(),
                Sleep::for(1500)->milliseconds(),
            ]);
        }
    }

    #[Test]
    public function a_connection_failure_is_retried_for_a_get(): void
    {
        Sleep::fake();
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts) {
            return ++$attempts === 1
                ? Create::rejectionFor(new ConnectException('cURL error 28: timed out', $request->toPsrRequest()))
                : PreuviaFake::ok(['ok' => true]);
        });

        $this->assertTrue($this->client()->tokens()->balance()->data('ok'));
        $this->assertSame(2, $attempts);
    }

    #[Test]
    public function a_connection_failure_throws_a_temporary_connection_exception(): void
    {
        Sleep::fake();
        Http::fake(fn (Request $request) => Create::rejectionFor(new ConnectException('cURL error 7: refused', $request->toPsrRequest())));

        try {
            $this->client()->dossiers()->create(['template_uuid' => 't-1']);
            $this->fail('A ConnectionException was expected.');
        } catch (ConnectionException $exception) {
            $this->assertTrue($exception->isTemporary());
            $this->assertNull($exception->status());
            $this->assertStringStartsWith('Preuvia API POST /dossiers failed (connection error): cURL error 7', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function a_post_is_not_retried_after_a_503(): void
    {
        Sleep::fake();
        Http::fake(fn () => PreuviaFake::error('service_unavailable', null, 503));

        $this->expectException(ServerException::class);

        try {
            $this->client()->dossiers()->create(['template_uuid' => 't-1']);
        } finally {
            Http::assertSentCount(1);
            Sleep::assertNeverSlept();
        }
    }

    #[Test]
    public function retry_after_is_honoured(): void
    {
        Sleep::fake();
        Http::fakeSequence()
            ->pushResponse(PreuviaFake::error('too_many_requests', null, 429, [], ['Retry-After' => '3']))
            ->pushResponse(PreuviaFake::ok(['uuid' => 'u-1']));

        $this->assertSame('u-1', $this->client()->users()->show('u-1')->data('uuid'));

        Sleep::assertSequence([Sleep::for(3)->seconds()]);
    }

    #[Test]
    public function a_retry_after_longer_than_the_limit_is_not_waited(): void
    {
        Sleep::fake();
        Http::fake(fn () => PreuviaFake::error('too_many_requests', 'Slow down', 429, [], ['Retry-After' => '60']));

        try {
            $this->client()->users()->list();
            $this->fail('A RateLimitException was expected.');
        } catch (RateLimitException $exception) {
            $this->assertSame(60, $exception->retryAfter());
            $this->assertTrue($exception->isTemporary());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function health_returns_a_503_instead_of_throwing(): void
    {
        $this->app['config']->set('preuvia.api_key', null);
        $this->app['config']->set('preuvia.health_token', 'ops-token');
        $this->app->forgetInstance(Preuvia::class);
        Sleep::fake();
        Http::fake(fn () => Http::response([
            'success' => false,
            'message' => 'Degraded',
            'data' => ['database' => 'ok', 'storage' => 'down'],
            'meta' => [],
            'errors' => null,
        ], 503));

        $health = $this->client()->health();

        $this->assertFalse($health->successful());
        $this->assertSame(503, $health->status());
        $this->assertSame('down', $health->data('storage'));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/health'
            && ! $request->hasHeader('Authorization')
            && $request->hasHeader('X-Health-Token', 'ops-token'));
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function health_reports_success(): void
    {
        Http::fake(fn () => PreuviaFake::ok(['status' => 'ok']));

        $health = $this->client()->health();

        $this->assertTrue($health->successful());
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Health-Token'));
    }

    #[Test]
    public function health_still_throws_on_a_transport_failure(): void
    {
        Http::fake(fn (Request $request) => Create::rejectionFor(new ConnectException('cURL error 6', $request->toPsrRequest())));

        $this->expectException(ConnectionException::class);

        $this->client()->health();
    }
}
