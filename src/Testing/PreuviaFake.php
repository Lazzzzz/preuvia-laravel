<?php

namespace Preuvia\Laravel\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Builders of fake Preuvia answers for Http::fake() and Preuvia::fake().
 */
class PreuviaFake
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(mixed $data = null, ?string $message = null, array $meta = [], int $status = 200): PromiseInterface
    {
        return Http::response(static::envelope(true, $message, $data, $meta), $status);
    }

    public static function created(mixed $data = null, ?string $message = null): PromiseInterface
    {
        return static::ok($data, $message, [], 201);
    }

    /**
     * @param  list<mixed>  $items
     */
    public static function paginated(array $items, int $page = 1, int $lastPage = 1, int $perPage = 25, ?int $total = null): PromiseInterface
    {
        $total ??= $page >= $lastPage
            ? ($lastPage - 1) * $perPage + count($items)
            : $lastPage * $perPage;

        return static::ok(array_values($items), null, [
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, string>  $headers
     */
    public static function error(string $code, ?string $message = null, int $status = 400, array $errors = [], array $headers = []): PromiseInterface
    {
        return Http::response(
            static::envelope(false, $message, null, ['code' => $code], $errors === [] ? null : $errors),
            $status,
            $headers,
        );
    }

    /**
     * @param  array<string, list<string>|string>  $errors
     */
    public static function validation(array $errors): PromiseInterface
    {
        return static::error('validation_failed', 'The given data was invalid.', 422, $errors);
    }

    public static function notFound(): PromiseInterface
    {
        return static::error('not_found', 'Resource not found.', 404);
    }

    public static function file(string $contents, string $mimeType = 'application/pdf', ?string $filename = null): PromiseInterface
    {
        $headers = ['Content-Type' => $mimeType];

        if ($filename !== null) {
            $fallback = str_replace(['"', '\\'], '', Str::ascii($filename));
            $headers['Content-Disposition'] = $fallback === $filename
                ? "attachment; filename=\"{$filename}\""
                : "attachment; filename=\"{$fallback}\"; filename*=UTF-8''".rawurlencode($filename);
        }

        return Http::response($contents, 200, $headers);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $errors
     * @return array{success: bool, message: ?string, data: mixed, meta: array<string, mixed>, errors: ?array<string, mixed>}
     */
    protected static function envelope(bool $success, ?string $message, mixed $data, array $meta, ?array $errors = null): array
    {
        return [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
            'errors' => $errors,
        ];
    }
}
