<?php

namespace Preuvia\Laravel;

use Illuminate\Http\Client\Factory as HttpFactory;
use Preuvia\Laravel\Http\PreuviaResponse;
use Preuvia\Laravel\Http\Transport;
use Preuvia\Laravel\Resources\Certifications;
use Preuvia\Laravel\Resources\CustomTemplates;
use Preuvia\Laravel\Resources\Documents;
use Preuvia\Laravel\Resources\Dossiers;
use Preuvia\Laravel\Resources\Templates;
use Preuvia\Laravel\Resources\Tokens;
use Preuvia\Laravel\Resources\Users;

/**
 * Client of the Preuvia REST API v1. Immutable: the with*() methods return a configured copy.
 */
class Preuvia
{
    public const VERSION = '1.0.0';

    protected Transport $transport;

    /**
     * @param  array{
     *     base_url?: string,
     *     api_key?: ?string,
     *     health_token?: ?string,
     *     timeout?: int,
     *     connect_timeout?: int,
     *     retry_times?: int,
     *     retry_sleep_ms?: int,
     *     max_retry_after?: int,
     *     send_hash?: bool,
     * }  $options
     */
    public function __construct(HttpFactory $http, array $options = [])
    {
        $this->transport = new Transport($http, $options);
    }

    /**
     * Builds the client from the `preuvia` config file layout.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(HttpFactory $http, array $config): self
    {
        $defaults = Transport::DEFAULTS;

        return new self($http, [
            'base_url' => (string) ($config['base_url'] ?? $defaults['base_url']),
            'api_key' => (string) ($config['api_key'] ?? ''),
            'health_token' => isset($config['health_token']) ? (string) $config['health_token'] : null,
            'timeout' => (int) ($config['timeout'] ?? $defaults['timeout']),
            'connect_timeout' => (int) ($config['connect_timeout'] ?? $defaults['connect_timeout']),
            'retry_times' => (int) data_get($config, 'retry.times', $defaults['retry_times']),
            'retry_sleep_ms' => (int) data_get($config, 'retry.sleep_ms', $defaults['retry_sleep_ms']),
            'max_retry_after' => (int) data_get($config, 'retry.max_retry_after', $defaults['max_retry_after']),
            'send_hash' => (bool) data_get($config, 'documents.send_hash', $defaults['send_hash']),
        ]);
    }

    public function withApiKey(string $apiKey): static
    {
        return $this->withOptions(['api_key' => $apiKey]);
    }

    public function withBaseUrl(string $baseUrl): static
    {
        return $this->withOptions(['base_url' => $baseUrl]);
    }

    public function withTimeout(int $seconds): static
    {
        return $this->withOptions(['timeout' => $seconds]);
    }

    public function withRetries(int $times): static
    {
        return $this->withOptions(['retry_times' => $times]);
    }

    public function withoutRetries(): static
    {
        return $this->withRetries(0);
    }

    public function baseUrl(): string
    {
        return (string) $this->transport->option('base_url');
    }

    /**
     * GET /health. A 503 (a dependency is down) is returned, not thrown: check successful().
     */
    public function health(): PreuviaResponse
    {
        return $this->transport->health();
    }

    /**
     * GET /me: the API application, its client account and the acting user.
     */
    public function me(): PreuviaResponse
    {
        return $this->transport->request('GET', '/me');
    }

    public function templates(): Templates
    {
        return new Templates($this->transport);
    }

    public function customTemplates(): CustomTemplates
    {
        return new CustomTemplates($this->transport);
    }

    public function dossiers(): Dossiers
    {
        return new Dossiers($this->transport);
    }

    public function documents(): Documents
    {
        return new Documents($this->transport);
    }

    public function certifications(): Certifications
    {
        return new Certifications($this->transport);
    }

    public function users(): Users
    {
        return new Users($this->transport);
    }

    public function tokens(): Tokens
    {
        return new Tokens($this->transport);
    }

    /**
     * Raw GET for endpoints the SDK does not wrap yet. The endpoint is relative to the base URL.
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $endpoint, array $query = []): PreuviaResponse
    {
        return $this->transport->request('GET', $endpoint, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $endpoint, array $payload = []): PreuviaResponse
    {
        return $this->transport->request('POST', $endpoint, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function patch(string $endpoint, array $payload = []): PreuviaResponse
    {
        return $this->transport->request('PATCH', $endpoint, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function delete(string $endpoint, array $payload = []): PreuviaResponse
    {
        return $this->transport->request('DELETE', $endpoint, $payload);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->transport = $this->transport->with($options);

        return $clone;
    }
}
