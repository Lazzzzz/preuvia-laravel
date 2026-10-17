<?php

namespace Preuvia\Laravel\Resources;

use Illuminate\Support\LazyCollection;
use Preuvia\Laravel\Http\PreuviaResponse;
use Preuvia\Laravel\Http\Transport;

abstract class Resource
{
    public function __construct(
        protected Transport $transport,
    ) {}

    /**
     * Joins URL-encoded path segments: `path('dossiers', $uuid)` → `/dossiers/<uuid>`.
     */
    protected function path(string ...$segments): string
    {
        return Transport::path(...$segments);
    }

    /**
     * @param  array<string, mixed>  $data  Query string for GET, JSON body otherwise.
     */
    protected function request(string $method, string $endpoint, array $data = []): PreuviaResponse
    {
        return $this->transport->request($method, $endpoint, $data);
    }

    /**
     * Walks every page of a list endpoint lazily, one request per page (100 items unless the query says otherwise).
     *
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    protected function paginate(string $endpoint, array $query = []): LazyCollection
    {
        return LazyCollection::make(function () use ($endpoint, $query) {
            $query['per_page'] ??= 100;
            $page = max(1, (int) ($query['page'] ?? 1));

            while (true) {
                $response = $this->request('GET', $endpoint, array_replace($query, ['page' => $page]));
                $items = $response->data();

                if (! is_array($items) || $items === []) {
                    return;
                }

                foreach ($items as $item) {
                    yield $item;
                }

                $pagination = $response->pagination();

                if ($pagination === null || $pagination->currentPage >= $pagination->lastPage) {
                    return;
                }

                $page++;
            }
        });
    }
}
