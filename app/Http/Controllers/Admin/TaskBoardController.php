<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\Task;
use App\Services\Project\TaskService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Kanban board — `admin.tasks.board`, `admin.projects.board`, `admin.tasks.move` (phase-06 §8.4, §6.5).
 *
 * **The board is two queries, not one per column.** Cards are fetched once, ordered by
 * `(project_id, status, board_position)` — the index §2.5 exists for — and grouped in PHP. Every count on
 * a card comes from a cache column (INV-P6), so rendering a hundred cards does not touch a comment or an
 * attachment table.
 *
 * {@see move()} answers JSON with the **whole affected column**, not just the moved card. A drop normally
 * writes one row, but when the gap between neighbours closes the service renumbers the column in the same
 * transaction (§6.5) — the client has to resync from the answer rather than assume its optimistic order
 * still holds.
 */
final class TaskBoardController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Task::class);

        return view('admin.tasks.board', $this->board($request, null));
    }

    public function forProject(Request $request, Project $project): View
    {
        $this->authorize('view', $project);
        $this->authorize('viewAny', Task::class);

        return view('admin.tasks.board', $this->board($request, $project));
    }

    /**
     * The drag handler. Throttled at the route, because a stuck pointer should cost one 429 rather than a
     * thousand writes.
     */
    public function move(Request $request, Task $task): JsonResponse
    {
        $this->authorize('changeStatus', $task);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(TaskStatus::values())],
            'after_id' => ['nullable', 'integer'],
            'before_id' => ['nullable', 'integer'],
        ]);

        $result = $this->tasks->move(
            $task,
            TaskStatus::from($data['status']),
            $data['after_id'] ?? null,
            $data['before_id'] ?? null,
            $request->user(),
        );

        return response()->json([
            'task' => [
                'id' => $result['task']->getKey(),
                'status' => $result['task']->status->value,
                'position' => (string) $result['task']->board_position,
                'progress_percent' => (string) $result['task']->progress_percent,
            ],
            'column' => $result['column'],
            'renormalised' => $result['renormalised'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function board(Request $request, ?Project $project): array
    {
        $cards = Task::query()
            ->visibleTo($request->user())
            ->when($project !== null, fn (Builder $query) => $query->where('project_id', $project->getKey()))
            ->where('depth', 0)
            ->whereIn('status', array_map(
                static fn (TaskStatus $status): string => $status->value,
                TaskStatus::boardColumns()
            ))
            ->with(['project:id,code,name', 'assignee:id,name'])
            ->orderBy('board_position')
            ->orderBy('id')
            ->get();

        $columns = [];

        foreach (TaskStatus::boardColumns() as $status) {
            $columns[$status->value] = [
                'status' => $status,
                'cards' => $cards->where('status', $status)->values(),
            ];
        }

        return [
            'project' => $project,
            'columns' => $columns,
            'projects' => Project::query()->visibleTo($request->user())->orderBy('name')->pluck('name', 'id')->all(),
            'canMove' => $request->user()->can('tasks.change_status'),
        ];
    }
}
