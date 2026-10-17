<?php

namespace Preuvia\Laravel\Resources;

use Illuminate\Support\LazyCollection;
use Preuvia\Laravel\Http\PreuviaResponse;

/**
 * Token balance spent by eIDAS certificates and PDF signatures.
 */
class Tokens extends Resource
{
    public function balance(): PreuviaResponse
    {
        return $this->request('GET', '/tokens/balance');
    }

    /**
     * GET /tokens/transactions
     *
     * @param  array{page?: int, per_page?: int}  $query
     */
    public function transactions(array $query = []): PreuviaResponse
    {
        return $this->request('GET', '/tokens/transactions', $query);
    }

    /**
     * @param  array{page?: int, per_page?: int}  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazyTransactions(array $query = []): LazyCollection
    {
        return $this->paginate('/tokens/transactions', $query);
    }
}
