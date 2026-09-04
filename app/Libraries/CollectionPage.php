<?php

namespace App\Libraries;

/**
 * Normalizes collection pagination and provides one response contract for
 * asynchronous CMS directory endpoints.
 */
final class CollectionPage
{
    private function __construct(
        private readonly int $total,
        private readonly int $page,
        private readonly int $perPage,
        private readonly int $pages,
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query, int $total, int $defaultPerPage = 20, int $maximumPerPage = 100): self
    {
        $total = max(0, $total);
        $maximumPerPage = max(1, $maximumPerPage);
        $defaultPerPage = max(1, min($maximumPerPage, $defaultPerPage));
        $requestedPerPage = filter_var($query['per_page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $perPage = $requestedPerPage === false ? $defaultPerPage : min($maximumPerPage, (int) $requestedPerPage);
        $pages = max(1, (int) ceil($total / $perPage));
        $requestedPage = filter_var($query['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $page = min($pages, $requestedPage === false ? 1 : (int) $requestedPage);

        return new self($total, $page, $perPage, $pages);
    }

    public function page(): int
    {
        return $this->page;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * @param list<mixed> $items
     * @return array{items:list<mixed>,pagination:array{page:int,perPage:int,total:int,pages:int,hasPrevious:bool,hasNext:bool}}
     */
    public function payload(array $items): array
    {
        return [
            'items' => array_values($items),
            'pagination' => [
                'page' => $this->page,
                'perPage' => $this->perPage,
                'total' => $this->total,
                'pages' => $this->pages,
                'hasPrevious' => $this->page > 1,
                'hasNext' => $this->page < $this->pages,
            ],
        ];
    }
}
