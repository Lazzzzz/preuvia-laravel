<?php

namespace Preuvia\Laravel\Resources;

use Illuminate\Support\LazyCollection;
use Preuvia\Laravel\Http\PreuviaResponse;

/**
 * Published templates the client can create dossiers from.
 */
class Templates extends Resource
{
    /**
     * GET /templates
     *
     * @param  array{page?: int, per_page?: int}  $query
     */
    public function list(array $query = []): PreuviaResponse
    {
        return $this->request('GET', '/templates', $query);
    }

    /**
     * @param  array{page?: int, per_page?: int}  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(array $query = []): LazyCollection
    {
        return $this->paginate('/templates', $query);
    }
}
