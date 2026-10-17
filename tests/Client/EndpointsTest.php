<?php

namespace Preuvia\Laravel\Tests\Client;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Preuvia\Laravel\Http\Download;
use Preuvia\Laravel\Http\PreuviaResponse;
use Preuvia\Laravel\Preuvia;
use Preuvia\Laravel\Testing\PreuviaFake;
use Preuvia\Laravel\Tests\TestCase;

class EndpointsTest extends TestCase
{
    private const BASE = 'https://preuvia.test/api/v1';

    /**
     * @return iterable<string, array{Closure(Preuvia): mixed, string, string, array<string, mixed>}>
     */
    public static function endpoints(): iterable
    {
        $base = self::BASE;

        yield 'me' => [fn (Preuvia $p) => $p->me(), 'GET', "{$base}/me", []];

        yield 'templates.list' => [fn (Preuvia $p) => $p->templates()->list(['page' => 2]), 'GET', "{$base}/templates?page=2", ['page' => 2]];

        yield 'customTemplates.list' => [fn (Preuvia $p) => $p->customTemplates()->list(['status' => 'draft']), 'GET', "{$base}/templates/custom?status=draft", ['status' => 'draft']];
        yield 'customTemplates.create' => [fn (Preuvia $p) => $p->customTemplates()->create(['name' => 'Visite', 'category' => 'inspection', 'config' => ['steps' => []]]), 'POST', "{$base}/templates/custom", ['name' => 'Visite', 'category' => 'inspection', 'config' => ['steps' => []]]];
        yield 'customTemplates.show' => [fn (Preuvia $p) => $p->customTemplates()->show('t-1'), 'GET', "{$base}/templates/custom/t-1", []];
        yield 'customTemplates.update' => [fn (Preuvia $p) => $p->customTemplates()->update('t-1', ['name' => 'Renamed']), 'PATCH', "{$base}/templates/custom/t-1", ['name' => 'Renamed']];
        yield 'customTemplates.publish' => [fn (Preuvia $p) => $p->customTemplates()->publish('t-1'), 'POST', "{$base}/templates/custom/t-1/publish", []];
        yield 'customTemplates.archive' => [fn (Preuvia $p) => $p->customTemplates()->archive('t-1'), 'POST', "{$base}/templates/custom/t-1/archive", []];
        yield 'customTemplates.unarchive' => [fn (Preuvia $p) => $p->customTemplates()->unarchive('t-1'), 'POST', "{$base}/templates/custom/t-1/unarchive", []];
        yield 'customTemplates.duplicate' => [fn (Preuvia $p) => $p->customTemplates()->duplicate('t-1'), 'POST', "{$base}/templates/custom/t-1/duplicate", []];

        yield 'dossiers.list' => [fn (Preuvia $p) => $p->dossiers()->list(['status' => 'active', 'source' => 'api']), 'GET', "{$base}/dossiers?status=active&source=api", ['status' => 'active', 'source' => 'api']];
        yield 'dossiers.create' => [fn (Preuvia $p) => $p->dossiers()->create(['template_uuid' => 't-1', 'name' => 'Lot 4']), 'POST', "{$base}/dossiers", ['template_uuid' => 't-1', 'name' => 'Lot 4']];
        yield 'dossiers.show' => [fn (Preuvia $p) => $p->dossiers()->show('d-1'), 'GET', "{$base}/dossiers/d-1", []];
        yield 'dossiers.update' => [fn (Preuvia $p) => $p->dossiers()->update('d-1', ['due_at' => '2026-11-01T10:00:00+01:00']), 'PATCH', "{$base}/dossiers/d-1", ['due_at' => '2026-11-01T10:00:00+01:00']];
        yield 'dossiers.delete' => [fn (Preuvia $p) => $p->dossiers()->delete('d-1'), 'DELETE', "{$base}/dossiers/d-1", []];
        yield 'dossiers.assign' => [fn (Preuvia $p) => $p->dossiers()->assign('d-1', 'u-1'), 'POST', "{$base}/dossiers/d-1/assignee", ['user_uuid' => 'u-1']];
        yield 'dossiers.unassign' => [fn (Preuvia $p) => $p->dossiers()->unassign('d-1'), 'DELETE', "{$base}/dossiers/d-1/assignee", []];
        yield 'dossiers.complete' => [fn (Preuvia $p) => $p->dossiers()->complete('d-1'), 'POST', "{$base}/dossiers/d-1/complete", []];
        yield 'dossiers.reopen' => [fn (Preuvia $p) => $p->dossiers()->reopen('d-1'), 'POST', "{$base}/dossiers/d-1/reopen", []];
        yield 'dossiers.archive' => [fn (Preuvia $p) => $p->dossiers()->archive('d-1'), 'POST', "{$base}/dossiers/d-1/archive", []];
        yield 'dossiers.unarchive' => [fn (Preuvia $p) => $p->dossiers()->unarchive('d-1'), 'POST', "{$base}/dossiers/d-1/unarchive", []];
        yield 'dossiers.syncTemplate' => [fn (Preuvia $p) => $p->dossiers()->syncTemplate('d-1'), 'POST', "{$base}/dossiers/d-1/template-sync", []];

        yield 'documents.list' => [fn (Preuvia $p) => $p->documents()->list('d-1', ['per_page' => 50]), 'GET', "{$base}/dossiers/d-1/documents?per_page=50", ['per_page' => 50]];
        yield 'documents.show' => [fn (Preuvia $p) => $p->documents()->show('d-1', 'doc-1'), 'GET', "{$base}/dossiers/d-1/documents/doc-1", []];
        yield 'documents.download' => [fn (Preuvia $p) => $p->documents()->download('d-1', 'doc-1'), 'GET', "{$base}/dossiers/d-1/documents/doc-1/download", []];
        yield 'documents.download preview' => [fn (Preuvia $p) => $p->documents()->download('d-1', 'doc-1', preview: true), 'GET', "{$base}/dossiers/d-1/documents/doc-1/download?preview=1", ['preview' => 1]];
        yield 'documents.artifacts' => [fn (Preuvia $p) => $p->documents()->artifacts('d-1', 'doc-1'), 'GET', "{$base}/dossiers/d-1/documents/doc-1/artifacts", []];
        yield 'documents.certify' => [fn (Preuvia $p) => $p->documents()->certify('d-1', 'doc-1'), 'POST', "{$base}/dossiers/d-1/documents/doc-1/certifications", []];
        yield 'documents.generateEidasCertificate' => [fn (Preuvia $p) => $p->documents()->generateEidasCertificate('d-1', 'doc-1'), 'POST', "{$base}/dossiers/d-1/documents/doc-1/eidas-certificate", []];
        yield 'documents.downloadEidasCertificate' => [fn (Preuvia $p) => $p->documents()->downloadEidasCertificate('d-1', 'doc-1'), 'GET', "{$base}/dossiers/d-1/documents/doc-1/eidas-certificate", []];
        yield 'documents.signPdf' => [fn (Preuvia $p) => $p->documents()->signPdf('d-1', 'doc-1'), 'POST', "{$base}/dossiers/d-1/documents/doc-1/sign-pdf", []];
        yield 'documents.downloadSignedPdf' => [fn (Preuvia $p) => $p->documents()->downloadSignedPdf('d-1', 'doc-1'), 'GET', "{$base}/dossiers/d-1/documents/doc-1/signed-pdf", []];

        yield 'certifications.list' => [fn (Preuvia $p) => $p->certifications()->list(['status' => 'certified']), 'GET', "{$base}/certifications?status=certified", ['status' => 'certified']];
        yield 'certifications.show' => [fn (Preuvia $p) => $p->certifications()->show('c-1'), 'GET', "{$base}/certifications/c-1", []];

        yield 'users.list' => [fn (Preuvia $p) => $p->users()->list(['search' => 'dupont']), 'GET', "{$base}/users?search=dupont", ['search' => 'dupont']];
        yield 'users.create' => [fn (Preuvia $p) => $p->users()->create(['first_name' => 'Ana', 'email' => 'ana@example.test']), 'POST', "{$base}/users", ['first_name' => 'Ana', 'email' => 'ana@example.test']];
        yield 'users.show' => [fn (Preuvia $p) => $p->users()->show('u-1'), 'GET', "{$base}/users/u-1", []];
        yield 'users.update' => [fn (Preuvia $p) => $p->users()->update('u-1', ['role' => 'manager']), 'PATCH', "{$base}/users/u-1", ['role' => 'manager']];
        yield 'users.activate' => [fn (Preuvia $p) => $p->users()->activate('u-1'), 'POST', "{$base}/users/u-1/activate", []];
        yield 'users.deactivate' => [fn (Preuvia $p) => $p->users()->deactivate('u-1'), 'POST', "{$base}/users/u-1/deactivate", []];

        yield 'tokens.balance' => [fn (Preuvia $p) => $p->tokens()->balance(), 'GET', "{$base}/tokens/balance", []];
        yield 'tokens.transactions' => [fn (Preuvia $p) => $p->tokens()->transactions(['page' => 3]), 'GET', "{$base}/tokens/transactions?page=3", ['page' => 3]];

        yield 'raw get' => [fn (Preuvia $p) => $p->get('dossiers', ['search' => 'x']), 'GET', "{$base}/dossiers?search=x", ['search' => 'x']];
        yield 'raw post' => [fn (Preuvia $p) => $p->post('/dossiers', ['template_uuid' => 't-1']), 'POST', "{$base}/dossiers", ['template_uuid' => 't-1']];
        yield 'raw patch' => [fn (Preuvia $p) => $p->patch('users/u-1', ['is_active' => false]), 'PATCH', "{$base}/users/u-1", ['is_active' => false]];
        yield 'raw delete' => [fn (Preuvia $p) => $p->delete('dossiers/d-1/assignee'), 'DELETE', "{$base}/dossiers/d-1/assignee", []];
    }

    /**
     * @param  Closure(Preuvia): mixed  $call
     * @param  array<string, mixed>  $data
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function it_sends_each_operation_to_its_endpoint(Closure $call, string $method, string $url, array $data): void
    {
        Http::fake(function (Request $request) {
            $binary = $request->method() === 'GET'
                && preg_match('#/(download|eidas-certificate|signed-pdf)(\?|$)#', $request->url()) === 1;

            return $binary
                ? PreuviaFake::file('%PDF-1.7', 'application/pdf', 'proof.pdf')
                : PreuviaFake::ok(['uuid' => 'x']);
        });

        $result = $call($this->app->make(Preuvia::class));

        $this->assertThat($result, $this->logicalOr(
            $this->isInstanceOf(PreuviaResponse::class),
            $this->isInstanceOf(Download::class),
        ));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === $method
            && $request->url() === $url
            && $request->data() == $data);
    }

    #[Test]
    public function it_encodes_path_segments(): void
    {
        Http::fake(fn () => PreuviaFake::ok());

        $this->app->make(Preuvia::class)->dossiers()->show('a/b c?');

        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/dossiers/a%2Fb%20c%3F');
    }

    #[Test]
    public function it_registers_the_preuvia_alias(): void
    {
        $this->assertSame($this->app->make(Preuvia::class), $this->app->make('preuvia'));
    }
}
