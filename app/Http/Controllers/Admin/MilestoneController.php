<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\MilestoneStatus;
use App\Http\Controllers\Controller;
use App\Models\Project\Project;
use App\Models\Project\ProjectMilestone;
use App\Services\Project\MilestoneService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Milestones — `admin.milestones.*` (phase-06 §7.2, §8.5), `module:project_milestones`.
 *
 * `amount` is accepted only from somebody holding `projects.view_financial` (§4.2): a milestone's payment
 * value is money, and a role that cannot see the contract must not be able to set the schedule it pays on.
 *
 * Dates outside the project window are a **warning, not a refusal** (§6.1) — the service returns the
 * message and the screen shows it, because a milestone that overruns is a fact worth recording.
 */
final class MilestoneController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly MilestoneService $milestones) {}

    public function index(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return view('admin.milestones.index', [
            'project' => $project,
            'milestones' => $project->milestones()->with('tasks:id,project_milestone_id,status')->get(),
            'statuses' => MilestoneStatus::options(),
            'showMoney' => $request->user()->can('projects.view_financial'),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('create', ProjectMilestone::class);

        $data = $this->validated($request);

        $this->milestones->create($project, $data, $request->user());

        $warning = $this->milestones->datesWarning($project, $data['start_date'] ?? null, $data['deadline'] ?? null);

        return back()->with('toast', [
            'type' => $warning === null ? 'success' : 'warning',
            'message' => $warning ?? 'Milestone added.',
        ]);
    }

    public function show(Request $request, ProjectMilestone $milestone): View
    {
        $this->authorize('view', $milestone);

        return view('admin.milestones.show', [
            'milestone' => $milestone->load(['project:id,code,name', 'tasks']),
            'statuses' => $this->allowedStatusOptions($milestone),
            'showMoney' => $request->user()->can('projects.view_financial'),
        ]);
    }

    public function update(Request $request, ProjectMilestone $milestone): RedirectResponse
    {
        $this->authorize('update', $milestone);

        $this->milestones->update($milestone, $this->validated($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Milestone updated.']);
    }

    public function destroy(Request $request, ProjectMilestone $milestone): RedirectResponse
    {
        $this->authorize('delete', $milestone);

        $this->milestones->delete($milestone, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Milestone removed; its tasks were detached.']);
    }

    public function status(Request $request, ProjectMilestone $milestone): RedirectResponse
    {
        $this->authorize('changeStatus', $milestone);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(MilestoneStatus::values())],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->milestones->changeStatus(
            $milestone,
            MilestoneStatus::from($data['status']),
            $data['reason'] ?? null,
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Milestone status updated.']);
    }

    public function reorder(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $this->milestones->reorder($project, array_map('intval', $data['order']), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Order saved.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'start_date' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date', 'after_or_equal:start_date'],
            'weight' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];

        // §4.2: the payment value is money, so it needs the money permission — not a milestone ability.
        if ($request->user()->can('projects.view_financial')) {
            $rules['amount'] = ['nullable', 'numeric', 'min:0', 'max:9999999999999'];
        }

        return $request->validate($rules);
    }

    /**
     * @return array<string, string>
     */
    private function allowedStatusOptions(ProjectMilestone $milestone): array
    {
        $options = [];

        foreach ($milestone->status->allowedTransitions() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
