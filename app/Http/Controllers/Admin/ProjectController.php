<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DataObjects\Project\ProjectData;
use App\Enums\MilestoneStatus;
use App\Enums\Priority;
use App\Enums\ProgressBasis;
use App\Enums\ProjectMemberRole;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ChangeProjectStatusRequest;
use App\Http\Requests\Project\ProjectListRequest;
use App\Http\Requests\Project\ReviseProjectValueRequest;
use App\Http\Requests\Project\SetProjectProgressRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\Crm\Client;
use App\Models\Project\Project;
use App\Models\User;
use App\Services\Project\ProjectProgressService;
use App\Services\Project\ProjectService;
use App\Services\Project\ProjectValueService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Projects — `admin.projects.*` (phase-06 §7.1, §8.1-§8.3), `module:projects`.
 *
 * **Money is withheld, not blanked** (§9, INV-P15). Everything under {@see FINANCIAL_COLUMNS} is chosen in
 * the SELECT only when the viewer holds `projects.view_financial`; without it the columns never reach the
 * query, so they are absent from the response body rather than hidden by a CSS class or an `@can` around
 * a value the server already sent.
 *
 * **Every list is scoped by `Project::visibleTo()`** — the one definition of "may see this project" (§9),
 * so the index, the pickers and route-model binding all agree. A project the viewer cannot see answers
 * **404** through the policy, never 403, so an id cannot be probed.
 */
final class ProjectController extends Controller
{
    use AuthorizesRequests;

    private const SORTABLE = ['code', 'name', 'status', 'priority', 'deadline', 'progress_percent', 'created_at'];

    /**
     * The columns §9 puts behind `projects.view_financial`.
     *
     * @var list<string>
     */
    private const FINANCIAL_COLUMNS = [
        'budget_amount', 'project_value', 'discount_amount', 'net_value',
        'commission_type', 'commission_rate', 'commission_fixed_amount',
    ];

    /**
     * The columns every screen needs whatever the viewer holds.
     *
     * @var list<string>
     */
    private const BASE_COLUMNS = [
        'id', 'code', 'name', 'client_id', 'lead_id', 'project_manager_id', 'service_id',
        'description', 'project_type', 'priority', 'status', 'start_date', 'deadline', 'completed_on',
        'currency', 'value_revision_count', 'progress_percent', 'progress_mode', 'progress_basis',
        'progress_reason', 'progress_set_by', 'progress_updated_at', 'estimated_minutes',
        'actual_seconds', 'actual_minutes', 'actual_hours', 'created_at', 'updated_at', 'deleted_at',
    ];

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectValueService $values,
        private readonly ProjectProgressService $progress,
    ) {}

    public function index(ProjectListRequest $request): View
    {
        $this->authorize('viewAny', Project::class);

        $actor = $request->user();
        $showMoney = $actor->can('projects.view_financial');

        $sort = $request->sortColumn(self::SORTABLE, 'created_at');
        $direction = $request->sortDirection();

        $projects = $this->query($request, $actor, $showMoney)
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($request->integer('per_page') ?: per_page())
            ->withQueryString();

        return view('admin.projects.index', [
            'projects' => $projects,
            'showMoney' => $showMoney,
            'sort' => $sort,
            'direction' => $direction,
            'statuses' => ProjectStatus::options(),
            'priorities' => Priority::options(),
            'types' => ProjectType::options(),
            'clients' => $this->clientOptions(),
            'managers' => $this->managerOptions(),
            'filters' => $request->only(['q', 'status', 'priority', 'type', 'client_id', 'manager_id', 'overdue', 'trashed']),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Project::class);

        return view('admin.projects.create', $this->formData($request));
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = $this->projects->create(ProjectData::fromArray($request->validated()), $request->user());

        return redirect()
            ->route('admin.projects.show', $project)
            ->with('toast', ['type' => 'success', 'message' => sprintf('Project %s created.', $project->code)]);
    }

    public function show(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        $actor = $request->user();
        $showMoney = $actor->can('projects.view_financial');

        $project->loadMissing([
            'client:id,name,company_name',
            'projectManager:id,name',
            'service:id,title',
            'members.user:id,name',
            'milestones',
        ]);

        return view('admin.projects.show', [
            'project' => $project,
            'showMoney' => $showMoney,
            'derivedProgress' => $this->progress->derivedFor($project),
            'revisions' => $showMoney ? $this->values->history($project) : null,
            'taskCounts' => $this->taskCounts($project),
            'milestoneStatuses' => MilestoneStatus::options(),
            'memberRoles' => ProjectMemberRole::options(),
            'statuses' => $this->allowedStatusOptions($project),
        ]);
    }

    public function edit(Request $request, Project $project): View
    {
        $this->authorize('update', $project);

        return view('admin.projects.edit', array_merge($this->formData($request), ['project' => $project]));
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $this->projects->update($project, ProjectData::fromArray($request->validated()), $request->user());

        return redirect()
            ->route('admin.projects.show', $project)
            ->with('toast', ['type' => 'success', 'message' => 'Project updated.']);
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $this->projects->archive($project, $request->user());

        return redirect()
            ->route('admin.projects.index')
            ->with('toast', ['type' => 'success', 'message' => sprintf('%s archived.', $project->code)]);
    }

    public function restore(Request $request, int $project): RedirectResponse
    {
        $model = Project::withTrashed()->findOrFail($project);

        $this->authorize('restore', $model);

        $this->projects->restore($model, $request->user());

        return redirect()
            ->route('admin.projects.show', $model)
            ->with('toast', ['type' => 'success', 'message' => sprintf('%s restored.', $model->code)]);
    }

    public function status(ChangeProjectStatusRequest $request, Project $project): RedirectResponse
    {
        $this->projects->changeStatus(
            $project,
            ProjectStatus::from($request->validated('status')),
            $request->validated('reason'),
            $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is now %s.', $project->code, $project->refresh()->status->label()),
        ]);
    }

    /**
     * The [D-P6-3] override, and the way back to derivation.
     */
    public function progress(SetProjectProgressRequest $request, Project $project): RedirectResponse
    {
        if ($request->validated('mode') === 'manual') {
            $this->progress->setManual(
                $project,
                (string) $request->validated('progress_percent'),
                (string) $request->validated('reason'),
                $request->user(),
            );
        } else {
            $this->progress->setAuto($project, (string) $request->validated('reason'), $request->user());
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Progress updated.']);
    }

    /**
     * The value and commission tab (§8.8) — `projects.view_financial` only.
     */
    public function value(Request $request, Project $project): View
    {
        $this->authorize('viewFinancial', $project);

        return view('admin.projects.value', [
            'project' => $project,
            'revisions' => $this->values->history($project),
            'canRevise' => $request->user()->can('revise', $project),
        ]);
    }

    public function storeValue(ReviseProjectValueRequest $request, Project $project): RedirectResponse
    {
        $data = $request->validated();

        $this->values->revise(
            $project,
            array_intersect_key($data, array_flip(Project::VALUE_COLUMNS)),
            (string) $data['reason'],
            (string) $data['effective_on'],
            $request->user(),
            $data['notes'] ?? null,
        );

        return redirect()
            ->route('admin.projects.value.index', $project)
            ->with('toast', ['type' => 'success', 'message' => 'Value revision recorded.']);
    }

    /**
     * @return Builder<Project>
     */
    private function query(ProjectListRequest $request, User $actor, bool $showMoney): Builder
    {
        $columns = $showMoney
            ? array_merge(self::BASE_COLUMNS, self::FINANCIAL_COLUMNS)
            : self::BASE_COLUMNS;

        return Project::query()
            ->select($columns)
            ->visibleTo($actor)
            ->with(['client:id,name,company_name', 'projectManager:id,name'])
            ->when($request->boolean('trashed'), fn (Builder $query) => $query->onlyTrashed())
            ->when($request->search(), fn (Builder $query, string $term) => $query->where(
                fn (Builder $scoped) => $scoped
                    ->where('name', 'like', '%'.$term.'%')
                    ->orWhere('code', 'like', '%'.$term.'%')
            ))
            ->when($request->query('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->query('priority'), fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($request->query('type'), fn (Builder $query, string $type) => $query->where('project_type', $type))
            ->when($request->query('client_id'), fn (Builder $query, $id) => $query->where('client_id', (int) $id))
            ->when($request->query('manager_id'), fn (Builder $query, $id) => $query->where('project_manager_id', (int) $id))
            ->when($request->boolean('overdue'), fn (Builder $query) => $query
                ->whereNotNull('deadline')
                ->whereDate('deadline', '<', now()->toDateString())
                ->whereNotIn('status', [ProjectStatus::Completed->value, ProjectStatus::Cancelled->value]));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        return [
            'clients' => $this->clientOptions(),
            'managers' => $this->managerOptions(),
            'types' => ProjectType::options(),
            'priorities' => Priority::options(),
            'bases' => ProgressBasis::options(),
            'canSetValue' => $request->user()->can('projects.view_financial'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function clientOptions(): array
    {
        return Client::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * §1.2 [D-P6-2]: work is assigned to a `users` row, and the picker is filtered to admin-panel staff.
     *
     * @return array<int, string>
     */
    private function managerOptions(): array
    {
        return User::query()
            ->whereHas('roles', fn (Builder $role) => $role->where('panel', 'admin'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The status moves §2.13.1 allows from where the project stands — the select offers nothing else.
     *
     * @return array<string, string>
     */
    private function allowedStatusOptions(Project $project): array
    {
        $options = [];

        foreach ($project->status->allowedTransitions() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    /**
     * @return array<string, int>
     */
    private function taskCounts(Project $project): array
    {
        $counts = $project->tasks()
            ->selectRaw('`status`, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $result = ['total' => array_sum($counts)];

        foreach (TaskStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }
}
