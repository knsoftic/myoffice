<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ProjectMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectMemberRequest;
use App\Models\Project\Project;
use App\Models\Project\ProjectMember;
use App\Models\User;
use App\Services\Project\ProjectService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The team tab — `admin.projects.members.*` (phase-06 §7.1, §8.6), `module:projects`.
 *
 * Removing somebody returns their **open tasks** to this screen rather than unassigning them quietly: a
 * task with nobody on it and no warning is how work disappears. The removal itself is a soft delete, so
 * "who was on this project in March" stays answerable (INV-P11).
 */
final class ProjectMemberController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ProjectService $projects) {}

    public function index(Request $request, Project $project): View
    {
        $this->authorize('view', $project);

        return view('admin.projects.members', [
            'project' => $project,
            'members' => $project->members()->with('user:id,name,email')->get(),
            'roles' => ProjectMemberRole::options(),
            'staff' => $this->staffOptions($project),
            'canAssign' => $request->user()->can('assign', $project),
        ]);
    }

    public function store(StoreProjectMemberRequest $request, Project $project): RedirectResponse
    {
        $data = $request->validated();

        $this->projects->addMember(
            $project,
            isset($data['user_id']) ? User::query()->find($data['user_id']) : null,
            $data['collaborator_id'] ?? null,
            ProjectMemberRole::from($data['role']),
            $data['notes'] ?? null,
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Added to the team.']);
    }

    public function update(Request $request, Project $project, ProjectMember $member): RedirectResponse
    {
        $this->authorize('update', $member);

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(ProjectMemberRole::values())],
        ]);

        $this->projects->updateMemberRole($member, ProjectMemberRole::from($data['role']), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Role updated.']);
    }

    public function destroy(Request $request, Project $project, ProjectMember $member): RedirectResponse
    {
        $this->authorize('delete', $member);

        $orphaned = $this->projects->removeMember($member, $request->user());

        return back()->with('toast', [
            'type' => $orphaned->isEmpty() ? 'success' : 'warning',
            'message' => $orphaned->isEmpty()
                ? 'Removed from the team.'
                : sprintf(
                    'Removed from the team. %d open task(s) are still assigned to them: %s.',
                    $orphaned->count(),
                    $orphaned->pluck('title')->take(5)->implode(', ')
                ),
        ]);
    }

    /**
     * Staff who are not already on this team ([D-P6-2]: the picker is users, filtered to admin roles).
     *
     * @return array<int, string>
     */
    private function staffOptions(Project $project): array
    {
        $taken = $project->members()->whereNotNull('user_id')->pluck('user_id')->all();

        return User::query()
            ->whereHas('roles', fn (Builder $role) => $role->where('panel', 'admin'))
            ->whereNotIn('id', $taken)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
