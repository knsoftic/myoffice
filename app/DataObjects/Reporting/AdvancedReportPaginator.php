<?php

declare(strict_types=1);

namespace App\DataObjects\Reporting;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * One page of Advanced Reports rows whose links carry **exactly** the filters the server applied
 * (D177).
 *
 * `AdvancedReportRequest::toData()` corrects a request once — a course or batch the viewer cannot
 * filter by, a batch that does not fit the course, a money sort for a viewer without money, a reversed
 * date range — and `AdvancedReportFilters::toQuery()` writes the corrected set back out. The page
 * links are built from that set (`appends()`); they must never re-send the uncorrected query string.
 *
 * The shared `x-ui.pagination-summary` calls `withQueryString()` on every paginator it renders, which
 * merges the raw request query back over the appended one. Here that is a no-op: the query this page
 * carries is already the whole, sanitised filter set. Nothing else about the paginator changes.
 *
 * @template TValue
 *
 * @extends LengthAwarePaginator<int, TValue>
 */
final class AdvancedReportPaginator extends LengthAwarePaginator
{
    /**
     * `$page`'s items, total, size, position and path, with `$query` as its links' query string.
     *
     * @param  LengthAwarePaginator<int, TValue>  $page
     * @param  array<string, string|int>  $query
     * @return self<TValue>
     */
    public static function withFilters(LengthAwarePaginator $page, array $query): self
    {
        $copy = new self($page->items(), $page->total(), $page->perPage(), $page->currentPage(), [
            'path' => $page->path(),
            'pageName' => $page->getPageName(),
        ]);

        $copy->appends($query);

        return $copy;
    }

    /**
     * The links already carry the sanitised filters; the raw request query is not merged back in.
     *
     * @return $this
     */
    public function withQueryString(): static
    {
        return $this;
    }
}
