<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Models\Cms\Faq;
use App\Models\Cms\StudentReview;
use App\Models\Institute\Course;
use App\Models\Institute\CourseModule;
use App\Models\Institute\CourseTopicResource;
use Illuminate\Support\Collection;

/**
 * Everything the public course page renders, assembled once (§90, phase-14-17 §6.13).
 *
 * **One payload, one query budget.** The landing page shows the course, its category, the active
 * outline tree, the public resources, the FAQs, the trainers, the upcoming batches and the approved
 * reviews — and a template that fetched each of those for itself would issue a query per accordion row.
 * `PublicCourseService::landing()` gathers them; the view only reads.
 *
 * `admissionOpen` is the resolved answer, not the column: the page asks
 * `CourseService::effectiveAdmissionOpen()` once and every Apply button on it obeys the same verdict.
 */
final readonly class CourseLandingPayload
{
    /**
     * @param  Collection<int, CourseModule>  $modules  active only, ordered, with their topics
     * @param  Collection<int, CourseTopicResource>  $publicResources
     * @param  Collection<int, Faq>  $faqs
     * @param  Collection<int, mixed>  $trainers  empty until Phase 16 ships `teachers`
     * @param  Collection<int, mixed>  $upcomingBatches  empty until Phase 16 ships `batches`
     * @param  Collection<int, StudentReview>  $reviews
     * @param  list<string>  $requirements
     * @param  list<string>  $outcomes
     */
    public function __construct(
        public Course $course,
        public Collection $modules,
        public Collection $publicResources,
        public Collection $faqs,
        public Collection $trainers,
        public Collection $upcomingBatches,
        public Collection $reviews,
        public array $requirements,
        public array $outcomes,
        public bool $admissionOpen,
        public string $applyUrl,
        public string $whatsappUrl,
        public ?string $referredBy = null,
        public ?string $referralCode = null,
    ) {}

    /**
     * Is there an outline worth rendering an accordion for?
     */
    public function hasOutline(): bool
    {
        return $this->modules->isNotEmpty();
    }

    /**
     * The total of the three fee components, or null when the course quotes nothing — in which case the
     * page says "Contact us" rather than printing a confident zero.
     */
    public function totalFee(): ?string
    {
        $total = $this->course->totalFee();

        return bccomp($total, '0.00', 2) === 1 ? $total : null;
    }

    /**
     * "About 36 hours of teaching" — from `outline_minutes`, when the outline actually says.
     *
     * Null below half an hour rather than a rounded zero: "about 0 hours of teaching" on a course page
     * reads as a broken figure, and saying nothing is the honest version of not knowing yet.
     */
    public function outlineHours(): ?int
    {
        $minutes = (int) $this->course->outline_minutes;

        return $minutes >= 30 ? (int) round($minutes / 60) : null;
    }
}
