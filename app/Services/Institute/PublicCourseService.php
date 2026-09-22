<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\CatalogueQuery;
use App\DataObjects\Institute\CourseLandingPayload;
use App\Models\Cms\Faq;
use App\Models\Cms\StudentReview;
use App\Models\Institute\Course;
use App\Models\Institute\CourseCategory;
use App\Models\Institute\CourseTopicResource;
use App\Services\Collaborator\ReferralLinkService;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The §89–90 public read model (phase-14-17 §6.13).
 *
 * **Every Apply, Enrol and WhatsApp link on every public page is built here.** That is not tidiness: a
 * hand-written `href="/admission"` drops the visible half of a collaborator's attribution, and the
 * partner whose link brought the visitor stops being able to see that it worked. `applyUrl()` carries
 * the code through every hop.
 *
 * The **authoritative** carrier is Phase 9's visit row, session and encrypted cookie, written by its
 * `CaptureReferral` middleware — the `?ref=` parameter is kept so a shared or bookmarked link still
 * works and so the visitor can see the attribution written down. Neither is trusted as an id: the form
 * posts a **visit token** and the server re-resolves it (INV-I4, Phase 9 INV-R2).
 *
 * **Nothing here reads a draft.** The catalogue and the landing page both filter on `published`, and an
 * unpublished slug is a 404 — not a 403, which would confirm that the course exists.
 */
final class PublicCourseService
{
    public function __construct(
        private readonly CourseService $courses,
        private readonly ReferralLinkService $referrals,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | The catalogue
    |--------------------------------------------------------------------------
    */

    /**
     * @return LengthAwarePaginator<int, Course>
     */
    public function catalogue(CatalogueQuery $query): LengthAwarePaginator
    {
        return Course::query()
            ->published()
            // A course in a switched-off category is not on the site: disabling a grouping hides what
            // is inside it, which is the whole point of the switch.
            ->whereHas('category', fn (Builder $q) => $q->where('is_active', true))
            ->when($query->categoryId !== null, fn (Builder $q) => $q->where('course_category_id', $query->categoryId))
            ->when($query->level !== null, fn (Builder $q) => $q->where('level', $query->level->value))
            ->when($query->mode !== null, fn (Builder $q) => $q->where('delivery_mode', $query->mode->value))
            ->when($query->certificate === true, fn (Builder $q) => $q->where('certificate_available', true))
            ->when($query->featured === true, fn (Builder $q) => $q->where('is_featured', true))
            ->when($query->feeFrom !== null, fn (Builder $q) => $q->where('course_fee', '>=', Money::of($query->feeFrom)))
            ->when($query->feeTo !== null, fn (Builder $q) => $q->where('course_fee', '<=', Money::of($query->feeTo)))
            ->search($query->search)
            ->catalogueOrder()
            ->with(['category:id,name,slug,icon'])
            ->paginate($query->perPage)
            ->withQueryString();
    }

    /**
     * The active categories a filter rail offers, with a live count of what is actually published in
     * each — a rail offering a category with nothing behind it is a dead end.
     *
     * @return Collection<int, CourseCategory>
     */
    public function filterCategories(): Collection
    {
        return CourseCategory::query()
            ->active()
            ->ordered()
            ->withCount(['courses as published_courses_count' => fn (Builder $q) => $q->published()])
            ->get()
            ->filter(static fn (CourseCategory $c): bool => (int) $c->published_courses_count > 0)
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | The landing page
    |--------------------------------------------------------------------------
    */

    /**
     * Everything `/courses/{slug}` renders, in one place.
     *
     * A draft, an archived course or a course in a disabled category is a **404** — the page does not
     * exist as far as the public is concerned, and a 403 would confirm that it does.
     */
    public function landing(string $slug, ?Request $request = null): CourseLandingPayload
    {
        $course = $this->visible()
            ->where('slug', $slug)
            ->with([
                'category',
                // Active nodes only: a switched-off topic is one the institute has stopped teaching,
                // and listing it on the page it is selling would be a promise it no longer keeps.
                'modules' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id'),
                'modules.topics' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id'),
                'modules.topics.lectures' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id'),
                'modules.topics.publicResources',
            ])
            ->first();

        abort_if($course === null, Response::HTTP_NOT_FOUND);

        $visit = $request !== null ? $this->referrals->currentVisit($request) : null;

        return new CourseLandingPayload(
            course: $course,
            modules: $course->modules,
            publicResources: $course->resources()->where('is_public', true)->orderBy('sort_order')->get(),
            faqs: $this->faqsFor($course),
            trainers: $this->trainersFor($course),
            upcomingBatches: $this->upcomingBatchesFor($course),
            reviews: $this->reviewsFor($course),
            requirements: (array) ($course->requirements ?? []),
            outcomes: (array) ($course->outcomes ?? []),
            admissionOpen: $this->courses->effectiveAdmissionOpen($course),
            applyUrl: $this->applyUrl($course, null, $request),
            whatsappUrl: $this->whatsappUrl($course, $request),
            referredBy: $visit?->collaborator?->name,
            referralCode: $visit?->referral_code,
        );
    }

    /**
     * The one file a visitor may fetch from a syllabus, or a 404 (§2.8, D21).
     *
     * Every condition answers the same way, because each of them is a fact about something that does
     * not exist as far as this visitor is concerned: the course must be one the catalogue would show,
     * the resource must belong to *that* course, it must be marked public and downloadable, and the
     * file must actually be on the disk. A 403 on any of them would confirm that the id guessed is a
     * real resource of a real course.
     */
    public function downloadableResource(string $slug, int $resourceId): CourseTopicResource
    {
        $course = $this->visible()->where('slug', $slug)->first();

        abort_if($course === null, Response::HTTP_NOT_FOUND);

        $resource = CourseTopicResource::query()
            ->where('course_id', $course->getKey())
            ->where('is_public', true)
            ->where('is_downloadable', true)
            ->whereNotNull('file_path')
            ->find($resourceId);

        abort_if($resource === null, Response::HTTP_NOT_FOUND);

        return $resource;
    }

    /**
     * What "on the site" means, stated once: published, and in a category that is switched on.
     * `landing()` and `downloadableResource()` have to agree on it, or a file would outlive its page.
     */
    private function visible(): Builder
    {
        return Course::query()
            ->published()
            ->whereHas('category', fn (Builder $q) => $q->where('is_active', true));
    }

    /*
    |--------------------------------------------------------------------------
    | The links that carry the attribution
    |--------------------------------------------------------------------------
    */

    /**
     * The referral-preserving "Apply now" link.
     *
     * `?ref=` is the **visible** half of the attribution: the authoritative carrier is the visit row,
     * the session and the encrypted cookie, and this parameter exists so a shared link still works and
     * so the visitor can see who they were referred by. It is never read as an id — the form posts a
     * token, and the server re-resolves it.
     */
    public function applyUrl(Course $course, mixed $batch = null, ?Request $request = null): string
    {
        $parameters = array_filter([
            'course' => $course->slug,
            'batch' => is_object($batch) && isset($batch->code) ? $batch->code : null,
            'ref' => $this->displayCode($request),
        ], static fn ($value): bool => $value !== null && $value !== '');

        // The admission form is Phase 15's. Until it ships, "Apply" goes to the contact page carrying
        // the same parameters rather than to a route that does not exist — a dead button on a public
        // page is worse than one that lands somewhere a human reads (D28).
        if (Route::has('site.admission.create')) {
            return route('site.admission.create', $parameters);
        }

        return route('site.contact.index', $parameters);
    }

    /**
     * A WhatsApp message that already names the course — and the referral code, so staff creating the
     * inquiry from that chat still have it in front of them.
     */
    public function whatsappUrl(Course $course, ?Request $request = null): string
    {
        $number = preg_replace('/\D+/', '', (string) setting('contact.whatsapp', ''));
        $code = $this->displayCode($request);

        $message = sprintf('Hello, I would like to know more about the %s course.', $course->name);

        if ($code !== null) {
            $message .= sprintf(' (Referral code: %s)', $code);
        }

        return sprintf('https://wa.me/%s?text=%s', $number, rawurlencode($message));
    }

    /*
    |--------------------------------------------------------------------------
    | The sitemap lives elsewhere, on purpose
    |--------------------------------------------------------------------------
    |
    | §6.13 lists a `sitemapEntries()` here, but Phase 3 already owns the seam: a phase registers a
    | `SitemapUrlProvider` with `SitemapGenerator::extend()` and never edits the generator (D23).
    | `App\Support\Institute\Sitemap\CourseSitemapProvider` and its category counterpart are that
    | registration, and a second method on this class would be a second answer to "which courses are in
    | the sitemap" — the first thing to drift the day somebody changes the `is_indexable` rule in one
    | of them.
    */

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The code to show and to carry, or null when this visitor carries no attribution.
     */
    private function displayCode(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        return $this->referrals->currentVisit($request)?->referral_code;
    }

    /**
     * Phase 3's `faqs` rows, published only (§2.10).
     *
     * @return Collection<int, Faq>
     */
    private function faqsFor(Course $course): Collection
    {
        return $course->faqs()
            ->where('status', 'published')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Phase 4's approved reviews for this course.
     *
     * @return Collection<int, StudentReview>
     */
    private function reviewsFor(Course $course): Collection
    {
        return StudentReview::query()
            ->public()
            ->forCourse((int) $course->getKey())
            ->limit(12)
            ->get();
    }

    /**
     * Trainers come with Phase 16's `teachers`. Until then the section renders nothing at all rather
     * than a placeholder card, which would be an invented fact on a public page (D28).
     *
     * @return Collection<int, mixed>
     */
    private function trainersFor(Course $course): Collection
    {
        if (! app('db')->getSchemaBuilder()->hasTable('teachers')) {
            return collect();
        }

        return app('db')->table('course_teacher')
            ->join('teachers', 'teachers.id', '=', 'course_teacher.teacher_id')
            ->where('course_teacher.course_id', $course->getKey())
            ->where('teachers.is_public', true)
            ->select('teachers.*')
            ->get();
    }

    /**
     * Upcoming batches come with Phase 16. Same rule: no table, no section.
     *
     * @return Collection<int, mixed>
     */
    private function upcomingBatchesFor(Course $course): Collection
    {
        if (! app('db')->getSchemaBuilder()->hasTable('batches')) {
            return collect();
        }

        return app('db')->table('batches')
            ->where('course_id', $course->getKey())
            ->whereIn('status', ['planned', 'enrolling'])
            ->orderBy('start_date')
            ->limit(6)
            ->get();
    }
}
