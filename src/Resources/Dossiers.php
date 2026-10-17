<?php

namespace Preuvia\Laravel\Resources;

use Illuminate\Support\LazyCollection;
use Preuvia\Laravel\Http\PreuviaResponse;

/**
 * @phpstan-type DossierQuery array{
 *     page?: int,
 *     per_page?: int,
 *     include_archived?: bool,
 *     status?: 'active'|'completed'|'missed'|'archived',
 *     template_uuid?: string,
 *     search?: string,
 *     source?: 'web'|'mobile'|'api'|'schedule',
 * }
 */
class Dossiers extends Resource
{
    /**
     * GET /dossiers
     *
     * @param  DossierQuery  $query
     */
    public function list(array $query = []): PreuviaResponse
    {
        return $this->request('GET', '/dossiers', $query);
    }

    /**
     * @param  DossierQuery  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(array $query = []): LazyCollection
    {
        return $this->paginate('/dossiers', $query);
    }

    /**
     * POST /dossiers. Not retried: a lost answer could otherwise create the dossier twice.
     *
     * @param  array{template_uuid: string, name?: ?string, assigned_user_uuid?: ?string, due_at?: ?string}  $payload
     */
    public function create(array $payload): PreuviaResponse
    {
        return $this->request('POST', '/dossiers', $payload);
    }

    public function show(string $uuid): PreuviaResponse
    {
        return $this->request('GET', $this->path('dossiers', $uuid));
    }

    /**
     * @param  array{name?: ?string, due_at?: ?string}  $payload
     */
    public function update(string $uuid, array $payload): PreuviaResponse
    {
        return $this->request('PATCH', $this->path('dossiers', $uuid), $payload);
    }

    public function delete(string $uuid): PreuviaResponse
    {
        return $this->request('DELETE', $this->path('dossiers', $uuid));
    }

    /**
     * POST /dossiers/{uuid}/assignee
     */
    public function assign(string $uuid, string $userUuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('dossiers', $uuid, 'assignee'), ['user_uuid' => $userUuid]);
    }

    /**
     * DELETE /dossiers/{uuid}/assignee
     */
    public function unassign(string $uuid): PreuviaResponse
    {
        return $this->request('DELETE', $this->path('dossiers', $uuid, 'assignee'));
    }

    public function complete(string $uuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('dossiers', $uuid, 'complete'));
    }

    public function reopen(string $uuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('dossiers', $uuid, 'reopen'));
    }

    public function archive(string $uuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('dossiers', $uuid, 'archive'));
    }

    public function unarchive(string $uuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('dossiers', $uuid, 'unarchive'));
    }

    /**
     * POST /dossiers/{uuid}/template-sync: appends the template steps missing from the dossier
     * (`meta.added_steps`); 409 template_snapshot_diverged when the snapshot no longer matches.
     */
    public function syncTemplate(string $uuid): PreuviaResponse
    {
        return $this->request('POST', $this->path('dossiers', $uuid, 'template-sync'));
    }
}
