<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Models\Cms\StudentReview;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public student reviews page — `site.reviews.index`, the full list behind the home page's
 * `student_reviews` section.
 *
 * `website.reviews_page_enabled = false` makes the page a 404 (registry default: **off**).
 *
 * **This page cannot widen what moderation already approved.** `StudentReview::public()` is the one
 * filter the public site may use (§9.2) — `status = approved` — and `SoftDeletes` drops the rest. There
 * is no featured-only or rating override here: an editor approves a review once, in the moderation
 * screen, and every public surface reads that same decision.
 *
 * **The SELECT is the privacy boundary.** Only the eight columns the approved snapshot consists of are
 * loaded, which are exactly the ones `StudentReviewsSectionProvider` already publishes: the display
 * name and photo, the course name snapshot, the rating, the review, the video URL and the featured
 * flag. `student_id`, `course_id`, `submitted_by_user_id`, `approved_by`, `rejection_reason`, `source`
 * and `ip_address` are never read, so no template can print an internal id or a submitter's address.
 * The video is rendered through `site.marketing.partials.video-embed`, which rebuilds the embed from a
 * parsed YouTube / Vimeo id rather than trusting the stored string.
 *
 * **Paginated, always.** Reviews accumulate without limit and an index that loaded every approved row
 * would be the unbounded query the performance scan fails; the page size is
 * `website.reviews_per_page`, clamped by `sitePerPage()`.
 */
final class StudentReviewController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function index(): Response
    {
        if (! $this->siteFlag('website.reviews_page_enabled', false)) {
            return $this->notFound();
        }

        $reviews = $this->eagerPublic(
            StudentReview::query()
                ->public()
                ->select([
                    'id',
                    'student_name',
                    'student_photo_media_id',
                    'course_name',
                    'rating',
                    'review',
                    'video_url',
                    'is_featured',
                    'sort_order',
                ]),
            ['studentPhoto'],
        )
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate($this->sitePerPage('website.reviews_per_page', 12))
            ->withQueryString();

        return $this->contentPage('site.reviews.index', [
            'reviews' => $reviews,
        ], $this->routeSeo('site.reviews.index'), 'site-reviews', ['title' => 'Student reviews', 'slug' => 'student-reviews']);
    }
}
