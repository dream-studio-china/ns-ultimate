<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Service;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One server-paginated Finance list page.
 *
 * Finance projections are paged in SQL, so the Core reply helper must not slice
 * them again. Controllers render this as the standard EasyAdmin envelope:
 * `data` rows plus a Core-compatible `paginator`, with optional extra top-level
 * keys (for example a report snapshot).
 */
#[Exclude]
final class LedgerPage
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 300;

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $limit,
        public readonly array $extra = [],
    ) {}

    /**
     * Validate `page` and `limit` list parameters.
     *
     * @param array<string, mixed> $parameters
     * @return array{int, int} page and limit
     */
    public static function window(array $parameters): array
    {
        $page = LedgerInput::page($parameters['page'] ?? '1');
        $limit = LedgerInput::page($parameters['limit'] ?? (string) self::DEFAULT_LIMIT);
        if ($limit > self::MAX_LIMIT) {
            throw new LedgerError('invalid_page');
        }

        return [$page, $limit];
    }

    /** @return array{total: int, page: int, limit: int, pages: int, has_previous: bool, has_next: bool} */
    public function paginator(): array
    {
        $pages = max(1, (int) ceil($this->total / $this->limit));

        return [
            'total' => $this->total,
            'page' => $this->page,
            'limit' => $this->limit,
            'pages' => $pages,
            'has_previous' => $this->page > 1,
            'has_next' => $this->page < $pages,
        ];
    }
}
