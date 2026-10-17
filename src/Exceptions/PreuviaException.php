<?php

namespace Preuvia\Laravel\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base of every exception thrown by the SDK: catch this one to handle any Preuvia failure.
 */
abstract class PreuviaException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $response  Decoded JSON envelope, when the API answered one.
     */
    public function __construct(
        string $message,
        protected ?string $method = null,
        protected ?string $endpoint = null,
        protected ?int $status = null,
        protected ?array $response = null,
        protected ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /**
     * Builds the exception matching an HTTP error status.
     *
     * @param  array<string, mixed>|null  $response
     */
    public static function fromResponse(string $method, string $endpoint, int $status, ?array $response, ?int $retryAfter = null): self
    {
        $class = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 402 => InsufficientTokensException::class,
            $status === 403 => AuthorizationException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 422 => ValidationException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => RequestException::class,
        };

        $method = strtoupper($method);
        $code = self::extractCode($response);
        $apiMessage = is_array($response) && is_string($response['message'] ?? null) && $response['message'] !== ''
            ? $response['message']
            : null;

        $message = sprintf(
            'Preuvia API %s %s failed (%s)%s',
            $method,
            $endpoint,
            trim($status.' '.($code ?? '')),
            $apiMessage === null ? '.' : ': '.$apiMessage,
        );

        return new $class($message, $method, $endpoint, $status, $response, $retryAfter);
    }

    public function method(): ?string
    {
        return $this->method;
    }

    public function endpoint(): ?string
    {
        return $this->endpoint;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function response(): ?array
    {
        return $this->response;
    }

    /**
     * Machine-readable error code (`meta.code`), e.g. `validation_failed` or `insufficient_tokens`.
     */
    public function errorCode(): ?string
    {
        return self::extractCode($this->response);
    }

    /**
     * Per-field messages of a validation error (`errors`), empty otherwise.
     *
     * @return array<string, mixed>
     */
    public function errors(): array
    {
        $errors = $this->response['errors'] ?? null;

        return is_array($errors) ? $errors : [];
    }

    /**
     * Seconds to wait before trying again, from the Retry-After header.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Rate limits, server errors and lost connections clear up on their own:
     * the caller should retry later instead of recording a final failure.
     */
    public function isTemporary(): bool
    {
        return $this->status !== null && ($this->status === 429 || $this->status >= 500);
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    private static function extractCode(?array $response): ?string
    {
        $code = $response['meta']['code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }
}
