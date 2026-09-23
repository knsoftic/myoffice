<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StoreGradeScaleRequest;
use App\Models\Institute\GradeScale;
use App\Services\Institute\Exceptions\InvalidGradeScale;
use App\Services\Institute\GradeScaleService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Grade scales — `admin.grade-scales.*` (§81, phase-19-23 §7.3).
 *
 * **`InvalidGradeScale` is caught and turned into a field error, not left to bubble.** The geometry
 * rules it reports — a gap at 79.5, two bands both claiming 80, nothing marked passing — are things a
 * person typing a scale gets wrong on the way to getting it right, and an error page loses the twelve
 * rows they had already filled in. Every other refusal is a bug and is allowed to surface as one.
 */
final class GradeScaleController extends Controller
{
    public function __construct(
        private readonly GradeScaleService $scales,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', GradeScale::class);

        $scales = GradeScale::query()
            ->withCount('bands')
            ->when($request->string('q')->toString() !== '', function ($query) use ($request): void {
                $term = $request->string('q')->toString();
                $query->where(fn ($q) => $q->where('name', 'like', "%$term%")->orWhere('code', 'like', "%$term%"));
            })
            ->when($request->string('status')->toString() === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->string('status')->toString() === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->boolean('trashed'), fn ($q) => $q->onlyTrashed())
            ->ordered()
            ->paginate(20)
            ->withQueryString();

        return view('admin.grade-scales.index', [
            'scales' => $scales,
            'canCreate' => (bool) $request->user()?->can('create', GradeScale::class),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', GradeScale::class);

        return view('admin.grade-scales.create');
    }

    public function store(StoreGradeScaleRequest $request): RedirectResponse
    {
        try {
            $scale = $this->scales->create(
                $request->safe()->except(['bands']),
                $request->validated('bands', []),
                $request->user(),
            );
        } catch (InvalidGradeScale $e) {
            return $this->bandError($e);
        }

        return redirect()
            ->route('admin.grade-scales.show', $scale)
            ->with('toast', ['type' => 'success', 'message' => 'Grade scale created.']);
    }

    public function show(Request $request, GradeScale $grade_scale): View
    {
        Gate::authorize('view', $grade_scale);

        return view('admin.grade-scales.show', [
            'scale' => $grade_scale->load('bands'),
            'examCount' => $grade_scale->exams()->count(),
            'canEdit' => (bool) $request->user()?->can('update', $grade_scale),
            'canChangeStatus' => (bool) $request->user()?->can('changeStatus', $grade_scale),
            'canDelete' => (bool) $request->user()?->can('delete', $grade_scale),
        ]);
    }

    public function edit(Request $request, GradeScale $grade_scale): View
    {
        Gate::authorize('update', $grade_scale);

        return view('admin.grade-scales.edit', [
            'scale' => $grade_scale->load('bands'),
            // A band that has graded somebody refuses to be deleted, and replacing the set deletes
            // them all — so the screen says so before a coordinator loses their edits to a 422.
            'bandsLocked' => $grade_scale->hasBeenUsed(),
        ]);
    }

    public function update(StoreGradeScaleRequest $request, GradeScale $grade_scale): RedirectResponse
    {
        try {
            $this->scales->update(
                $grade_scale,
                $request->safe()->except(['bands']),
                // Absent means "leave the bands alone". `validated('bands')` would return [] for an
                // absent key, which the service would read as "replace them with nothing".
                $request->has('bands') ? $request->validated('bands') : null,
                $request->user(),
            );
        } catch (InvalidGradeScale $e) {
            return $this->bandError($e);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Grade scale updated.']);
    }

    public function setDefault(Request $request, GradeScale $grade_scale): RedirectResponse
    {
        Gate::authorize('changeStatus', $grade_scale);

        $this->scales->setDefault($grade_scale, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'This is the default now — exams that name no scale of their own will use it.',
        ]);
    }

    public function deactivate(Request $request, GradeScale $grade_scale): RedirectResponse
    {
        Gate::authorize('changeStatus', $grade_scale);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $this->scales->deactivate($grade_scale, $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Retired. Exams already graded against it keep their grades.',
        ]);
    }

    public function destroy(Request $request, GradeScale $grade_scale): RedirectResponse
    {
        Gate::authorize('delete', $grade_scale);

        $grade_scale->delete();

        return redirect()
            ->route('admin.grade-scales.index')
            ->with('toast', ['type' => 'success', 'message' => 'Grade scale removed.']);
    }

    // -------------------------------------------------------------------------------------------

    /**
     * Put a geometry refusal back on the bands, with what they had typed, rather than on an error page.
     */
    private function bandError(InvalidGradeScale $e): RedirectResponse
    {
        return back()
            ->withInput()
            ->withErrors(['bands' => $e->getMessage()])
            ->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
    }
}
