<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\TestimonialType;
use App\Models\Cms\StudentReview;
use App\Models\Cms\SuccessStory;
use App\Models\Cms\Testimonial;
use App\Support\SettingsRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The public review walls — testimonials (§14), student reviews (§91) and success stories (§92)
 * (phase-04 §8.11, the feed the section partials and a later course page read).
 *
 * Every read goes through the model's `scopePublic()` — approved testimonials and reviews, published
 * stories — so nothing pending, rejected, draft or trashed can reach the site through this class.
 * Featured rows come first, then the admin's `sort_order`. Photos are eager-loaded (no N+1), and a course
 * filter uses the deferred `course_id` link only as a filter, never as a join (§2.1).
 */
final class TestimonialFeed
{
    public const MAX_LIMIT = 48;

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @return Collection<int, Testimonial>
     */
    public function testimonials(int $limit = 12, ?TestimonialType $type = null, bool $featuredOnly = false): Collection
    {
        return $this->testimonialQuery($type, $featuredOnly)->limit($this->limit($limit))->get();
    }

    /**
     * @return Collection<int, StudentReview>
     */
    public function studentReviews(int $limit = 12, ?int $courseId = null, bool $featuredOnly = false): Collection
    {
        return $this->studentReviewQuery($courseId, $featuredOnly)->limit($this->limit($limit))->get();
    }

    /**
     * @return Collection<int, SuccessStory>
     */
    public function successStories(int $limit = 12, ?int $courseId = null, bool $featuredOnly = false): Collection
    {
        $query = SuccessStory::query()->public()->with('photo')
            ->when($courseId !== null, static fn (Builder $builder) => $builder->where('course_id', $courseId))
            ->when($featuredOnly, static fn (Builder $builder) => $builder->where('is_featured', true));

        return $this->ordered($query)->limit($this->limit($limit))->get();
    }

    /**
     * The paginated testimonial feed (`website.reviews_per_page`).
     */
    public function paginateTestimonials(?TestimonialType $type = null): LengthAwarePaginator
    {
        return $this->testimonialQuery($type, false)->paginate($this->perPage())->withQueryString();
    }

    /**
     * The paginated student-review feed (`website.reviews_per_page`).
     */
    public function paginateStudentReviews(?int $courseId = null): LengthAwarePaginator
    {
        return $this->studentReviewQuery($courseId, false)->paginate($this->perPage())->withQueryString();
    }

    public function perPage(): int
    {
        $value = $this->settings->get('website.reviews_per_page', 12);

        return is_numeric($value) ? max(3, min(self::MAX_LIMIT, (int) $value)) : 12;
    }

    /**
     * @return Builder<Testimonial>
     */
    private function testimonialQuery(?TestimonialType $type, bool $featuredOnly): Builder
    {
        $query = Testimonial::query()->public()->with('authorPhoto')
            ->when($type !== null, static fn (Builder $builder) => $builder->where('type', $type?->value))
            ->when($featuredOnly, static fn (Builder $builder) => $builder->where('is_featured', true));

        return $this->ordered($query);
    }

    /**
     * @return Builder<StudentReview>
     */
    private function studentReviewQuery(?int $courseId, bool $featuredOnly): Builder
    {
        $query = StudentReview::query()->public()->with('studentPhoto')
            ->when($courseId !== null, static fn (Builder $builder) => $builder->where('course_id', $courseId))
            ->when($featuredOnly, static fn (Builder $builder) => $builder->where('is_featured', true));

        return $this->ordered($query);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function ordered(Builder $query): Builder
    {
        return $query->orderByDesc($query->qualifyColumn('is_featured'))
            ->orderBy($query->qualifyColumn('sort_order'))
            ->orderByDesc($query->qualifyColumn('id'));
    }

    private function limit(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
    }
}
