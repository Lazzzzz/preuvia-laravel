<?php

namespace Preuvia\Laravel\Http;

/**
 * `meta.pagination` of a list response.
 */
final readonly class Pagination
{
    public function __construct(
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {}

    /**
     * @param  array{current_page?: int, last_page?: int, per_page?: int, total?: int}  $pagination
     */
    public static function fromArray(array $pagination): self
    {
        $currentPage = max(1, (int) ($pagination['current_page'] ?? 1));

        return new self(
            $currentPage,
            max($currentPage, (int) ($pagination['last_page'] ?? $currentPage)),
            (int) ($pagination['per_page'] ?? 0),
            (int) ($pagination['total'] ?? 0),
        );
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    public function toArray(): array
    {
        return [
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage,
            'per_page' => $this->perPage,
            'total' => $this->total,
        ];
    }
}
