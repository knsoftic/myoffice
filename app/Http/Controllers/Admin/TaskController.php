<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DataObjects\Project\TaskData;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\Task;
use App\Models\User;
use App\Services\Project\TaskService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tasks — `admin.tasks.*` (phase-06 §7.3, §8.7), `module:tasks`.
 *
 * Every list runs through `Task::visibleTo()` (§9), so a developer's index is their own work plus whatever
 * their managing membership opens up, and a task they cannot see answers **404** through the policy.
 *
 * Writing is narrower than reading: `TaskPolicy::update()` wants the assignee or somebody who manages the
 * project, so a colleague's card is readable and not rewritable.
 */
final class TaskController extends Controller
{
    use AuthorizesRequests;

    private const SORTABLE = ['title', 'status', 'priority', 'due_date', 'created_at'];

    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Task::class);

        return view('admin.tasks.index', $this->listData($request, $this->scoped($request)));
    }

    /**
     * §7.3's "my work": the same screen, narrowed to what is assigned to me.
     */
    public function mine(Request $request): View
    {
        $this->authorize('viewAny', Task::class);

        $query = $this->scoped($request)->where('assigned_user_id', $request->user()->getKey());

        return view('admin.tasks.index', array_merge($this->listData($request, $query), ['mine' => true]));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Task::class);

        return view('admin.tasks.create', $this->formData($request));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Task::class);

        $task = $this->tasks->create(TaskData::fromArray($this->validated($request)), $request->user());

        return redirect()
            ->route('admin.tasks.show', $task)
            ->with('toast', ['type' => 'success', 'message' => 'Task created.']);
    }

    public function storeSubtask(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('create', Task::class);

        $payload = $this->validated($request);
        $payload['project_id'] = $task->project_id;
        $payload['parent_task_id'] = $task->getKey();

        $this->tasks->create(TaskData::fromArray($payload), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Subtask added.']);
    }

    public function show(Request $request, Task $task): View
    {
        $this->authorize('view', $task);

        $task->loadMissing([
            'project:id,code,name,client_id',
            'milestone:id,name',
            'assignee:id,name',
            'reporter:id,name',
            'parent:id,title',
            'subtasks',
            'checklistItems',
            'comments.author:id,name',
        ]);

        return view('admin.tasks.show', [
            'task' => $task,
            'statuses' => $this->allowedStatusOptions($task),
            'members' => $this->memberOptions($task->project),
            'canWrite' => $request->user()->can('update', $task),
        ]);
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $this->tasks->update($task, TaskData::fromArray($this->validated($request, partial: true)), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Task updated.']);
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);

        $this->tasks->delete($task, $request->user());

        return redirect()
            ->route('admin.tasks.index')
            ->with('toast', ['type' => 'success', 'message' => 'Task removed.']);
    }

    public function restore(Request $request, int $task): RedirectResponse
    {
        $model = Task::withTrashed()->findOrFail($task);

        $this->authorize('restore', $model);

        $this->tasks->restore($model, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Task restored.']);
    }

    public function status(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('changeStatus', $task);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(TaskStatus::values())],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->tasks->changeStatus(
            $task,
            TaskStatus::from($data['status']),
            $data['reason'] ?? null,
            $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Task is now %s.', $task->refresh()->status->label()),
        ]);
    }

    public function assign(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('assign', $task);

        $data = $request->validate([
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_collaborator_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->tasks->assign(
            $task,
            isset($data['assigned_user_id']) ? User::query()->find($data['assigned_user_id']) : null,
            $data['assigned_collaborator_id'] ?? null,
            $data['note'] ?? null,
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Assignment updated.']);
    }

    /**
     * @return Builder<Task>
     */
    private function scoped(Request $request): Builder
    {
        return Task::query()
            ->visibleTo($request->user())
            ->with(['project:id,code,name', 'assignee:id,name', 'milestone:id,name']);
    }

    /**
     * @param  Builder<Task>  $query
     * @return array<string, mixed>
     */
    private function listData(Request $request, Builder $query): array
    {
        $term = trim((string) $request->query('q', ''));
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $tasks = $query
            ->when($term !== '', fn (Builder $scoped) => $scoped->where('title', 'like', '%'.$term.'%'))
            ->when($request->query('status'), fn (Builder $scoped, string $status) => $scoped->where('status', $status))
            ->when($request->query('priority'), fn (Builder $scoped, string $priority) => $scoped->where('priority', $priority))
            ->when($request->query('project_id'), fn (Builder $scoped, $id) => $scoped->where('project_id', (int) $id))
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate(per_page())
            ->withQueryString();

        return [
            'tasks' => $tasks,
            'statuses' => TaskStatus::options(),
            'priorities' => Priority::options(),
            'projects' => $this->projectOptions($request),
            'filters' => $request->only(['q', 'status', 'priority', 'project_id']),
            'mine' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        return [
            'projects' => $this->projectOptions($request),
            'priorities' => Priority::options(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'project_id' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:projects,id'],
            'project_milestone_id' => ['nullable', 'integer', 'exists:project_milestones,id'],
            'title' => [$required, 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => [$required, 'string', Rule::in(Priority::values())],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_client_visible' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function projectOptions(Request $request): array
    {
        return Project::query()
            ->visibleTo($request->user())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Only active members can be assigned (§6.1), so only they are offered.
     *
     * @return array<int, string>
     */
    private function memberOptions(?Project $project): array
    {
        if ($project === null) {
            return [];
        }

        return $project->users()->orderBy('name')->pluck('name', 'users.id')->all();
    }

    /**
     * @return array<string, string>
     */
    private function allowedStatusOptions(Task $task): array
    {
        $options = [];

        foreach ($task->status->allowedTransitions() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
