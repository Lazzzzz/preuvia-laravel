<?php

namespace Preuvia\Laravel\Http;

use ArrayAccess;
use ArrayIterator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\Response;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

/**
 * Decoded JSON envelope of a Preuvia answer. Reads like the raw array
 * (`$response['data']['uuid']`) and offers typed helpers on top.
 *
 * @implements ArrayAccess<string, mixed>
 * @implements Arrayable<string, mixed>
 * @implements IteratorAggregate<string, mixed>
 */
class PreuviaResponse implements Arrayable, ArrayAccess, IteratorAggregate, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(
        protected Response $response,
        protected array $envelope,
    ) {}

    public function status(): int
    {
        return $this->response->status();
    }

    /**
     * 2xx status and an envelope that does not report `success: false`.
     */
    public function successful(): bool
    {
        return $this->response->successful() && ($this->envelope['success'] ?? true) !== false;
    }

    public function message(): ?string
    {
        $message = $this->envelope['message'] ?? null;

        return is_string($message) ? $message : null;
    }

    /**
     * The `data` member, or one of its keys in dot notation.
     */
    public function data(?string $key = null, mixed $default = null): mixed
    {
        return data_get($this->envelope['data'] ?? null, $key, $default);
    }

    /**
     * The `meta` member, or one of its keys in dot notation.
     */
    public function meta(?string $key = null, mixed $default = null): mixed
    {
        return data_get($this->envelope['meta'] ?? [], $key, $default);
    }

    /**
     * Machine-readable code (`meta.code`).
     */
    public function code(): ?string
    {
        $code = $this->meta('code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function errors(): array
    {
        $errors = $this->envelope['errors'] ?? null;

        return is_array($errors) ? $errors : [];
    }

    public function pagination(): ?Pagination
    {
        $pagination = $this->meta('pagination');

        return is_array($pagination) ? Pagination::fromArray($pagination) : null;
    }

    public function hasMorePages(): bool
    {
        return $this->pagination()?->hasMorePages() ?? false;
    }

    public function header(string $header): string
    {
        return $this->response->header($header);
    }

    public function response(): Response
    {
        return $this->response;
    }

    public function toResponse(): Response
    {
        return $this->response;
    }

    /**
     * The full decoded envelope: success, message, data, meta, errors.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->envelope;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->envelope;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->envelope);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->envelope[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->envelope[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('A Preuvia response is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('A Preuvia response is read-only.');
    }
}
