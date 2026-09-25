<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Models\Institute\Course;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public fee structure — `site.fees.index`.
 *
 * `website.fee_structure_page_enabled = false` makes the page a 404, and the setting defaults to
 * **false**: an institute that has not priced its catalogue yet would otherwise publish an empty fee
 * table, and a soft-404 answering 200 is the version of that page which gets indexed.
 *
 * **Only `Course::published()` rows, and only inside a live category** — the same pair of conditions
 * `PublicCourseService::catalogue()` applies, because a price list that showed a draft course would be
 * quoting a figure the institute has not agreed to publish, and switching a category off is supposed to
 * hide what is inside it.
 *
 * **Nothing here adds anything up.** The four fee columns are a price list, not money that has moved
 * (see `Course`), and each is rendered on its own through the `money()` helper; `Course::totalFee()`
 * exists for a caller that genuinely wants the three one-off components combined through bcmath, and is
 * deliberately not used here. Courses are grouped by their category, with the ungrouped ones last.
 */
final class FeeStructureController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function index(): Response
    {
        if (! $this->siteFlag('website.fee_structure_page_enabled', false)) {
            return $this->notFound();
        }

        $courses = $this->eagerPublic(Course::query()->published(), ['category'])
            // A course filed under a switched-off category is not on the site at all.
            ->whereHas('category', fn (Builder $query): Builder => $query->where('is_active', true))
            ->catalogueOrder()
            ->get([
                'id', 'slug', 'name', 'code', 'course_category_id',
                'course_fee', 'admission_fee', 'registration_fee', 'monthly_fee',
                'duration_value', 'duration_unit',
                'installment_available', 'max_installments', 'installment_note',
                'certificate_available', 'admission_open', 'is_featured', 'sort_order',
            ]);

        $groups = [];
        $ungrouped = [];

        foreach ($courses as $course) {
            $category = trim((string) ($course->category?->name ?? ''));

            if ($category === '') {
                $ungrouped[] = $course;
            } else {
                $groups[$category][] = $course;
            }
        }

        if ($ungrouped !== []) {
            $groups[''] = $ungrouped;
        }

        return $this->contentPage('site.fees.index', [
            'courses' => $courses,
            // category name => courses; the '' key (no category) is always last.
            'groups' => $groups,
        ], $this->routeSeo('site.fees.index'), 'site-fees', ['title' => 'Fee structure', 'slug' => 'fee-structure']);
    }
}
