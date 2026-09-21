<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\DataObjects\Institute\CatalogueQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Models\Institute\CourseCategory;
use App\Services\Cms\SeoService;
use App\Services\Institute\PublicCourseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public catalogue and course pages — `site.courses.*` (§89–90, phase-14-17 §7.10, §8.20).
 *
 * **Read-only, and it computes nothing.** `PublicCourseService` owns the filters, the payload and every
 * Apply / WhatsApp link — including the referral code that has to survive each hop. A page that built
 * its own `href="/admission"` would drop the visible half of a partner's attribution.
 *
 * **A course that is not published does not exist here.** A draft, an archived course and a course in a
 * switched-off category are all 404s, never 403s: a 403 would confirm to a stranger that the slug they
 * guessed is a real course.
 *
 * SEO goes through Phase 3's pipeline, like every other public page: the catalogue's own `seo_meta` row
 * keyed by route name, a course's from its own columns (D23) — this phase adds no second SEO store.
 */
final class CourseController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function __construct(
        private readonly PublicCourseService $courses,
        private readonly SeoService $seo,
    ) {}

    public function index(Request $request): Response
    {
        $query = CatalogueQuery::fromRequest($request);

        return $this->contentPage(
            'site.courses.index',
            [
                'courses' => $this->courses->catalogue($query),
                'categories' => $this->courses->filterCategories(),
                'query' => $query,
                'category' => null,
            ],
            $this->routeSeo('site.courses.index'),
            'site-courses',
            ['title' => 'Courses', 'slug' => 'courses'],
        );
    }

    /**
     * One category's courses, at its own canonical URL.
     */
    public function category(Request $request, string $category): Response
    {
        $model = CourseCategory::query()->active()->where('slug', $category)->first();

        if (! $model instanceof CourseCategory) {
            return $this->notFound();
        }

        $query = CatalogueQuery::fromRequest($request, (int) $model->getKey());

        return $this->contentPage(
            'site.courses.index',
            [
                'courses' => $this->courses->catalogue($query),
                'categories' => $this->courses->filterCategories(),
                'query' => $query,
                'category' => $model,
            ],
            $this->seo->for($model),
            'site-courses',
            ['title' => $model->name, 'slug' => 'courses/category/'.$model->slug],
        );
    }

    /**
     * A public syllabus file. The rule lives in the service so it cannot drift from the landing page.
     */
    public function resource(string $course, int $resource): StreamedResponse
    {
        $row = $this->courses->downloadableResource($course, (int) $resource);

        abort_unless(Storage::disk('local')->exists((string) $row->file_path), 404);

        $name = Str::slug((string) $row->title);

        return Storage::disk('local')->download(
            (string) $row->file_path,
            ($name === '' ? 'resource' : $name).'.'.pathinfo((string) $row->file_path, PATHINFO_EXTENSION),
        );
    }

    public function show(Request $request, string $course): Response
    {
        $payload = $this->courses->landing($course, $request);

        return $this->contentPage(
            'site.courses.show',
            ['payload' => $payload],
            $this->seo->for($payload->course),
            'site-course',
            ['title' => $payload->course->name, 'slug' => 'courses/'.$payload->course->slug],
        );
    }
}
