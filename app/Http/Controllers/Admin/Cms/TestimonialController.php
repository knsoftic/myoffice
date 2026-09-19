<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\TestimonialType;
use App\Http\Requests\Cms\BulkModerationRequest;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\ModerationRequest;
use App\Http\Requests\Cms\StoreTestimonialRequest;
use App\Http\Requests\Cms\UpdateTestimonialRequest;
use App\Models\Cms\Testimonial;
use App\Services\Cms\ModerationService;
use App\Services\Cms\ReviewContentService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client / student / other testimonials — `admin.testimonials.*` (phase-04 §2.10, §6.5, §7.2, §8.5),
 * `module:testimonials`. The moderation queue itself lives in `ModeratedContentController`.
 */
final class TestimonialController extends ModeratedContentController
{
    public function __construct(
        ModerationService $moderation,
        private readonly ReviewContentService $reviews,
    ) {
        parent::__construct($moderation);
    }

    public function index(ContentListRequest $request): View
    {
        return $this->queue($request);
    }

    public function create(Request $request): View
    {
        $this->authorize('testimonials.create');

        return view('admin.testimonials.create', [
            'testimonial' => null,
            'typeOptions' => TestimonialType::options(),
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'autoApprove' => $this->autoApprove(),
        ]);
    }

    public function store(StoreTestimonialRequest $request): Response
    {
        $this->authorize('testimonials.create');

        return $this->attempt($request, function () use ($request): Response {
            $testimonial = $this->reviews->storeTestimonial($request->testimonialPayload(), $request->uploadedImage('author_photo'));

            return $this->done(
                $request,
                sprintf('The testimonial from %s was saved.', $testimonial->author_name),
                redirect()->route('admin.testimonials.index', ['tab' => 'all']),
                ['id' => (int) $testimonial->getKey()],
            );
        }, field: 'review');
    }

    public function show(Request $request, Testimonial $testimonial): Response
    {
        return $this->showRecord($request, $testimonial);
    }

    public function edit(Request $request, Testimonial $testimonial): View
    {
        $this->authorize('testimonials.edit');
        $this->authorize('update', $testimonial);

        $this->loadAvailable($testimonial, ['approver', 'submitter', 'authorPhoto', 'editor']);

        return view('admin.testimonials.edit', [
            'testimonial' => $testimonial,
            'typeOptions' => TestimonialType::options(),
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'can' => $this->abilities($this->actor($request)),
        ]);
    }

    public function update(UpdateTestimonialRequest $request, Testimonial $testimonial): Response
    {
        $this->authorize('testimonials.edit');
        $this->authorize('update', $testimonial);

        return $this->attempt($request, function () use ($request, $testimonial): Response {
            $testimonial = $this->reviews->updateTestimonial($testimonial, $request->testimonialPayload(), $request->uploadedImage('author_photo'));

            return $this->done($request, 'The testimonial was saved. Any change to its text is recorded in the activity log.', redirect()->route('admin.testimonials.edit', $testimonial));
        }, field: 'review');
    }

    public function destroy(Request $request, Testimonial $testimonial): Response
    {
        $this->authorize('testimonials.delete');
        $this->authorize('delete', $testimonial);

        return $this->attempt($request, function () use ($request, $testimonial): Response {
            $this->reviews->delete($testimonial);

            return $this->done($request, 'The testimonial was deleted.', redirect()->route('admin.testimonials.index'));
        }, Response::HTTP_FORBIDDEN);
    }

    public function approve(ModerationRequest $request, Testimonial $testimonial): Response
    {
        return $this->approveRecord($request, $testimonial);
    }

    public function reject(ModerationRequest $request, Testimonial $testimonial): Response
    {
        return $this->rejectRecord($request, $testimonial);
    }

    public function bulkApprove(BulkModerationRequest $request): Response
    {
        return $this->bulkApproveRecords($request);
    }

    public function featured(Request $request, Testimonial $testimonial): Response
    {
        return $this->toggleFeaturedRecord($request, $testimonial);
    }

    protected function module(): string
    {
        return 'testimonials';
    }

    protected function modelClass(): string
    {
        return Testimonial::class;
    }

    protected function routePrefix(): string
    {
        return 'admin.testimonials';
    }

    protected function nameColumn(): string
    {
        return 'author_name';
    }

    protected function label(): string
    {
        return 'testimonial';
    }

    protected function paginatorName(): string
    {
        return 'testimonials';
    }

    protected function photoRelation(): string
    {
        return 'authorPhoto';
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function applyExtraFilters(Builder $query, ContentListRequest $request): void
    {
        $type = $request->filterEnum('type', TestimonialType::class);
        $course = $request->filterString('course');

        $query
            ->when($type instanceof TestimonialType, static fn (Builder $query) => $query->where('type', $type->value))
            ->when($course !== null, fn (Builder $query) => $query->where('course_name', 'like', $this->like($course)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraViewData(): array
    {
        return [
            'typeOptions' => TestimonialType::options(),
        ];
    }
}
