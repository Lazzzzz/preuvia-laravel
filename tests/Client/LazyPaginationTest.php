<?php

namespace Preuvia\Laravel\Tests\Client;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\Test;
use Preuvia\Laravel\Http\Pagination;
use Preuvia\Laravel\Preuvia;
use Preuvia\Laravel\Testing\PreuviaFake;
use Preuvia\Laravel\Tests\TestCase;

class LazyPaginationTest extends TestCase
{
    private function client(): Preuvia
    {
        return $this->app->make(Preuvia::class);
    }

    #[Test]
    public function lazy_walks_every_page_on_demand(): void
    {
        Http::fake(function (Request $request) {
            $page = (int) $request->data()['page'];

            return PreuviaFake::paginated([['uuid' => "d-{$page}a"], ['uuid' => "d-{$page}b"]], $page, 3, 100);
        });

        $dossiers = $this->client()->dossiers()->lazy(['status' => 'active']);

        $this->assertInstanceOf(LazyCollection::class, $dossiers);
        Http::assertNothingSent();

        $this->assertSame(['d-1a', 'd-1b', 'd-2a', 'd-2b', 'd-3a', 'd-3b'], $dossiers->pluck('uuid')->all());

        $this->assertSame([
            'https://preuvia.test/api/v1/dossiers?status=active&per_page=100&page=1',
            'https://preuvia.test/api/v1/dossiers?status=active&per_page=100&page=2',
            'https://preuvia.test/api/v1/dossiers?status=active&per_page=100&page=3',
        ], Http::recorded()->map(fn (array $pair) => $pair[0]->url())->all());
    }

    #[Test]
    public function lazy_stops_early_when_only_a_few_items_are_taken(): void
    {
        Http::fake(fn (Request $request) => PreuviaFake::paginated([['id' => 1], ['id' => 2]], (int) $request->data()['page'], 5, 2));

        $this->assertCount(3, $this->client()->users()->lazy()->take(3)->all());

        Http::assertSentCount(2);
    }

    #[Test]
    public function lazy_stops_on_an_empty_page_and_honours_the_caller_query(): void
    {
        Http::fakeSequence()
            ->pushResponse(PreuviaFake::paginated([['id' => 1]], 2, 9, 10))
            ->pushResponse(PreuviaFake::paginated([], 3, 9, 10));

        $items = $this->client()->documents()->lazy('d-1', ['per_page' => 10, 'page' => 2])->all();

        $this->assertSame([['id' => 1]], $items);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://preuvia.test/api/v1/dossiers/d-1/documents?per_page=10&page=2');
    }

    #[Test]
    public function every_list_has_a_lazy_variant(): void
    {
        Http::fake(fn () => PreuviaFake::paginated([['id' => 1]]));

        $client = $this->client();

        foreach ([
            $client->templates()->lazy(),
            $client->customTemplates()->lazy(),
            $client->certifications()->lazy(),
            $client->tokens()->lazyTransactions(),
        ] as $lazy) {
            $this->assertSame([['id' => 1]], $lazy->all());
        }

        $this->assertSame([
            '/api/v1/templates',
            '/api/v1/templates/custom',
            '/api/v1/certifications',
            '/api/v1/tokens/transactions',
        ], Http::recorded()->map(fn (array $pair) => $pair[0]->toPsrRequest()->getUri()->getPath())->all());
    }

    #[Test]
    public function pagination_is_built_from_meta(): void
    {
        $pagination = Pagination::fromArray(['current_page' => 2, 'last_page' => 2, 'per_page' => 25, 'total' => 40]);

        $this->assertFalse($pagination->hasMorePages());
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 25, 'total' => 40], $pagination->toArray());
        $this->assertTrue(Pagination::fromArray(['current_page' => 1, 'last_page' => 2])->hasMorePages());
    }
}
