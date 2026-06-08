<?php

declare(strict_types=1);

namespace Sinclear\Api\Domain;

use Sinclear\Api\Application\Settings;

/**
 * Pagination parameters for list endpoints.
 */
final readonly class Pagination
{
    public function __construct(
        public int $page,
        public int $limit,
        public int $offset,
        public string $sort,
        public string $order
    ) {
    }

    /**
     * @param array<string, string> $queryParams
     */
    public static function fromQuery(array $queryParams, Settings $settings): self
    {
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $defaultLimit = (int) $settings->get('pagination.default_limit', 25);
        $maxLimit = (int) $settings->get('pagination.max_limit', 100);
        $limit = min($maxLimit, max(1, (int) ($queryParams['limit'] ?? $defaultLimit)));
        $offset = ($page - 1) * $limit;
        $sort = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($queryParams['sort'] ?? 'createdAt')) ?: 'createdAt';
        $order = strtolower((string) ($queryParams['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        return new self($page, $limit, $offset, $sort, $order);
    }

    /**
     * @return array<string, int|float>
     */
    public function meta(int $total): array
    {
        return [
            'page' => $this->page,
            'limit' => $this->limit,
            'total' => $total,
            'totalPages' => $this->limit > 0 ? (int) ceil($total / $this->limit) : 0,
        ];
    }
}
