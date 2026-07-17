<?php

namespace App\Data\Reports;

use Illuminate\Contracts\Pagination\CursorPaginator;

final class ReportCursorPagination
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 500;

    public function __construct(
        private readonly int $limit = self::DEFAULT_LIMIT,
        private readonly ?string $cursor = null,
    ) {}

    public function limit(): int
    {
        return $this->limit;
    }

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    /**
     * Build the public pagination metadata from a cursor paginator
     * without exposing Laravel internals in report payloads.
     *
     * @return array{has_more: bool, next_cursor: ?string, previous_cursor: ?string, per_page: int, count: int}
     */
    public static function metadata(CursorPaginator $paginator): array
    {
        return [
            'has_more' => $paginator->hasMorePages(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'previous_cursor' => $paginator->previousCursor()?->encode(),
            'per_page' => $paginator->perPage(),
            'count' => count($paginator->items()),
        ];
    }
}
