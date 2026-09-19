<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\EmploymentType;
use App\Enums\JobApplicationStatus;
use App\Enums\JobOpeningStatus;
use App\Enums\WorkMode;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ChangeJobOpeningStatusRequest;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\ReorderRequest;
use App\Http\Requests\Cms\StoreJobOpeningRequest;
use App\Http\Requests\Cms\UpdateJobOpeningRequest;
use App\Models\Cms\JobOpening;
use App\Models\User;
use App\Services\Cms\ContentOrderService;
use App\Services\Cms\JobOpeningService;
use App\Services\Cms\SeoService;
use App\Support\Format;
use App\Support\SlugGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Job openings — `admin.jobs.*` (phase-04 §2.18, §6.8, §7.2, §8.8), `module:jobs`. The table is
 * `job_openings`; only the module slug, the URI and the route names say "jobs" (R1).
 *
 * Writes: `JobOpeningService` (store, update, changeStatus, delete), `ContentOrderService::reorder()` and
 * `SeoService::save()` (D23). The form's *Publishing* tab carries `status`; changing it needs
 * `jobs.change_status` and goes through `changeStatus()`, which stamps `opened_at` / `closed_at`.
 * Salaries travel as decimal strings and are rendered with `money()` in the view — never a float.
 */
final class JobOpeningController extends Controller
{
    use RespondsForContent;

    private const SORTABLE = ['title', 'department', 'deadline', 'status', 'applications_count', 'sort_order', 'updated_at'];

    public function __construct(
        private readonly JobOpeningService $jobs,
        private readonly ContentOrderService $order,
        private readonly SeoService $seo,
    ) {}

    public function index(ContentListRequest $request): View
    {
        $this->authorize('jobs.view_any');

        $user = $this->actor($request);
        $trashed = $this->wantsTrashed($request, $user, 'jobs');
        $sort = $request->sortColumn(self::SORTABLE, 'sort_order');
        $direction = $request->sortDirection('asc');
        $search = $request->searchTerm();
        $status = $request->filterEnum('status', JobOpeningStatus::class);
        $type = $request->filterEnum('employment_type', EmploymentType::class);
        $mode = $request->filterEnum('work_mode', WorkMode::class);
        $department = $request->filterString('department');
        $deadline = $request->filterString('deadline');
        $featured = $request->filterBool('featured');
        $today = CarbonImmutable::now(Format::displayTimezone())->toDateString();

        $jobs = JobOpening::query()
            ->when($trashed, static fn (Builder $query) => $query->onlyTrashed())
            ->when($status instanceof JobOpeningStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($type instanceof EmploymentType, static fn (Builder $query) => $query->where('employment_type', $type->value))
            ->when($mode instanceof WorkMode, static fn (Builder $query) => $query->where('work_mode', $mode->value))
            ->when($department !== null, static fn (Builder $query) => $query->where('department', $department))
            ->when($featured !== null, static fn (Builder $query) => $query->where('is_featured', $featured))
            ->when($deadline === 'expired', static fn (Builder $query) => $query->whereNotNull('deadline')->where('deadline', '<', $today))
            ->when($deadline === 'week', static fn (Builder $query) => $query->whereBetween('deadline', [$today, CarbonImmutable::parse($today)->addDays(7)->toDateString()]))
            ->when($deadline === 'month', static fn (Builder $query) => $query->whereBetween('deadline', [$today, CarbonImmutable::parse($today)->addMonth()->toDateString()]))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('title', 'like', $this->like($search))
                    ->orWhere('department', 'like', $this->like($search))
                    ->orWhere('location', 'like', $this->like($search));
            }))
            ->select('job_openings.*')
            // "3 new" beside the applications count, in the same query.
            ->addSelect([
                'new_applications_count' => DB::table('job_applications')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('job_applications.job_opening_id', 'job_openings.id')
                    ->whereNull('job_applications.deleted_at')
                    ->where('job_applications.status', JobApplicationStatus::New->value),
            ])
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $filters = $request->activeFilters();

        return view('admin.jobs.index', [
            'jobs' => $jobs,
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'trashed' => $trashed,
            'today' => $today,
            'statusOptions' => JobOpeningStatus::options(),
            'employmentTypeOptions' => EmploymentType::options(),
            'workModeOptions' => WorkMode::options(),
            'departmentOptions' => $this->departments(),
            'counts' => $this->counts($user),
            'canReorder' => ! $trashed && $filters === [] && $jobs->lastPage() === 1 && $user->can('jobs.edit'),
            'can' => [
                'create' => $user->can('jobs.create'),
                'edit' => $user->can('jobs.edit'),
                'delete' => $user->can('jobs.delete'),
                'changeStatus' => $user->can('jobs.change_status'),
                'applications' => $user->can('job_applications.view_any'),
                'restore' => $user->can('jobs.restore'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('jobs.create');

        return view('admin.jobs.create', array_merge($this->formData($request), [
            'job' => null,
            'seoMeta' => null,
            'seoInherited' => null,
            'seoCompleteness' => null,
        ]));
    }

    public function store(StoreJobOpeningRequest $request): Response
    {
        $this->authorize('jobs.create');

        $status = $request->requestedStatus();
        $statusChanges = $status !== null && $status !== JobOpeningStatus::Draft;

        if ($statusChanges) {
            $this->authorize('jobs.change_status');
        }

        return $this->attempt($request, function () use ($request, $status, $statusChanges): Response {
            $job = DB::transaction(function () use ($request, $status, $statusChanges): JobOpening {
                $job = $this->jobs->store($request->jobOpeningPayload());

                if ($request->seoPayload() !== null) {
                    $this->seo->save($job, $request->seoPayload());
                }

                if ($statusChanges && $status !== null) {
                    $job = $this->jobs->changeStatus($job, $status);
                }

                return $job;
            });

            return $this->done(
                $request,
                sprintf('"%s" was saved.', $job->title),
                redirect()->route('admin.jobs.edit', $job),
                ['id' => (int) $job->getKey()],
            );
        }, field: 'title');
    }

    public function show(Request $request, JobOpening $job): Response
    {
        $this->authorize('jobs.view');
        $this->authorize('view', $job);

        return redirect()->route('admin.jobs.edit', $job);
    }

    public function edit(Request $request, JobOpening $job): View
    {
        $this->authorize('jobs.edit');
        $this->authorize('update', $job);

        $user = $this->actor($request);
        $status = $job->status instanceof JobOpeningStatus ? $job->status : JobOpeningStatus::tryFrom((string) $job->status);

        $job->loadCount(['applications as new_applications_count' => static fn (Builder $query) => $query->where('status', JobApplicationStatus::New->value)]);
        $this->loadAvailable($job, ['editor']);

        return view('admin.jobs.edit', array_merge($this->formData($request), [
            'job' => $job,
            'seoMeta' => $this->seo->meta($job),
            'seoInherited' => $this->seo->for($job),
            'seoCompleteness' => $this->seo->completeness($job),
            'publicUrl' => $status === JobOpeningStatus::Open && Route::has('site.careers.show') ? route('site.careers.show', ['jobOpening' => $job->slug]) : null,
            'applicationsUrl' => $user->can('job_applications.view_any') && Route::has('admin.job-applications.index') ? route('admin.job-applications.index', ['job' => $job->getKey()]) : null,
            'can' => [
                'edit' => $user->can('update', $job),
                'delete' => $user->can('jobs.delete') && $user->can('delete', $job),
                'changeStatus' => $user->can('jobs.change_status'),
            ],
        ]));
    }

    public function update(UpdateJobOpeningRequest $request, JobOpening $job): Response
    {
        $this->authorize('jobs.edit');
        $this->authorize('update', $job);

        $current = $job->status instanceof JobOpeningStatus ? $job->status : JobOpeningStatus::tryFrom((string) $job->status);
        $status = $request->requestedStatus();
        $statusChanges = $status !== null && $status !== $current;

        if ($statusChanges) {
            $this->authorize('jobs.change_status');
        }

        return $this->attempt($request, function () use ($request, $job, $status, $statusChanges): Response {
            $job = DB::transaction(function () use ($request, $job, $status, $statusChanges): JobOpening {
                $job = $this->jobs->update($job, $request->jobOpeningPayload());

                if ($request->seoPayload() !== null) {
                    $this->seo->save($job, $request->seoPayload());
                }

                if ($statusChanges && $status !== null) {
                    $job = $this->jobs->changeStatus($job, $status);
                }

                return $job;
            });

            return $this->done($request, sprintf('"%s" was saved.', $job->title), redirect()->route('admin.jobs.edit', $job));
        }, field: 'title');
    }

    /**
     * Soft delete: applications and their CV files are untouched (§2.19). A force delete is
     * `JobOpeningService::purge()`, which has no route in Phase 4.
     */
    public function destroy(Request $request, JobOpening $job): Response
    {
        $this->authorize('jobs.delete');
        $this->authorize('delete', $job);

        $title = $job->title;

        return $this->attempt($request, function () use ($request, $job, $title): Response {
            $this->jobs->delete($job);

            return $this->done($request, sprintf('"%s" was deleted. Its applications are kept.', $title), redirect()->route('admin.jobs.index'));
        }, Response::HTTP_FORBIDDEN);
    }

    public function status(ChangeJobOpeningStatusRequest $request, JobOpening $job): Response
    {
        $this->authorize('jobs.change_status');
        $this->authorize('changeStatus', $job);

        $status = $request->jobStatus();

        return $this->attempt($request, function () use ($request, $job, $status): Response {
            $job = $this->jobs->changeStatus($job, $status, $request->reason());

            return $this->done(
                $request,
                sprintf('"%s" is now %s.', $job->title, mb_strtolower($status->label())),
                null,
                ['id' => (int) $job->getKey(), 'status' => $status->value],
            );
        }, field: 'status');
    }

    public function reorder(ReorderRequest $request): Response
    {
        $this->authorize('jobs.edit');

        return $this->attempt($request, function () use ($request): Response {
            $this->order->reorder(JobOpening::class, $request->orderedIds());

            return $this->done($request, 'Job order saved.', null, ['ids' => $request->orderedIds()]);
        }, field: 'ids');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        return [
            'statusOptions' => JobOpeningStatus::options(),
            'employmentTypeOptions' => EmploymentType::options(),
            'workModeOptions' => WorkMode::options(),
            'salaryPeriodOptions' => $this->salaryPeriodOptions(),
            'departmentOptions' => $this->departments(),
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'reservedSlugs' => SlugGenerator::RESERVED,
            'today' => CarbonImmutable::now(Format::displayTimezone())->toDateString(),
            'canChangeStatus' => $this->actor($request)->can('jobs.change_status'),
        ];
    }

    /**
     * `JobOpening::SALARY_PERIODS`, value => label.
     *
     * @return array<string, string>
     */
    private function salaryPeriodOptions(): array
    {
        $labels = ['monthly' => 'Monthly', 'yearly' => 'Yearly', 'hourly' => 'Hourly', 'project' => 'Per project'];
        $options = [];

        foreach (JobOpening::SALARY_PERIODS as $period) {
            $options[$period] = $labels[$period] ?? ucfirst($period);
        }

        return $options;
    }

    /**
     * @return array{all: int, open: int, draft: int, closed: int, filled: int, trashed: int}
     */
    private function counts(User $user): array
    {
        $byStatus = $this->countBy(JobOpening::query(), 'status');

        return [
            'all' => array_sum($byStatus),
            'open' => $byStatus[JobOpeningStatus::Open->value] ?? 0,
            'draft' => $byStatus[JobOpeningStatus::Draft->value] ?? 0,
            'closed' => $byStatus[JobOpeningStatus::Closed->value] ?? 0,
            'filled' => $byStatus[JobOpeningStatus::Filled->value] ?? 0,
            'trashed' => $user->can('jobs.restore') ? JobOpening::query()->onlyTrashed()->count() : 0,
        ];
    }

    /**
     * @return list<string>
     */
    private function departments(): array
    {
        return JobOpening::query()
            ->whereNotNull('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department')
            ->map(static fn (mixed $department): string => (string) $department)
            ->all();
    }
}
