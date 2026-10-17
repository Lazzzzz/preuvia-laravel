<?php

namespace Preuvia\Laravel\Http;

use Closure;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException as HttpRequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Preuvia\Laravel\Exceptions\ConfigurationException;
use Preuvia\Laravel\Exceptions\ConnectionException;
use Preuvia\Laravel\Exceptions\InvalidResponseException;
use Preuvia\Laravel\Exceptions\PreuviaException;
use Preuvia\Laravel\Exceptions\RequestException;
use Preuvia\Laravel\Preuvia;

/**
 * Sends requests to the Preuvia API: authentication, timeouts, retries, envelope decoding and error mapping.
 *
 * @internal
 *
 * @phpstan-type Options array{
 *     base_url: string,
 *     api_key: string,
 *     health_token: ?string,
 *     timeout: int,
 *     connect_timeout: int,
 *     retry_times: int,
 *     retry_sleep_ms: int,
 *     max_retry_after: int,
 *     send_hash: bool,
 * }
 */
class Transport
{
    public const DEFAULTS = [
        'base_url' => 'https://preuvia.eliteinspection.fr/api/v1',
        'api_key' => '',
        'health_token' => null,
        'timeout' => 30,
        'connect_timeout' => 10,
        'retry_times' => 2,
        'retry_sleep_ms' => 500,
        'max_retry_after' => 10,
        'send_hash' => true,
    ];

    /** Statuses worth another attempt: rate limit and transient gateway or storage failures. */
    private const RETRYABLE_STATUSES = [429, 502, 503, 504];

    /** @var Options */
    protected array $options;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        protected HttpFactory $http,
        array $options = [],
    ) {
        $options = array_replace(self::DEFAULTS, $options);

        $this->options = [
            'base_url' => rtrim(trim((string) $options['base_url']), '/'),
            'api_key' => trim((string) $options['api_key']),
            'health_token' => blank($options['health_token']) ? null : (string) $options['health_token'],
            'timeout' => max(1, (int) $options['timeout']),
            'connect_timeout' => max(1, (int) $options['connect_timeout']),
            'retry_times' => max(0, (int) $options['retry_times']),
            'retry_sleep_ms' => max(0, (int) $options['retry_sleep_ms']),
            'max_retry_after' => max(0, (int) $options['max_retry_after']),
            'send_hash' => (bool) $options['send_hash'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): static
    {
        return new static($this->http, array_replace($this->options, $overrides));
    }

    public function option(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Joins path segments, each one URL-encoded: `path('dossiers', $uuid)` → `/dossiers/<uuid>`.
     */
    public static function path(string ...$segments): string
    {
        return '/'.implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * JSON request. GETs are retried; other methods only when $idempotent is true.
     *
     * @param  array<string, mixed>  $data  Query string for GET, JSON body otherwise.
     */
    public function request(string $method, string $endpoint, array $data = [], ?bool $idempotent = null): PreuviaResponse
    {
        $method = strtoupper($method);
        $endpoint = $this->normalizeEndpoint($endpoint);

        $response = $this->send(
            $method,
            $endpoint,
            fn (PendingRequest $request, string $url): Response => $method === 'GET'
                ? $request->get($url, $data)
                : $request->asJson()->send($method, $url, ['json' => $data]),
            $idempotent ?? $method === 'GET',
        );

        return $this->decode($method, $endpoint, $response);
    }

    /**
     * Multipart request. $dispatch receives a fresh request on every attempt, so it can reopen its file.
     *
     * @param  Closure(PendingRequest, string): Response  $dispatch
     */
    public function multipart(string $endpoint, Closure $dispatch, bool $idempotent): PreuviaResponse
    {
        $endpoint = $this->normalizeEndpoint($endpoint);

        return $this->decode('POST', $endpoint, $this->send('POST', $endpoint, $dispatch, $idempotent));
    }

    /**
     * Binary GET: a Download on success, the mapped exception on a JSON error envelope.
     *
     * @param  array<string, mixed>  $query
     */
    public function download(string $endpoint, array $query = []): Download
    {
        $endpoint = $this->normalizeEndpoint($endpoint);

        $response = $this->send(
            'GET',
            $endpoint,
            fn (PendingRequest $request, string $url): Response => $request->get($url, $query),
            true,
            json: false,
        );

        if ($response->failed()) {
            throw $this->exceptionFor('GET', $endpoint, $response);
        }

        $decoded = $this->isJson($response) ? $response->json() : null;

        if (is_array($decoded) && ($decoded['success'] ?? null) === false) {
            throw RequestException::unsuccessful('GET', $endpoint, $response->status(), $decoded);
        }

        return new Download($response);
    }

    /**
     * GET /health: unauthenticated, not retried, and a 503 (dependency down) is returned, not thrown.
     */
    public function health(): PreuviaResponse
    {
        $headers = $this->options['health_token'] === null ? [] : ['X-Health-Token' => $this->options['health_token']];

        $response = $this->send(
            'GET',
            '/health',
            fn (PendingRequest $request, string $url): Response => $request->withHeaders($headers)->get($url),
            false,
            authenticated: false,
        );

        $decoded = $response->json();

        if (in_array($response->status(), [200, 503], true) && is_array($decoded)) {
            return new PreuviaResponse($response, $decoded);
        }

        return $this->decode('GET', '/health', $response);
    }

    /**
     * @param  Closure(PendingRequest, string): Response  $dispatch
     */
    protected function send(
        string $method,
        string $endpoint,
        Closure $dispatch,
        bool $retryable,
        bool $authenticated = true,
        bool $json = true,
    ): Response {
        if ($this->options['base_url'] === '') {
            throw ConfigurationException::missingBaseUrl();
        }

        if ($authenticated && $this->options['api_key'] === '') {
            throw ConfigurationException::missingApiKey();
        }

        $url = $this->options['base_url'].$endpoint;
        $maxAttempts = $retryable ? $this->options['retry_times'] + 1 : 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $dispatch($this->pendingRequest($authenticated, $json), $url);
            } catch (HttpRequestException $exception) {
                // Only reached when the app turned on global throwing: handle the answer ourselves.
                $response = $exception->response;
            } catch (HttpConnectionException $exception) {
                if ($attempt >= $maxAttempts) {
                    throw ConnectionException::fromTransport($method, $endpoint, $exception);
                }

                $this->pause($attempt, null);

                continue;
            }

            if ($attempt >= $maxAttempts || ! in_array($response->status(), self::RETRYABLE_STATUSES, true)) {
                return $response;
            }

            $retryAfter = $this->retryAfter($response);

            // Waiting that long would block the caller: hand the error back now.
            if ($retryAfter !== null && $retryAfter > $this->options['max_retry_after']) {
                return $response;
            }

            $this->pause($attempt, $retryAfter);
        }
    }

    protected function pendingRequest(bool $authenticated, bool $json): PendingRequest
    {
        $request = $this->http
            ->withHeaders([
                'User-Agent' => 'preuvia-laravel/'.Preuvia::VERSION,
                'Accept' => $json ? 'application/json' : '*/*',
            ])
            ->timeout($this->options['timeout'])
            ->connectTimeout($this->options['connect_timeout']);

        return $authenticated ? $request->withToken($this->options['api_key']) : $request;
    }

    protected function decode(string $method, string $endpoint, Response $response): PreuviaResponse
    {
        if ($response->failed()) {
            throw $this->exceptionFor($method, $endpoint, $response);
        }

        $decoded = $response->json();

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw InvalidResponseException::notJson($method, $endpoint, $response->status());
        }

        if (($decoded['success'] ?? true) === false) {
            throw RequestException::unsuccessful($method, $endpoint, $response->status(), $decoded);
        }

        return new PreuviaResponse($response, $decoded);
    }

    protected function exceptionFor(string $method, string $endpoint, Response $response): PreuviaException
    {
        // Error bodies from a proxy (HTML 502...) carry no envelope: the status alone decides.
        $decoded = $this->isJson($response) || str_starts_with(ltrim($response->body()), '{') ? $response->json() : null;

        return PreuviaException::fromResponse(
            $method,
            $endpoint,
            $response->status(),
            is_array($decoded) ? $decoded : null,
            $this->retryAfter($response),
        );
    }

    protected function retryAfter(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    protected function pause(int $attempt, ?int $retryAfter): void
    {
        if ($retryAfter !== null && $retryAfter > 0) {
            Sleep::for($retryAfter)->seconds();

            return;
        }

        Sleep::for($this->options['retry_sleep_ms'] * $attempt)->milliseconds();
    }

    protected function isJson(Response $response): bool
    {
        $type = strtolower($response->header('Content-Type'));

        return str_contains($type, '/json') || str_contains($type, '+json');
    }

    protected function normalizeEndpoint(string $endpoint): string
    {
        return '/'.ltrim($endpoint, '/');
    }
}
