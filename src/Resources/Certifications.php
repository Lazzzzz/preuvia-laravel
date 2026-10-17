<?php

namespace Preuvia\Laravel\Resources;

use Illuminate\Support\LazyCollection;
use Preuvia\Laravel\Http\PreuviaResponse;

/**
 * Datasure certifications of the client's documents.
 */
class Certifications extends Resource
{
    /**
     * GET /certifications
     *
     * @param  array{page?: int, per_page?: int, status?: string}  $query
     */
    public function list(array $query = []): PreuviaResponse
    {
        return $this->request('GET', '/certifications', $query);
    }

    /**
     * @param  array{page?: int, per_page?: int, status?: string}  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(array $query = []): LazyCollection
    {
        return $this->paginate('/certifications', $query);
    }

    public function show(string $uuid): PreuviaResponse
    {
        return $this->request('GET', $this->path('certifications', $uuid));
    }
}
