<?php

namespace Preuvia\Laravel\Tests\Client;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Preuvia\Laravel\Exceptions\AuthorizationException;
use Preuvia\Laravel\Exceptions\RequestException;
use Preuvia\Laravel\Http\Download;
use Preuvia\Laravel\Preuvia;
use Preuvia\Laravel\Testing\PreuviaFake;
use Preuvia\Laravel\Tests\TestCase;

class DownloadTest extends TestCase
{
    private function client(): Preuvia
    {
        return $this->app->make(Preuvia::class);
    }

    #[Test]
    public function it_wraps_a_binary_answer(): void
    {
        Http::fake(fn () => PreuviaFake::file('%PDF-1.7 signed', 'application/pdf', 'signed.pdf'));

        $download = $this->client()->documents()->downloadSignedPdf('d-1', 'doc-1');

        $this->assertSame('%PDF-1.7 signed', $download->body());
        $this->assertSame('application/pdf', $download->mimeType());
        $this->assertSame('signed.pdf', $download->filename());
        $this->assertSame(15, $download->size());
        $this->assertSame(200, $download->status());
        $this->assertSame(200, $download->response()->status());

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer preuvia_test_key')
            && $request->hasHeader('Accept', '*/*'));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function dispositions(): iterable
    {
        yield 'quoted' => ['attachment; filename="rapport final.pdf"', 'rapport final.pdf'];
        yield 'unquoted' => ['attachment; filename=photo.jpg', 'photo.jpg'];
        yield 'rfc 5987 wins' => ["attachment; filename=\"Facade.jpg\"; filename*=UTF-8''Fa%C3%A7ade%20nord.jpg", 'Façade nord.jpg'];
        yield 'rfc 5987 only' => ["attachment; filename*=utf-8''%E2%82%AC.pdf", '€.pdf'];
        yield 'escaped quote' => ['attachment; filename="a\"b.pdf"', 'a"b.pdf'];
        yield 'path stripped' => ['attachment; filename="../../etc/passwd"', 'passwd'];
        yield 'inline without name' => ['inline', null];
        yield 'missing' => ['', null];
    }

    #[Test]
    #[DataProvider('dispositions')]
    public function it_parses_the_file_name(string $disposition, ?string $expected): void
    {
        $headers = ['Content-Type' => 'image/jpeg; charset=binary'];

        if ($disposition !== '') {
            $headers['Content-Disposition'] = $disposition;
        }

        Http::fake(fn () => Http::response('bytes', 200, $headers));

        $download = $this->client()->documents()->download('d-1', 'doc-1');

        $this->assertSame($expected, $download->filename());
        $this->assertSame('image/jpeg', $download->mimeType());
    }

    #[Test]
    public function it_saves_the_file_creating_directories(): void
    {
        Http::fake(fn () => PreuviaFake::file('certificate', 'application/pdf', 'eidas.pdf'));

        $directory = sys_get_temp_dir().'/preuvia-'.bin2hex(random_bytes(4));
        $path = $directory.'/nested/eidas.pdf';

        try {
            $download = $this->client()->documents()->downloadEidasCertificate('d-1', 'doc-1');

            $this->assertInstanceOf(Download::class, $download);
            $this->assertSame($path, $download->save($path));
            $this->assertSame('certificate', file_get_contents($path));
        } finally {
            @unlink($path);
            @rmdir($directory.'/nested');
            @rmdir($directory);
        }
    }

    #[Test]
    public function it_stores_the_file_on_a_disk(): void
    {
        Storage::fake('proofs');
        Http::fake(fn () => PreuviaFake::file('jpeg bytes', 'image/jpeg', 'photo.jpg'));

        $path = $this->client()->documents()->download('d-1', 'doc-1')->store('dossiers/d-1/photo.jpg', 'proofs');

        $this->assertSame('dossiers/d-1/photo.jpg', $path);
        Storage::disk('proofs')->assertExists('dossiers/d-1/photo.jpg');
        $this->assertSame('jpeg bytes', Storage::disk('proofs')->get('dossiers/d-1/photo.jpg'));
    }

    #[Test]
    public function a_json_error_on_a_download_throws(): void
    {
        Http::fake(fn () => PreuviaFake::error('forbidden', 'Les téléchargements de documents sont désactivés.', 403));

        try {
            $this->client()->documents()->download('d-1', 'doc-1');
            $this->fail('An AuthorizationException was expected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('forbidden', $exception->errorCode());
            $this->assertSame('/dossiers/d-1/documents/doc-1/download', $exception->endpoint());
        }
    }

    #[Test]
    public function a_success_false_envelope_on_a_download_throws(): void
    {
        Http::fake(fn () => Http::response(['success' => false, 'message' => 'Pas prêt', 'data' => null, 'meta' => [], 'errors' => null]));

        $this->expectException(RequestException::class);

        $this->client()->documents()->downloadSignedPdf('d-1', 'doc-1');
    }

    #[Test]
    public function a_download_is_retried_like_any_read(): void
    {
        Sleep::fake();
        Http::fakeSequence()
            ->pushResponse(PreuviaFake::error('service_unavailable', null, 504))
            ->pushResponse(PreuviaFake::file('pdf'));

        $this->assertSame('pdf', $this->client()->documents()->download('d-1', 'doc-1')->body());
        Http::assertSentCount(2);
    }
}
