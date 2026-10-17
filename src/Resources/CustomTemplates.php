<?php

namespace Preuvia\Laravel\Resources;

use Illuminate\Support\LazyCollection;
use Preuvia\Laravel\Http\PreuviaResponse;

/**
 * Templates owned by the client account: drafts, published and archived.
 *
 * @phpstan-type TemplatePayload array{
 *     name?: string,
 *     description?: ?string,
 *     category?: string,
 *     icon?: ?string,
 *     image?: ?string,
 *     tone?: ?string,
 *     config?: array<string, mixed>,
 * }
 */
class CustomTemplates extends Resource
{
    /**
     * GET /templates/custom
     *
     * @param  array{page?: int, per_page?: int, include_archived?: bool, status?: string, search?: string}  $query
     */
    public function list(array $query = []): PreuviaResponse
    {
        return $this->request('GET', '/templates/custom', $query);
    }

    /**
     * @param  array{page?: int, per_page?: int, include_archived?: bool, status?: string, search?: string}  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(array $query = []): LazyCollection
    {
        return $this->paginate('/templates/custom', $query);
    }

    /**
     * POST /templates/custom (name, category and config required).
     *
     * @param  TemplatePayload  $payload
     */
    public function create(array $payload): PreuviaResponse
    {
        return $this->request('POST', '/templates/custom', $payload);
    }

    public function show(string $uuid): PreuviaResponse
    {
        return $this->request('GET', $this->path('templates', 'custom', $uuid));
    }

    /**
     * @param  TemplatePayload  $payload
     */
    public function update(string $uuid, array $payload): PreuviaResponse
    {
        return $this->request('PATCH', $this->path('templates', 'custom', $uuid), $payload);
    }

    public function publish(string $uuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('templates', 'custom', $uuid, 'publish'));
    }

    public function archive(string $uuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('templates', 'custom', $uuid, 'archive'));
    }

    public function unarchive(string $uuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('templates', 'custom', $uuid, 'unarchive'));
    }

    /**
     * Copies the template into a new draft. The v1 spec defines no body: $payload is sent as-is for later versions.
     *
     * @param  array<string, mixed>  $payload
     */
    public function duplicate(string $uuid, array $payload = []): PreuviaResponse
    {
        return $this->request('POST', $this->path('templates', 'custom', $uuid, 'duplicate'), $payload);
    }
}
