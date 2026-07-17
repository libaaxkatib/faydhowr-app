<?php

namespace App\Support\Reports;

use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * Cursor paginator that transforms row items while preserving the cursors
 * computed from the original model items.
 *
 * Laravel derives next/previous cursors lazily from the paginator items at
 * call time. If cursors were derived from transformed rows (e.g. ISO-8601
 * dates instead of the database datetime format), the encoded cursor values
 * would no longer match the database representation used in the cursor WHERE
 * clause, so next_cursor would never advance and previous_cursor would return
 * empty pages. Capturing the cursors before transforming avoids this.
 */
final class TransformedCursorPaginator extends CursorPaginator
{
    private readonly ?Cursor $modelNextCursor;

    private readonly ?Cursor $modelPreviousCursor;

    private readonly bool $modelHasMorePages;

    /**
     * @param  Closure(mixed): array<string, mixed>  $transformRow
     */
    public function __construct(CursorPaginatorContract $paginator, Closure $transformRow)
    {
        $this->modelNextCursor = $paginator->nextCursor();
        $this->modelPreviousCursor = $paginator->previousCursor();
        $this->modelHasMorePages = $paginator->hasMorePages();

        parent::__construct(
            array_map($transformRow, $paginator->items()),
            $paginator->perPage(),
        );
    }

    public function nextCursor(): ?Cursor
    {
        return $this->modelNextCursor;
    }

    public function previousCursor(): ?Cursor
    {
        return $this->modelPreviousCursor;
    }

    public function hasMorePages(): bool
    {
        return $this->modelHasMorePages;
    }
}
