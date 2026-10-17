<?php

namespace Preuvia\Laravel\Resources;

use Closure;
use DateTimeInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Preuvia\Laravel\Exceptions\FileException;
use Preuvia\Laravel\Http\Download;
use Preuvia\Laravel\Http\PreuviaResponse;
use Preuvia\Laravel\Http\ResourceStream;
use Psr\Http\Message\StreamInterface;
use SplFileInfo;

/**
 * Proof documents of a dossier, their Datasure certification, eIDAS certificate and signed PDF.
 */
class Documents extends Resource
{
    /**
     * GET /dossiers/{uuid}/documents
     *
     * @param  array{page?: int, per_page?: int}  $query
     */
    public function list(string $dossierUuid, array $query = []): PreuviaResponse
    {
        return $this->request('GET', $this->path('dossiers', $dossierUuid, 'documents'), $query);
    }

    /**
     * @param  array{page?: int, per_page?: int}  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(string $dossierUuid, array $query = []): LazyCollection
    {
        return $this->paginate($this->path('dossiers', $dossierUuid, 'documents'), $query);
    }

    /**
     * POST /dossiers/{uuid}/documents (multipart) and queues its Datasure certification.
     *
     * Sending a client_capture_id makes the upload idempotent: Preuvia answers 200 with the stored
     * document on a repeat, so only then is the upload retried after a lost connection or a 503.
     *
     * @param  string|SplFileInfo|resource  $file  Local path, file object or readable stream.
     * @param  array{
     *     filename?: string,
     *     captured_at?: DateTimeInterface|string|null,
     *     client_capture_id?: ?string,
     *     client_sha256?: string|false|null,
     *     device?: array{id?: string, name?: string, model?: string, platform?: string, os_version?: string, app_version?: string},
     * }  $options  client_sha256 is computed from the file when omitted and documents.send_hash is on; false skips it.
     */
    public function upload(string $dossierUuid, mixed $file, int $stepIndex, int $proofIndex, array $options = []): PreuviaResponse
    {
        [$open, $filename, $hash, $replayable] = $this->source($file, $options);

        $sha256 = $options['client_sha256'] ?? null;

        if ($sha256 === null && $this->transport->option('send_hash')) {
            $sha256 = $hash();
        }

        $capturedAt = $options['captured_at'] ?? null;

        $fields = $this->multipartFields(array_replace(
            array_diff_key($options, array_flip(['filename', 'client_sha256', 'captured_at'])),
            [
                'step_index' => $stepIndex,
                'proof_index' => $proofIndex,
                'captured_at' => $capturedAt instanceof DateTimeInterface
                    ? $capturedAt->format(DateTimeInterface::ATOM)
                    : $capturedAt,
                'client_sha256' => is_string($sha256) && $sha256 !== '' ? strtolower($sha256) : null,
            ],
        ));

        $idempotent = $replayable && filled($options['client_capture_id'] ?? null);

        return $this->transport->multipart(
            $this->path('dossiers', $dossierUuid, 'documents'),
            fn (PendingRequest $request, string $url): Response => $request
                ->attach('file', $open(), $filename)
                ->post($url, $fields),
            $idempotent,
        );
    }

    public function show(string $dossierUuid, string $documentUuid): PreuviaResponse
    {
        return $this->request('GET', $this->path('dossiers', $dossierUuid, 'documents', $documentUuid));
    }

    /**
     * Original file, or with $preview the copy stamped with the location and time banner when available.
     */
    public function download(string $dossierUuid, string $documentUuid, bool $preview = false): Download
    {
        return $this->transport->download(
            $this->path('dossiers', $dossierUuid, 'documents', $documentUuid, 'download'),
            $preview ? ['preview' => 1] : [],
        );
    }

    public function artifacts(string $dossierUuid, string $documentUuid): PreuviaResponse
    {
        return $this->request('GET', $this->path('dossiers', $dossierUuid, 'documents', $documentUuid, 'artifacts'));
    }

    /**
     * POST .../certifications: queues a new Datasure certification of the document.
     */
    public function certify(string $dossierUuid, string $documentUuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('dossiers', $dossierUuid, 'documents', $documentUuid, 'certifications'));
    }

    /**
     * POST .../eidas-certificate: spends tokens (402 insufficient_tokens).
     */
    public function generateEidasCertificate(string $dossierUuid, string $documentUuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('dossiers', $dossierUuid, 'documents', $documentUuid, 'eidas-certificate'));
    }

    public function downloadEidasCertificate(string $dossierUuid, string $documentUuid): Download
    {
        return $this->transport->download($this->path('dossiers', $dossierUuid, 'documents', $documentUuid, 'eidas-certificate'));
    }

    /**
     * POST .../sign-pdf: spends tokens (402 insufficient_tokens).
     */
    public function signPdf(string $dossierUuid, string $documentUuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('dossiers', $dossierUuid, 'documents', $documentUuid, 'sign-pdf'));
    }

    public function downloadSignedPdf(string $dossierUuid, string $documentUuid): Download
    {
        return $this->transport->download($this->path('dossiers', $dossierUuid, 'documents', $documentUuid, 'signed-pdf'));
    }

    /**
     * Opener called on every attempt, file name, lazy SHA-256, and whether the body can be sent again.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: Closure(): StreamInterface, 1: string, 2: Closure(): ?string, 3: bool}
     */
    protected function source(mixed $file, array $options): array
    {
        if (is_resource($file)) {
            if (get_resource_type($file) !== 'stream') {
                throw new InvalidArgumentException('The upload resource must be a stream.');
            }

            $meta = stream_get_meta_data($file);
            $seekable = (bool) $meta['seekable'];
            $uri = (string) ($meta['uri'] ?? '');
            $filename = (string) ($options['filename'] ?? ($uri !== '' && is_file($uri) ? basename($uri) : 'upload'));

            $open = function () use ($file, $seekable): StreamInterface {
                if ($seekable) {
                    rewind($file);
                }

                return new ResourceStream($file);
            };

            $hash = function () use ($file, $seekable): ?string {
                // A pipe or socket would be consumed by hashing: send it without client_sha256.
                if (! $seekable) {
                    return null;
                }

                rewind($file);
                $context = hash_init('sha256');
                hash_update_stream($context, $file);
                rewind($file);

                return hash_final($context);
            };

            return [$open, $filename, $hash, $seekable];
        }

        if ($file instanceof SplFileInfo) {
            $path = $file->getPathname();
            $filename = method_exists($file, 'getClientOriginalName') && filled($file->getClientOriginalName())
                ? (string) $file->getClientOriginalName()
                : $file->getFilename();
        } elseif (is_string($file) && $file !== '') {
            $path = $file;
            $filename = basename($file);
        } else {
            throw new InvalidArgumentException('The upload file must be a path, an SplFileInfo or a stream resource.');
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw FileException::unreadable($path);
        }

        $open = function () use ($path): StreamInterface {
            $handle = @fopen($path, 'rb');

            if ($handle === false) {
                throw FileException::unreadable($path);
            }

            // Owned by the stream: closed once the request is done with it.
            return Utils::streamFor($handle);
        };

        $hash = function () use ($path): string {
            $hash = @hash_file('sha256', $path);

            if ($hash === false) {
                throw FileException::unreadable($path);
            }

            return $hash;
        };

        return [$open, (string) ($options['filename'] ?? $filename), $hash, true];
    }

    /**
     * Flattens nested arrays to `device[id]` style names; nulls are left out, booleans become 1/0.
     *
     * @param  array<string|int, mixed>  $payload
     * @return list<array{name: string, contents: string}>
     */
    protected function multipartFields(array $payload, ?string $prefix = null): array
    {
        $fields = [];

        foreach ($payload as $key => $value) {
            $name = $prefix === null ? (string) $key : "{$prefix}[{$key}]";

            if (is_array($value)) {
                array_push($fields, ...$this->multipartFields($value, $name));

                continue;
            }

            if ($value === null) {
                continue;
            }

            $fields[] = [
                'name' => $name,
                'contents' => match (true) {
                    is_bool($value) => $value ? '1' : '0',
                    $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
                    default => (string) $value,
                },
            ];
        }

        return $fields;
    }
}
