<?php

namespace Preuvia\Laravel\Resources;

use Illuminate\Support\LazyCollection;
use Preuvia\Laravel\Http\PreuviaResponse;

/**
 * Users of the client account.
 *
 * @phpstan-type UserPayload array{
 *     first_name?: string,
 *     last_name?: ?string,
 *     email?: string,
 *     password?: string,
 *     password_hash?: string,
 *     role?: string,
 *     is_owner?: bool,
 *     is_active?: bool,
 * }
 */
class Users extends Resource
{
    /**
     * GET /users
     *
     * @param  array{page?: int, per_page?: int, status?: string, search?: string}  $query
     */
    public function list(array $query = []): PreuviaResponse
    {
        return $this->request('GET', '/users', $query);
    }

    /**
     * @param  array{page?: int, per_page?: int, status?: string, search?: string}  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(array $query = []): LazyCollection
    {
        return $this->paginate('/users', $query);
    }

    /**
     * POST /users (first_name and email required).
     *
     * @param  UserPayload  $payload
     */
    public function create(array $payload): PreuviaResponse
    {
        return $this->request('POST', '/users', $payload);
    }

    public function show(string $uuid): PreuviaResponse
    {
        return $this->request('GET', $this->path('users', $uuid));
    }

    /**
     * @param  UserPayload  $payload
     */
    public function update(string $uuid, array $payload): PreuviaResponse
    {
        return $this->request('PATCH', $this->path('users', $uuid), $payload);
    }

    public function activate(string $uuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('users', $uuid, 'activate'));
    }

    public function deactivate(string $uuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('users', $uuid, 'deactivate'));
    }
}
