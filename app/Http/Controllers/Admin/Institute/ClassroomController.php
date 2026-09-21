<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\ClassroomType;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Institute\Classroom;
use App\Services\Institute\ClassroomService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rooms — `admin.classrooms.*` (phase-14-17 §7.5, §8.10).
 *
 * Everything happens on the index: a room is six fields, and giving it a page of its own would mean
 * three screens to add a cupboard with a projector in it.
 */
final class ClassroomController extends Controller
{
    public function __construct(
        private readonly ClassroomService $rooms,
    ) {}

    public function index(Request $request): View
    {
        $rooms = $this->filtered($request)
            ->withCount(['batches', 'timetableEntries'])
            ->orderBy('code')
            ->paginate(per_page())
            ->withQueryString();

        return view('admin.classrooms.index', [
            'rooms' => $rooms,
            'types' => ClassroomType::options(),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only(['type', 'active', 'q']),
            'counts' => [
                'all' => $this->filtered($request)->count(),
                'active' => $this->filtered($request)->where('is_active', true)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $room = $this->rooms->create($this->validated($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => $room->label().' has been added.']);
    }

    public function update(Request $request, Classroom $classroom): RedirectResponse
    {
        $this->rooms->update($classroom, $this->validated($request, $classroom), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Saved.']);
    }

    public function toggle(Request $request, Classroom $classroom): RedirectResponse
    {
        $this->rooms->setActive($classroom, ! $classroom->is_active, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $classroom->label().' is now '.($classroom->fresh()->is_active ? 'open' : 'closed').'.',
        ]);
    }

    public function destroy(Classroom $classroom): RedirectResponse
    {
        $label = $classroom->label();

        $this->rooms->delete($classroom);

        return back()->with('toast', ['type' => 'success', 'message' => $label.' has been removed.']);
    }

    private function filtered(Request $request): Builder
    {
        return Classroom::query()
            ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->when($request->filled('active'), fn (Builder $q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->filled('q'), function (Builder $q) use ($request): void {
                $term = '%'.$request->string('q').'%';

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('code', 'like', $term)
                        ->orWhere('name', 'like', $term)
                        ->orWhere('location', 'like', $term);
                });
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Classroom $room = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('classrooms', 'code')->ignore($room?->getKey())->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(ClassroomType::class)],
            'capacity' => ['required', 'integer', 'min:1', 'max:5000'],
            'location' => ['nullable', 'string', 'max:150'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
