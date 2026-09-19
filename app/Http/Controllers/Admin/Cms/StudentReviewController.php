<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Requests\Cms\BulkModerationRequest;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\ModerationRequest;
use App\Http\Requests\Cms\StoreStudentReviewRequest;
use App\Http\Requests\Cms\UpdateStudentReviewRequest;
use App\Models\Cms\StudentReview;
use App\Services\Cms\ModerationService;
use App\Services\Cms\ReviewContentService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Student reviews — `admin.student-reviews.*` (phase-04 §2.11, §6.5, §7.2, §8.5),
 * `module:student_reviews`. Staff entry only in Phase 4 (§12 Q4); the moderation queue lives in
 * `ModeratedContentController`.
 */
final class StudentReviewController extends ModeratedContentController
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
        $this->authorize('student_reviews.create');

        return view('admin.student-reviews.create', [
            'review' => null,
            'courseOptions' => $this->courseOptions(),
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'autoApprove' => $this->autoApprove(),
        ]);
    }

    public function store(StoreStudentReviewRequest $request): Response
    {
        $this->authorize('student_reviews.create');

        return $this->attempt($request, function () use ($request): Response {
            $review = $this->reviews->storeStudentReview($request->studentReviewPayload(), $request->uploadedImage('student_photo'));

            return $this->done(
                $request,
                sprintf('The review from %s was saved.', $review->student_name),
                redirect()->route('admin.student-reviews.index', ['tab' => 'all']),
                ['id' => (int) $review->getKey()],
            );
        }, field: 'review');
    }

    public function show(Request $request, StudentReview $review): Response
    {
        return $this->showRecord($request, $review);
    }

    public function edit(Request $request, StudentReview $review): View
    {
        $this->authorize('student_reviews.edit');
        $this->authorize('update', $review);

        $this->loadAvailable($review, ['approver', 'submitter', 'studentPhoto', 'editor']);

        return view('admin.student-reviews.edit', [
            'review' => $review,
            'courseOptions' => $this->courseOptions(),
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'can' => $this->abilities($this->actor($request)),
        ]);
    }

    public function update(UpdateStudentReviewRequest $request, StudentReview $review): Response
    {
        $this->authorize('student_reviews.edit');
        $this->authorize('update', $review);

        return $this->attempt($request, function () use ($request, $review): Response {
            $review = $this->reviews->updateStudentReview($review, $request->studentReviewPayload(), $request->uploadedImage('student_photo'));

            return $this->done($request, 'The review was saved. Any change to its text is recorded in the activity log.', redirect()->route('admin.student-reviews.edit', $review));
        }, field: 'review');
    }

    public function destroy(Request $request, StudentReview $review): Response
    {
        $this->authorize('student_reviews.delete');
        $this->authorize('delete', $review);

        return $this->attempt($request, function () use ($request, $review): Response {
            $this->reviews->delete($review);

            return $this->done($request, 'The review was deleted.', redirect()->route('admin.student-reviews.index'));
        }, Response::HTTP_FORBIDDEN);
    }

    public function approve(ModerationRequest $request, StudentReview $review): Response
    {
        return $this->approveRecord($request, $review);
    }

    public function reject(ModerationRequest $request, StudentReview $review): Response
    {
        return $this->rejectRecord($request, $review);
    }

    public function bulkApprove(BulkModerationRequest $request): Response
    {
        return $this->bulkApproveRecords($request);
    }

    public function featured(Request $request, StudentReview $review): Response
    {
        return $this->toggleFeaturedRecord($request, $review);
    }

    protected function module(): string
    {
        return 'student_reviews';
    }

    protected function modelClass(): string
    {
        return StudentReview::class;
    }

    protected function routePrefix(): string
    {
        return 'admin.student-reviews';
    }

    protected function nameColumn(): string
    {
        return 'student_name';
    }

    protected function label(): string
    {
        return 'student review';
    }

    protected function paginatorName(): string
    {
        return 'reviews';
    }

    protected function photoRelation(): string
    {
        return 'studentPhoto';
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function applyExtraFilters(Builder $query, ContentListRequest $request): void
    {
        $course = $request->filterString('course');

        $query->when($course !== null, static fn (Builder $query) => $query->where('course_name', $course));
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraViewData(): array
    {
        return [
            'courseOptions' => $this->courseOptions(),
        ];
    }

    /**
     * The course snapshot names already used, value => label, for the course filter and the form's
     * suggestions (the `courses` table arrives in Phase 14; until then the snapshot is all there is).
     *
     * @return array<string, string>
     */
    private function courseOptions(): array
    {
        $options = [];

        foreach (StudentReview::query()->whereNotNull('course_name')->distinct()->orderBy('course_name')->pluck('course_name') as $name) {
            $options[(string) $name] = (string) $name;
        }

        return $options;
    }
}
