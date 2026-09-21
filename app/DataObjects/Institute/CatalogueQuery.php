<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Enums\CourseLevel;
use App\Enums\DeliveryMode;
use Illuminate\Http\Request;

/**
 * What a visitor asked the public catalogue for (§89, phase-14-17 §6.13).
 *
 * A DTO rather than a request array so the filters have one shape: the catalogue page, the category
 * page and the sitemap all build one of these, and none of them can invent a filter the others do not
 * understand. Everything is optional — an empty query is the whole published catalogue.
 */
final readonly class CatalogueQuery
{
    public function __construct(
        public ?string $search = null,
        public ?int $categoryId = null,
        public ?CourseLevel $level = null,
        public ?DeliveryMode $mode = null,
        public ?bool $certificate = null,
        public ?bool $featured = null,
        public ?string $feeFrom = null,
        public ?string $feeTo = null,
        public int $perPage = 12,
    ) {}

    /**
     * Built from a query string, with everything unrecognised dropped rather than guessed at.
     */
    public static function fromRequest(Request $request, ?int $categoryId = null): self
    {
        $perPage = (int) setting('institute.public_course_catalogue_per_page', 12);

        return new self(
            search: $request->filled('q') ? trim((string) $request->input('q')) : null,
            categoryId: $categoryId,
            level: CourseLevel::tryFrom((string) $request->input('level', '')),
            mode: DeliveryMode::tryFrom((string) $request->input('mode', '')),
            certificate: $request->boolean('certificate') ? true : null,
            featured: $request->boolean('featured') ? true : null,
            feeFrom: $request->filled('fee_from') ? (string) $request->input('fee_from') : null,
            feeTo: $request->filled('fee_to') ? (string) $request->input('fee_to') : null,
            perPage: max(3, min(60, $perPage)),
        );
    }

    /**
     * Is anything actually narrowed? The empty state says "no courses yet" or "clear your filters"
     * depending on the answer, and they are different messages.
     */
    public function hasFilters(): bool
    {
        return $this->search !== null
            || $this->level !== null
            || $this->mode !== null
            || $this->certificate !== null
            || $this->featured !== null
            || $this->feeFrom !== null
            || $this->feeTo !== null;
    }
}
