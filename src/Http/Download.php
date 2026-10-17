<?php

namespace Preuvia\Laravel\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Preuvia\Laravel\Exceptions\FileException;

/**
 * Successful binary answer: original document, eIDAS certificate or signed PDF.
 */
class Download
{
    public function __construct(
        protected Response $response,
    ) {}

    public function body(): string
    {
        return $this->response->body();
    }

    public function response(): Response
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->status();
    }

    /**
     * Content-Type without its parameters, e.g. `application/pdf`.
     */
    public function mimeType(): ?string
    {
        $type = trim(explode(';', $this->response->header('Content-Type'))[0]);

        return $type === '' ? null : strtolower($type);
    }

    /**
     * File name announced by Content-Disposition; the RFC 5987 `filename*` form wins over `filename`.
     */
    public function filename(): ?string
    {
        $disposition = $this->response->header('Content-Disposition');

        if ($disposition === '') {
            return null;
        }

        if (preg_match('/filename\*\s*=\s*([^\'";]*)\'[^\']*\'("?)([^";]+)\2/i', $disposition, $matches)) {
            $name = rawurldecode($matches[3]);
            $charset = strtoupper(trim($matches[1]));

            if ($charset !== '' && $charset !== 'UTF-8' && function_exists('mb_convert_encoding')) {
                $name = (string) mb_convert_encoding($name, 'UTF-8', $charset);
            }

            return $this->safeName($name);
        }

        if (preg_match('/(?:^|;)\s*filename\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;]+))/i', $disposition, $matches)) {
            $name = isset($matches[2]) && $matches[2] !== ''
                ? trim($matches[2])
                : stripslashes($matches[1]);

            return $this->safeName($name);
        }

        return null;
    }

    public function size(): int
    {
        return strlen($this->body());
    }

    /**
     * Writes the file on the local filesystem, creating missing directories. Returns the path.
     */
    public function save(string $path): string
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw FileException::unwritable($path);
        }

        if (@file_put_contents($path, $this->body()) === false) {
            throw FileException::unwritable($path);
        }

        return $path;
    }

    /**
     * Writes the file on a filesystem disk (default disk when null). Returns the path.
     */
    public function store(string $path, ?string $disk = null): string
    {
        if (Storage::disk($disk)->put($path, $this->body()) === false) {
            throw FileException::unwritable($path, $disk ?? 'default');
        }

        return $path;
    }

    private function safeName(string $name): ?string
    {
        // A header must never steer save() outside the caller's directory.
        $name = basename(str_replace('\\', '/', trim($name)));

        return $name === '' || $name === '.' || $name === '..' ? null : $name;
    }
}
