<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\TimerStopReason;
use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\Task;
use App\Models\Project\TimeEntry;
use App\Services\Project\TimeEntryService;
use App\Services\Project\TimerService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Time tracking — `admin.time.*` and `admin.timer.*` (phase-06 §7.5, §8.9), `module:time_tracking`.
 *
 * **`view` is "my time", `view_any` is "everyone's"** (§4.2): the list is scoped to the signed-in worker
 * unless they hold `view_any`, and the policy answers 404 rather than 403 for somebody else's entry, so
 * hours cannot be probed by id.
 *
 * The screen never renders a ticking number from the server. A running entry's stored seconds are the
 * closed segments only (INV-P5); the live figure is `duration_seconds + (now − open segment start)`,
 * computed in the browser from the server timestamp this controller passes down. That is why a crashed
 * tab or a double-clicked Stop cannot inflate anything.
 */
final class TimeTrackingController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly TimerService $timers,
        private readonly TimeEntryService $entries,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TimeEntry::class);

        $actor = $request->user();
        $seesAll = $actor->can('time_tracking.view_any');

        $entries = TimeEntry::query()
            ->with(['project:id,code,name', 'task:id,title', 'worker:id,name'])
            ->when(! $seesAll, fn (Builder $query) => $query->where('user_id', $actor->getKey()))
            ->when($request->query('project_id'), fn (Builder $query, $id) => $query->where('project_id', (int) $id))
            ->when($request->query('from'), fn (Builder $query, $from) => $query->whereDate('work_date', '>=', $from))
            ->when($request->query('to'), fn (Builder $query, $to) => $query->whereDate('work_date', '<=', $to))
            ->orderByDesc('work_date')
            ->orderByDesc('started_at')
            ->paginate(per_page())
            ->withQueryString();

        return view('admin.time.index', [
            'entries' => $entries,
            'live' => $this->timers->live($actor),
            'projects' => Project::query()->visibleTo($actor)->orderBy('name')->pluck('name', 'id')->all(),
            'seesAll' => $seesAll,
            'filters' => $request->only(['project_id', 'from', 'to']),
            // The browser ticks from this, never from a stored total.
            'serverNow' => now()->toIso8601String(),
            'totals' => $this->totals($actor, $seesAll),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'description' => ['nullable', 'string', 'max:500'],
            'switch' => ['nullable', 'boolean'],
        ]);

        $project = Project::query()->visibleTo($request->user())->findOrFail($data['project_id']);
        $task = isset($data['task_id']) ? Task::query()->find($data['task_id']) : null;

        // §6.4: switching is one atomic call, because a separate stop and start would race the guard.
        $method = $request->boolean('switch') ? 'switchTo' : 'start';

        $this->timers->{$method}($request->user(), null, $project, $task, $data['description'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'Timer started.']);
    }

    public function pause(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorize('runTimer', $entry);

        $this->timers->pause($entry, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Timer paused.']);
    }

    public function resume(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorize('runTimer', $entry);

        $this->timers->resume($entry, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Timer resumed.']);
    }

    public function stop(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorize('runTimer', $entry);

        $this->timers->stop($entry, TimerStopReason::Stop, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Timer stopped.']);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', TimeEntry::class);

        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after:started_at'],
            'description' => ['nullable', 'string', 'max:500'],
            'manual_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $project = Project::query()->visibleTo($request->user())->findOrFail($data['project_id']);

        $this->entries->createManual(
            $request->user(),
            null,
            $project,
            isset($data['task_id']) ? Task::query()->find($data['task_id']) : null,
            $data['started_at'],
            $data['ended_at'],
            $data['description'] ?? null,
            $data['manual_reason'] ?? null,
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Time recorded.']);
    }

    public function update(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorize('update', $entry);

        $data = $request->validate([
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after:started_at'],
            'description' => ['nullable', 'string', 'max:500'],
            'manual_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->entries->update(
            $entry,
            $data['started_at'],
            $data['ended_at'],
            $data['description'] ?? null,
            $data['manual_reason'] ?? null,
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Entry corrected.']);
    }

    /**
     * Discard — never a hard delete, and never without a reason (§6.1).
     */
    public function destroy(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorize('delete', $entry);

        $data = $request->validate([
            'discard_reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $this->entries->discard($entry, $data['discard_reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Entry discarded and removed from every total.']);
    }

    /**
     * Today and this week, in seconds — summed in one query each, formatted once in the view.
     *
     * @return array<string, int>
     */
    private function totals($actor, bool $seesAll): array
    {
        $base = fn (): Builder => TimeEntry::query()
            ->when(! $seesAll, fn (Builder $query) => $query->where('user_id', $actor->getKey()));

        $weekStart = now()->startOfWeek((int) setting('localization.week_start', 1));

        return [
            'today' => (int) $base()->whereDate('work_date', now()->toDateString())->sum('duration_seconds'),
            'week' => (int) $base()->whereDate('work_date', '>=', $weekStart->toDateString())->sum('duration_seconds'),
        ];
    }
}
