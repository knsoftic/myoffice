<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Institute;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\CourseStatus;
use App\Models\Institute\Course;
use App\Support\DateRange;
use Throwable;

/**
 * What the institute currently has on offer (§88, phase-14-17 §8.21).
 *
 * **Published, not "all"**. A catalogue of forty courses of which six are on the site is a very
 * different institute from one with forty live, and a card that counted drafts would flatter the first
 * into looking like the second.
 *
 * It is a **state**, not a period: how many courses are on sale does not depend on the dashboard's date
 * range, so the range is deliberately ignored rather than quietly applied to `created_at` — which would
 * make the number drop to zero the moment somebody looked at "today".
 */
final class ActiveCoursesWidget extends Widget
{
    public function key(): string
    {
        return 'institute_active_courses';
    }

    public function title(): string
    {
        return 'Courses on offer';
    }

    public function icon(): string
    {
        return 'academic-cap';
    }

    public function permission(): ?string
    {
        return 'courses.view_any';
    }

    public function module(): ?string
    {
        return 'courses';
    }

    public function group(): string
    {
        return WidgetGroup::INSTITUTE;
    }

    public function sort(): int
    {
        return 10;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.courses.index', ['status' => 'published']);
    }

    public function emptyMessage(): ?string
    {
        return 'Nothing is published yet.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $counts = Course::query()
                ->toBase()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all();

            $featured = Course::query()
                ->where('status', CourseStatus::Published->value)
                ->where('is_featured', true)
                ->count();

            // Only courses somebody can actually apply to: `admission_open` and the institute-wide
            // switch have to agree, which is the same test `effectiveAdmissionOpen()` makes.
            $admitting = (bool) setting('institute.admission_open', true)
                ? Course::query()
                    ->where('status', CourseStatus::Published->value)
                    ->where('admission_open', true)
                    ->count()
                : 0;
        } catch (Throwable) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'published' => (int) ($counts[CourseStatus::Published->value] ?? 0),
            'draft' => (int) ($counts[CourseStatus::Draft->value] ?? 0),
            'archived' => (int) ($counts[CourseStatus::Archived->value] ?? 0),
            'featured' => $featured,
            'admitting' => $admitting,
            'institute_open' => (bool) setting('institute.admission_open', true),
        ];
    }
}
