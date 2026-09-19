<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\SocialPlatform;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\ReorderRequest;
use App\Http\Requests\Cms\StoreTeamMemberRequest;
use App\Http\Requests\Cms\UpdateContentStatusRequest;
use App\Http\Requests\Cms\UpdateTeamMemberRequest;
use App\Models\Cms\TeamMember;
use App\Services\Cms\ContentOrderService;
use App\Services\Cms\TeamService;
use App\Support\SlugGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public team page's members — `admin.team.*` (phase-04 §2.9, §6.4, §7.2, §8.4), `module:team`.
 *
 * Writes: `TeamService` (store, update, togglePublic, changeStatus, delete) and
 * `ContentOrderService::reorder()`. The form's *Visibility* tab carries `status` and `is_public`; saving
 * a change to either needs `team.change_status` as well as `team.edit`, exactly as the dedicated
 * status and visibility endpoints do, so an editor cannot publish a profile by saving it.
 */
final class TeamMemberController extends Controller
{
    use RespondsForContent;

    private const SORTABLE = ['name', 'designation', 'department', 'experience_years', 'status', 'sort_order', 'updated_at'];

    public function __construct(
        private readonly TeamService $team,
        private readonly ContentOrderService $order,
    ) {}

    public function index(ContentListRequest $request): View
    {
        $this->authorize('team.view_any');

        $user = $this->actor($request);
        $trashed = $this->wantsTrashed($request, $user, 'team');
        $sort = $request->sortColumn(self::SORTABLE, 'sort_order');
        $direction = $request->sortDirection('asc');
        $search = $request->searchTerm();
        $department = $request->filterString('department');
        $status = $request->filterEnum('status', ContentStatus::class);
        $visibility = $request->filterString('visibility');

        $members = $this->withAvailable(TeamMember::query(), ['photo'])
            ->when($trashed, static fn (Builder $query) => $query->onlyTrashed())
            ->when($department !== null, static fn (Builder $query) => $query->where('department', $department))
            ->when($status instanceof ContentStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($visibility === 'public', static fn (Builder $query) => $query->where('is_public', true))
            ->when($visibility === 'hidden', static fn (Builder $query) => $query->where('is_public', false))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', $this->like($search))
                    ->orWhere('designation', 'like', $this->like($search));
            }))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $filters = $request->activeFilters();
        $visibility = $this->countBy(TeamMember::query(), 'is_public');

        return view('admin.team.index', [
            'members' => $members,
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'trashed' => $trashed,
            'departmentOptions' => $this->departmentOptions(),
            'statusOptions' => ContentStatus::options(),
            'socialPlatforms' => $this->socialPlatforms(),
            'counts' => [
                'all' => array_sum($visibility),
                'public' => $visibility['1'] ?? 0,
                'hidden' => $visibility['0'] ?? 0,
                'draft' => TeamMember::query()->where('status', ContentStatus::Draft->value)->count(),
                'trashed' => $user->can('team.restore') ? TeamMember::query()->onlyTrashed()->count() : 0,
            ],
            'canReorder' => ! $trashed && $filters === [] && $members->lastPage() === 1 && $user->can('team.edit'),
            'can' => [
                'create' => $user->can('team.create'),
                'edit' => $user->can('team.edit'),
                'delete' => $user->can('team.delete'),
                'changeStatus' => $user->can('team.change_status'),
                'restore' => $user->can('team.restore'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('team.create');

        return view('admin.team.create', array_merge($this->formData($request), [
            'member' => (new TeamMember)->forceFill(['status' => ContentStatus::Draft->value, 'is_public' => true, 'sort_order' => 0]),
        ]));
    }

    public function store(StoreTeamMemberRequest $request): Response
    {
        $this->authorize('team.create');

        $status = $request->requestedStatus();
        $public = $request->requestedPublic();
        $needsStatusAbility = ($status !== null && $status !== ContentStatus::Draft) || $public === false;

        if ($needsStatusAbility) {
            $this->authorize('team.change_status');
        }

        return $this->attempt($request, function () use ($request, $status, $public): Response {
            $member = DB::transaction(function () use ($request, $status, $public): TeamMember {
                $member = $this->team->store($request->teamMemberPayload(), $request->uploadedImage('photo'));

                if ($status !== null && $status !== ContentStatus::Draft) {
                    $member = $this->team->changeStatus($member, $status);
                }

                if ($public !== null && (bool) $member->is_public !== $public) {
                    $member = $this->team->togglePublic($member);
                }

                return $member;
            });

            return $this->done(
                $request,
                sprintf('%s was added to the team.', $member->name),
                redirect()->route('admin.team.edit', $member),
                ['id' => (int) $member->getKey()],
            );
        }, field: 'name');
    }

    public function show(Request $request, TeamMember $member): Response
    {
        $this->authorize('team.view');
        $this->authorize('view', $member);

        return redirect()->route('admin.team.edit', $member);
    }

    public function edit(Request $request, TeamMember $member): View
    {
        $this->authorize('team.edit');
        $this->authorize('update', $member);

        $this->loadAvailable($member, ['photo', 'editor']);
        $user = $this->actor($request);

        return view('admin.team.edit', array_merge($this->formData($request), [
            'member' => $member,
            'can' => [
                'edit' => $user->can('update', $member),
                'delete' => $user->can('team.delete') && $user->can('delete', $member),
                'changeStatus' => $user->can('team.change_status'),
            ],
        ]));
    }

    public function update(UpdateTeamMemberRequest $request, TeamMember $member): Response
    {
        $this->authorize('team.edit');
        $this->authorize('update', $member);

        $current = $member->status instanceof ContentStatus ? $member->status : ContentStatus::tryFrom((string) $member->status);
        $status = $request->requestedStatus();
        $public = $request->requestedPublic();
        $statusChanges = $status !== null && $status !== $current;
        $publicChanges = $public !== null && $public !== (bool) $member->is_public;

        if ($statusChanges || $publicChanges) {
            $this->authorize('team.change_status');
        }

        return $this->attempt($request, function () use ($request, $member, $status, $statusChanges, $publicChanges): Response {
            $member = DB::transaction(function () use ($request, $member, $status, $statusChanges, $publicChanges): TeamMember {
                $member = $this->team->update($member, $request->teamMemberPayload(), $request->uploadedImage('photo'));

                if ($statusChanges && $status !== null) {
                    $member = $this->team->changeStatus($member, $status);
                }

                if ($publicChanges) {
                    $member = $this->team->togglePublic($member);
                }

                return $member;
            });

            return $this->done($request, sprintf('%s was saved.', $member->name), redirect()->route('admin.team.edit', $member));
        }, field: 'name');
    }

    public function destroy(Request $request, TeamMember $member): Response
    {
        $this->authorize('team.delete');
        $this->authorize('delete', $member);

        $name = $member->name;

        return $this->attempt($request, function () use ($request, $member, $name): Response {
            $this->team->delete($member);

            return $this->done($request, sprintf('%s was removed from the team.', $name), redirect()->route('admin.team.index'));
        }, Response::HTTP_FORBIDDEN);
    }

    public function reorder(ReorderRequest $request): Response
    {
        $this->authorize('team.edit');

        return $this->attempt($request, function () use ($request): Response {
            $this->order->reorder(TeamMember::class, $request->orderedIds());

            return $this->done($request, 'Team order saved.', null, ['ids' => $request->orderedIds()]);
        }, field: 'ids');
    }

    public function status(UpdateContentStatusRequest $request, TeamMember $member): Response
    {
        $this->authorize('team.change_status');
        $this->authorize('changeStatus', $member);

        $status = $request->contentStatus();

        return $this->attempt($request, function () use ($request, $member, $status): Response {
            $member = $this->team->changeStatus($member, $status);

            return $this->done(
                $request,
                sprintf('%s is now %s.', $member->name, mb_strtolower($status->label())),
                null,
                ['id' => (int) $member->getKey(), 'status' => $status->value],
            );
        }, field: 'status');
    }

    /**
     * Show or hide a member on the website without unpublishing the profile (§2.9 `is_public`).
     */
    public function visibility(Request $request, TeamMember $member): Response
    {
        $this->authorize('team.change_status');
        $this->authorize('togglePublic', $member);

        return $this->attempt($request, function () use ($request, $member): Response {
            $member = $this->team->togglePublic($member);
            $public = (bool) $member->is_public;

            return $this->done(
                $request,
                $public ? sprintf('%s is visible on the website.', $member->name) : sprintf('%s is hidden from the website.', $member->name),
                null,
                ['id' => (int) $member->getKey(), 'is_public' => $public],
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        return [
            'statusOptions' => ContentStatus::options(),
            'socialPlatforms' => $this->socialPlatforms(),
            'departmentOptions' => $this->departmentOptions(),
            'reservedSlugs' => SlugGenerator::RESERVED,
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'canChangeStatus' => $this->actor($request)->can('team.change_status'),
        ];
    }

    /**
     * The *Links* tab rows: `SocialPlatform` value => label and icon, in display order. The form offers
     * exactly these keys; the request refuses any other.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    private function socialPlatforms(): array
    {
        $platforms = [];

        foreach (SocialPlatform::cases() as $platform) {
            $platforms[$platform->value] = ['label' => $platform->label(), 'icon' => $platform->icon()];
        }

        return $platforms;
    }

    /**
     * Existing department labels (suggestions only — the column is free text until Phase 7).
     *
     * @return list<string>
     */
    private function departmentOptions(): array
    {
        return TeamMember::query()
            ->whereNotNull('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department')
            ->map(static fn (mixed $department): string => (string) $department)
            ->all();
    }
}
