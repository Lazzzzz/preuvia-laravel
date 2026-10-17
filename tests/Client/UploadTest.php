<?php

namespace Preuvia\Laravel\Tests\Client;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Preuvia\Laravel\Exceptions\ConnectionException;
use Preuvia\Laravel\Exceptions\FileException;
use Preuvia\Laravel\Exceptions\ServerException;
use Preuvia\Laravel\Preuvia;
use Preuvia\Laravel\Testing\PreuviaFake;
use Preuvia\Laravel\Tests\TestCase;
use SplFileInfo;

class UploadTest extends TestCase
{
    private const CONTENTS = "\xFF\xD8\xFF proof photo bytes";

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'preuvia').'.jpg';
        file_put_contents($this->path, self::CONTENTS);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function client(): Preuvia
    {
        return $this->app->make(Preuvia::class);
    }

    /**
     * @return array<string, string>
     */
    private static function fields(Request $request): array
    {
        $fields = [];

        foreach ($request->data() as $part) {
            if (! isset($part['filename'])) {
                $fields[$part['name']] = (string) $part['contents'];
            }
        }

        return $fields;
    }

    #[Test]
    public function it_sends_the_multipart_fields(): void
    {
        Http::fake(fn () => PreuviaFake::created(['uuid' => 'doc-1']));

        $response = $this->client()->documents()->upload('d-1', $this->path, 2, 0, [
            'captured_at' => new DateTimeImmutable('2026-10-17 09:30:00', new DateTimeZone('Europe/Paris')),
            'client_capture_id' => 'capture-42',
            'device' => ['id' => 'dev-1', 'platform' => 'ios', 'os_version' => '26.1'],
        ]);

        $this->assertSame(201, $response->status());
        $this->assertSame('doc-1', $response['data']['uuid']);

        Http::assertSent(function (Request $request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://preuvia.test/api/v1/dossiers/d-1/documents', $request->url());
            $this->assertTrue($request->isMultipart());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer preuvia_test_key'));
            $this->assertTrue($request->hasFile('file', null, basename($this->path)));
            $this->assertStringContainsString(self::CONTENTS, $request->body());
            $this->assertSame([
                'client_capture_id' => 'capture-42',
                'device[id]' => 'dev-1',
                'device[platform]' => 'ios',
                'device[os_version]' => '26.1',
                'step_index' => '2',
                'proof_index' => '0',
                'captured_at' => '2026-10-17T09:30:00+02:00',
                'client_sha256' => hash('sha256', self::CONTENTS),
            ], self::fields($request));

            return true;
        });
    }

    #[Test]
    public function the_hash_can_be_given_disabled_or_turned_off_in_config(): void
    {
        Http::fake(fn () => PreuviaFake::created());

        $documents = $this->client()->documents();
        $documents->upload('d-1', $this->path, 0, 0, ['client_sha256' => str_repeat('A', 64), 'filename' => 'facade.jpg']);
        $documents->upload('d-1', $this->path, 0, 0, ['client_sha256' => false, 'captured_at' => '2026-10-17T07:30:00Z']);

        $this->app['config']->set('preuvia.documents.send_hash', false);
        $this->app->forgetInstance(Preuvia::class);
        $this->client()->documents()->upload('d-1', $this->path, 0, 0);

        $recorded = Http::recorded()->map(fn (array $pair) => self::fields($pair[0]))->all();

        $this->assertSame(str_repeat('a', 64), $recorded[0]['client_sha256']);
        $this->assertTrue(Http::recorded()[0][0]->hasFile('file', null, 'facade.jpg'));
        $this->assertArrayNotHasKey('client_sha256', $recorded[1]);
        $this->assertSame('2026-10-17T07:30:00Z', $recorded[1]['captured_at']);
        $this->assertArrayNotHasKey('client_sha256', $recorded[2]);
    }

    #[Test]
    public function it_accepts_a_file_object_and_keeps_the_client_name(): void
    {
        Http::fake(fn () => PreuviaFake::created());

        $this->client()->documents()->upload('d-1', new SplFileInfo($this->path), 0, 1);
        $this->client()->documents()->upload('d-1', new UploadedFile($this->path, 'Façade nord.jpg', 'image/jpeg', null, true), 0, 1);

        $this->assertTrue(Http::recorded()[0][0]->hasFile('file', null, basename($this->path)));
        $this->assertTrue(Http::recorded()[1][0]->hasFile('file', null, 'Façade nord.jpg'));
    }

    #[Test]
    public function a_stream_is_hashed_rewound_and_left_open(): void
    {
        Http::fake(fn () => PreuviaFake::created());

        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, self::CONTENTS);

        $this->client()->documents()->upload('d-1', $stream, 1, 1, ['filename' => 'photo.jpg']);

        Http::assertSent(function (Request $request) {
            return self::fields($request)['client_sha256'] === hash('sha256', self::CONTENTS)
                && $request->hasFile('file', null, 'photo.jpg')
                && str_contains($request->body(), self::CONTENTS);
        });

        $this->assertTrue(is_resource($stream), 'The caller keeps ownership of its stream.');
        fclose($stream);
    }

    #[Test]
    public function an_upload_with_a_capture_id_is_retried_with_the_full_file(): void
    {
        Sleep::fake();
        $bodies = [];
        Http::fake(function (Request $request) use (&$bodies) {
            $bodies[] = $request->body();
            // Like a transport that gave up mid-way: the next attempt must not reuse this body.
            $request->toPsrRequest()->getBody()->close();

            return count($bodies) === 1
                ? PreuviaFake::error('document_storage_unavailable', null, 503, [], ['Retry-After' => '2'])
                : PreuviaFake::ok(['uuid' => 'doc-1']);
        });

        $response = $this->client()->documents()->upload('d-1', $this->path, 0, 0, ['client_capture_id' => 'capture-1']);

        $this->assertSame('doc-1', $response->data('uuid'));
        $this->assertCount(2, $bodies);
        $this->assertStringContainsString(self::CONTENTS, $bodies[0]);
        $this->assertStringContainsString(self::CONTENTS, $bodies[1]);
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    }

    #[Test]
    public function a_stream_upload_with_a_capture_id_is_replayed_from_the_start(): void
    {
        Sleep::fake();
        $bodies = [];
        Http::fake(function (Request $request) use (&$bodies) {
            $bodies[] = $request->body();
            // Like a transport that gave up mid-way: the next attempt must not reuse this body.
            $request->toPsrRequest()->getBody()->close();

            return count($bodies) === 1
                ? Create::rejectionFor(new ConnectException('cURL error 56: reset', $request->toPsrRequest()))
                : PreuviaFake::ok(['uuid' => 'doc-1']);
        });

        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, self::CONTENTS);

        $this->client()->documents()->upload('d-1', $stream, 0, 0, ['client_capture_id' => 'capture-1']);

        $this->assertCount(2, $bodies);
        $this->assertStringContainsString(self::CONTENTS, $bodies[1]);
        $this->assertTrue(is_resource($stream), 'Closing the request body must not close the caller stream.');
        fclose($stream);
    }

    #[Test]
    public function an_upload_without_capture_id_is_not_retried(): void
    {
        Sleep::fake();
        Http::fake(fn () => PreuviaFake::error('document_storage_unavailable', null, 503));

        $this->expectException(ServerException::class);

        try {
            $this->client()->documents()->upload('d-1', $this->path, 0, 0);
        } finally {
            Http::assertSentCount(1);
            Sleep::assertNeverSlept();
        }
    }

    #[Test]
    public function a_non_seekable_stream_is_sent_once_without_hash(): void
    {
        Sleep::fake();
        Http::fake(fn (Request $request) => Create::rejectionFor(new ConnectException('cURL error 7', $request->toPsrRequest())));

        $process = popen('printf proof', 'r');

        try {
            $this->client()->documents()->upload('d-1', $process, 0, 0, ['client_capture_id' => 'capture-1']);
            $this->fail('A ConnectionException was expected.');
        } catch (ConnectionException) {
            Http::assertSentCount(1);
            $this->assertArrayNotHasKey('client_sha256', self::fields(Http::recorded()[0][0]));
        } finally {
            pclose($process);
        }
    }

    #[Test]
    public function an_unreadable_file_fails_before_any_request(): void
    {
        Http::fake();

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('/missing/proof.jpg');

        try {
            $this->client()->documents()->upload('d-1', '/missing/proof.jpg', 0, 0);
        } finally {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function an_unsupported_file_argument_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client()->documents()->upload('d-1', 42, 0, 0);
    }
}
