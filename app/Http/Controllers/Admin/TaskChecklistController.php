<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Events\Project\TaskChecklistChanged;
use App\Http\Controllers\Controller;
use App\Models\Project\Task;
use App\Models\Project\TaskChecklistItem;
use App\Services\Project\ProjectProgressService;
use App\Services\Project\TaskCacheService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A task's checklist — `admin.checklist.*` (phase-06 §7.3, §2.6).
 *
 * Each write recomputes `checklist_total` / `checklist_done` by COUNT under the task's lock (INV-P6) and
 * then walks the progress chain, **inside the same transaction** — so the ticked box and the percentage
 * beside it can never disagree, even if the request dies immediately afterwards.
 *
 * `done_at` is stamped with `is_done` because `chk_tci_done` requires it: "when was this ticked" must
 * never be unanswerable.
 */
final class TaskChecklistController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly TaskCacheService $caches,
        private readonly ProjectProgressService $progress,
    ) {}

    public function store(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($task, $data, $request): void {
            $item = new TaskChecklistItem;
            $item->forceFill([
                'task_id' => $task->getKey(),
                'title' => $data['title'],
                'sort_order' => (int) TaskChecklistItem::query()->where('task_id', $task->getKey())->max('sort_order') + 1,
            ])->save();

            $this->refresh($task, $request);
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'Checklist line added.']);
    }

    public function update(Request $request, TaskChecklistItem $item): RedirectResponse
    {
        $this->authorize('update', $item);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'is_done' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($item, $data, $request): void {
            $attributes = [];

            if (array_key_exists('title', $data)) {
                $attributes['title'] = $data['title'];
            }

            if (array_key_exists('is_done', $data)) {
                $done = (bool) $data['is_done'];
                $attributes['is_done'] = $done;
                // chk_tci_done: a done line always carries when and by whom.
                $attributes['done_at'] = $done ? now() : null;
                $attributes['done_by'] = $done ? $request->user()?->getKey() : null;
            }

            $item->forceFill($attributes)->save();

            $this->refresh($item->task, $request);
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'Checklist updated.']);
    }

    public function destroy(Request $request, TaskChecklistItem $item): RedirectResponse
    {
        $this->authorize('delete', $item);

        DB::transaction(function () use ($item, $request): void {
            $task = $item->task;
            $item->delete();

            $this->refresh($task, $request);
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'Checklist line removed.']);
    }

    private function refresh(Task $task, Request $request): void
    {
        $this->caches->syncWithParent($task);
        $this->progress->forget();
        $this->progress->recalculateTask($task->refresh());

        TaskChecklistChanged::dispatch($task, $request->user()?->getKey());
    }
}
