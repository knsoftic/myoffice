<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\JobApplicationStatus;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForContent;
use App\Http\Controllers\Admin\Cms\Concerns\StreamsCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\AssignRequest;
use App\Http\Requests\Cms\ChangeApplicationStatusRequest;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\UpdateJobApplicationRequest;
use App\Models\Activity;
use App\Models\Cms\JobApplication;
use App\Models\Cms\JobOpening;
use App\Models\User;
use App\Services\Cms\ApplicationCvService;
use App\Services\Cms\JobApplicationService;
use Generator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Job applications — the six-stage hiring pipeline, `admin.job-applications.*` (phase-04 §2.19, §6.8,
 * §7.2, §8.9, §9.1.3), `module:job_applications`.
 *
 * **Row scoping (§9.1.3).** Every query goes through `JobApplication::visibleTo()`: a holder of
 * `job_applications.view_any` (HR) sees every application; anyone else only those assigned to them or
 * belonging to an opening they created. An application outside that scope is a **404**, never a 403.
 *
 * **Withheld data.** `internal_notes` and `rating` reach the view only for a user who passes
 * `JobApplicationPolicy::update()`; they are hidden on the model otherwise, and the CV link is offered only
 * to one who passes `::download()`. The CV bytes are streamed only by `cv()` from the private disk (D21),
 * through `ApplicationCvService::download()`, which writes the §107 sensitive-access entry.
 */
final class JobApplicationController extends Controller
{
    use RespondsForContent;
    use StreamsCsv;

    private const SORTABLE = ['applicant_name', 'experience_years', 'expected_salary', 'rating', 'status', 'created_at'];

    public function __construct(
        private readonly JobApplicationService $applications,
        private readonly ApplicationCvService $cvs,
    ) {}

    public function index(ContentListRequest $request): View
    {
        // `view`, not view_any: a hiring manager or reviewer holding only `view` gets its own slice (§9.1.3,
        // tests 55-56); the rows stay scoped by `visibleTo()`.
        $this->authorize('job_applications.view');

        $user = $this->actor($request);
        $stage = $this->resolveStage($request);
        $sort = $request->sortColumn(self::SORTABLE, 'created_at');
        $direction = $request->sortDirection('desc');
        $canEdit = $user->can('job_applications.edit');

        $applications = $this->withAvailable($this->filteredQuery($request, $user, $stage), ['jobOpening', 'assignee'])
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        if (! $canEdit) {
            $applications->getCollection()->each(static fn (JobApplication $application) => $application->makeHidden(['internal_notes', 'rating']));
        }

        $jobId = $request->filterId('job');

        return view('admin.job-applications.index', [
            'applications' => $applications,
            'stage' => $stage,
            'stages' => array_map(
                static fn (JobApplicationStatus $case): array => ['value' => $case->value, 'label' => $case->label(), 'color' => $case->color()],
                JobApplicationStatus::cases(),
            ),
            'counts' => $this->funnel($user, $jobId),
            'filters' => $request->activeFilters(),
            'sort' => $sort,
            'direction' => $direction,
            'statusOptions' => JobApplicationStatus::options(),
            'jobOptions' => JobOpening::query()->withTrashed()->orderByDesc('created_at')->pluck('title', 'id')->all(),
            'reviewerOptions' => $this->reviewerOptions(),
            'interviewModes' => $this->interviewModes(),
            'selectedJob' => $jobId === null ? null : JobOpening::query()->withTrashed()->find($jobId),
            'can' => [
                'edit' => $canEdit,
                'delete' => $user->can('job_applications.delete'),
                'changeStatus' => $user->can('job_applications.change_status'),
                'assign' => $user->can('job_applications.assign'),
                'download' => $user->can('job_applications.download'),
                'export' => $user->can('job_applications.export'),
            ],
        ]);
    }

    public function show(Request $request, JobApplication $application): View
    {
        $this->authorize('job_applications.view');

        $user = $this->actor($request);
        $this->assertVisible($user, $application);
        $this->authorize('view', $application);

        $this->loadAvailable($application, ['jobOpening', 'assignee', 'statusChanger']);

        $canUpdate = $user->can('job_applications.edit') && $user->can('update', $application);
        $canDownload = $user->can('job_applications.download') && $user->can('download', $application);

        if (! $canUpdate) {
            $application->makeHidden(['internal_notes', 'rating']);
        }

        $status = $application->status instanceof JobApplicationStatus ? $application->status : JobApplicationStatus::tryFrom((string) $application->status);

        return view('admin.job-applications.show', [
            'application' => $application,
            'status' => $status,
            'allowedNext' => $status === null ? [] : array_values(array_map(
                static fn (mixed $next): string => $next instanceof JobApplicationStatus ? $next->value : (string) $next,
                $status->allowedNext(),
            )),
            'statusOptions' => JobApplicationStatus::options(),
            'interviewModes' => $this->interviewModes(),
            'timeline' => $this->timeline($application),
            'reviewerOptions' => $user->can('job_applications.assign') ? $this->reviewerOptions() : [],
            'cv' => [
                'name' => $application->cv_original_name,
                'mime' => $application->cv_mime,
                'size' => (int) $application->cv_size,
            ],
            'canUpdate' => $canUpdate,
            'canDownload' => $canDownload,
            'canDelete' => $user->can('job_applications.delete') && $user->can('delete', $application),
            'canChangeStatus' => $user->can('job_applications.change_status') && $user->can('changeStatus', $application),
            'canAssign' => $user->can('job_applications.assign') && $user->can('assign', $application),
        ]);
    }

    /**
     * Internal notes and the screening rating only (§7.2). A field that is not posted keeps its value —
     * the list's inline stars post `rating` alone and must never wipe the notes.
     */
    public function update(UpdateJobApplicationRequest $request, JobApplication $application): Response
    {
        $this->authorize('job_applications.edit');
        $this->assertVisible($this->actor($request), $application);
        $this->authorize('update', $application);

        $notes = $request->hasNotes() ? $request->notes() : $application->internal_notes;
        $rating = $request->hasRating() ? $request->rating() : ($application->rating === null ? null : (int) $application->rating);

        return $this->attempt($request, function () use ($request, $application, $notes, $rating): Response {
            $application = $this->applications->saveNotes($application, $notes, $rating);

            return $this->done(
                $request,
                'Saved.',
                redirect()->route('admin.job-applications.show', $application),
                ['id' => (int) $application->getKey(), 'rating' => $application->rating],
            );
        }, field: 'internal_notes');
    }

    /**
     * Soft delete: the CV file stays so a restore is lossless (§2.19).
     */
    public function destroy(Request $request, JobApplication $application): Response
    {
        $this->authorize('job_applications.delete');
        $this->assertVisible($this->actor($request), $application);
        $this->authorize('delete', $application);

        return $this->attempt($request, function () use ($request, $application): Response {
            $this->applications->delete($application);

            return $this->done($request, 'The application was deleted. Its CV is kept until it is permanently removed.', redirect()->route('admin.job-applications.index'));
        }, Response::HTTP_FORBIDDEN);
    }

    /**
     * Permanent removal, CV file included — the only way to let the same address apply to the same
     * opening again (§12 R4). Resolves trashed applications too.
     */
    public function forceDestroy(Request $request, string $application): Response
    {
        $this->authorize('job_applications.delete');

        $user = $this->actor($request);
        $record = JobApplication::query()->withTrashed()->visibleTo($user)->find($this->routeId($application));
        $this->abortUnlessVisible($record instanceof JobApplication);
        $this->authorize('forceDelete', $record);

        return $this->attempt($request, function () use ($request, $record): Response {
            $this->applications->forceDelete($record);

            return $this->done($request, 'The application and its CV were permanently removed.', redirect()->route('admin.job-applications.index'));
        }, Response::HTTP_FORBIDDEN);
    }

    public function status(ChangeApplicationStatusRequest $request, JobApplication $application): Response
    {
        $this->authorize('job_applications.change_status');
        $this->assertVisible($this->actor($request), $application);
        $this->authorize('changeStatus', $application);

        $to = $request->targetStatus();

        return $this->attempt($request, function () use ($request, $application, $to): Response {
            $application = $this->applications->changeStatus($application, $to, $request->context());

            return $this->done(
                $request,
                sprintf('%s moved to %s. The candidate is not emailed automatically.', $application->applicant_name, $to->label()),
                null,
                ['id' => (int) $application->getKey(), 'status' => $to->value],
            );
        }, field: 'status');
    }

    public function assign(AssignRequest $request, JobApplication $application): Response
    {
        $this->authorize('job_applications.assign');
        $this->assertVisible($this->actor($request), $application);
        $this->authorize('assign', $application);

        $reviewer = $request->assignee();

        return $this->attempt($request, function () use ($request, $application, $reviewer): Response {
            $application = $this->applications->assign($application, $reviewer);

            return $this->done(
                $request,
                $reviewer === null ? 'The application is now unassigned.' : sprintf('Assigned to %s.', $reviewer->name),
                null,
                ['id' => (int) $application->getKey(), 'assigned_to' => $reviewer?->getKey()],
            );
        }, field: 'user_id');
    }

    /**
     * Stream the CV from the private disk as an attachment (D21, test 37). Never inline, never a redirect.
     */
    public function cv(Request $request, JobApplication $application): Response
    {
        $this->authorize('job_applications.download');
        $this->assertVisible($this->actor($request), $application);
        $this->authorize('download', $application);

        $response = $this->cvs->download($application);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * CSV of the filtered, visible applications. No internal notes, no CV path.
     */
    public function export(ContentListRequest $request): StreamedResponse
    {
        $this->authorize('job_applications.export');

        $user = $this->actor($request);
        $stage = $this->resolveStage($request);

        $rows = function () use ($request, $user, $stage): Generator {
            $query = $this->withAvailable($this->filteredQuery($request, $user, $stage), ['jobOpening', 'assignee'])->orderByDesc('created_at');

            foreach ($query->cursor() as $application) {
                $status = $application->status instanceof JobApplicationStatus ? $application->status->label() : (string) $application->status;

                yield [
                    $application->getKey(),
                    $application->relationLoaded('jobOpening') ? $application->jobOpening?->title : null,
                    $application->applicant_name,
                    $application->email,
                    $application->phone,
                    $application->city,
                    $application->experience_years,
                    $application->expected_salary,
                    $status,
                    $application->relationLoaded('assignee') ? $application->assignee?->name : null,
                    $application->created_at,
                ];
            }
        };

        return $this->csv(
            'job-applications-'.Carbon::now()->format('Y-m-d').'.csv',
            ['ID', 'Position', 'Applicant', 'Email', 'Phone', 'City', 'Experience (years)', 'Expected salary', 'Stage', 'Reviewer', 'Applied at (UTC)'],
            $rows(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function assertVisible(User $user, JobApplication $application): void
    {
        $this->abortUnlessVisible(JobApplication::query()->visibleTo($user)->whereKey($application->getKey())->exists());
    }

    /**
     * `all` or one `JobApplicationStatus` value, from `?stage=` (or the generic `?tab=`).
     */
    private function resolveStage(ContentListRequest $request): string
    {
        $stage = $request->filterString('stage') ?? $request->filterString('tab');

        return $stage !== null && JobApplicationStatus::tryFrom($stage) !== null ? $stage : 'all';
    }

    /**
     * @return Builder<JobApplication>
     */
    private function filteredQuery(ContentListRequest $request, User $user, string $stage): Builder
    {
        $search = $request->searchTerm();
        $job = $request->filterId('job');
        $status = $stage !== 'all' ? JobApplicationStatus::tryFrom($stage) : $request->filterEnum('status', JobApplicationStatus::class);
        $assigned = $request->filterId('assigned');
        $unassigned = $request->filterBool('unassigned');
        $rating = $request->filterId('rating');
        $from = $request->fromDate();
        $to = $request->toDate();

        return JobApplication::query()
            ->visibleTo($user)
            ->when($job !== null, static fn (Builder $query) => $query->where('job_opening_id', $job))
            ->when($status instanceof JobApplicationStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($assigned !== null, static fn (Builder $query) => $query->where('assigned_to', $assigned))
            ->when($unassigned === true, static fn (Builder $query) => $query->whereNull('assigned_to'))
            ->when($rating !== null, static fn (Builder $query) => $query->where('rating', $rating))
            ->when($from !== null, static fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, static fn (Builder $query) => $query->where('created_at', '<=', $to))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('applicant_name', 'like', $this->like($search))
                    ->orWhere('email', 'like', $this->like($search))
                    ->orWhere('phone', 'like', $this->like($search));
            }));
    }

    /**
     * The stage counts, which double as the six-card funnel (for one job or all jobs).
     *
     * @return array<string, int>
     */
    private function funnel(User $user, ?int $jobId): array
    {
        $query = JobApplication::query()
            ->visibleTo($user)
            ->when($jobId !== null, static fn (Builder $query) => $query->where('job_opening_id', $jobId));

        $byStatus = $this->countBy($query, 'status');
        $counts = ['all' => array_sum($byStatus)];

        foreach (JobApplicationStatus::cases() as $case) {
            $counts[$case->value] = $byStatus[$case->value] ?? 0;
        }

        return $counts;
    }

    /**
     * Users who may be assigned an application: holders of `job_applications.view_any` or
     * `job_applications.view` (§6.11 `AssignRequest`, §9.1.3 — a reviewer without every application is
     * exactly who one is handed to), active accounts only.
     *
     * @return array<int, string>
     */
    private function reviewerOptions(): array
    {
        return User::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(static fn (User $candidate): bool => $candidate->can('job_applications.view_any') || $candidate->can('job_applications.view'))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function interviewModes(): array
    {
        $labels = ['onsite' => 'On-site', 'online' => 'Online', 'phone' => 'Phone'];
        $modes = [];

        foreach (JobApplication::INTERVIEW_MODES as $mode) {
            $modes[$mode] = $labels[$mode] ?? ucfirst($mode);
        }

        return $modes;
    }

    /**
     * The pipeline timeline from the activity log, newest first, with the causer: who moved the candidate,
     * when and why, and every CV download (§8.9).
     *
     * @return EloquentCollection<int, Activity>
     */
    private function timeline(JobApplication $application): EloquentCollection
    {
        return Activity::query()
            ->where('subject_type', $application->getMorphClass())
            ->where('subject_id', $application->getKey())
            ->with('causer')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }
}
