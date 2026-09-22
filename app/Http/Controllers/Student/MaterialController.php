<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Enums\MaterialAccessAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesTheSignedInStudent;
use App\Models\Institute\CourseMaterial;
use App\Services\Institute\MaterialAccessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A student's own material library — `student.materials.*` (§74, phase-19-23 §7.9, §8.8).
 *
 * **Every query is scoped to the `students` row this user *is*, never to an id in the URL.** A material
 * that is not theirs does not resolve, so it is a **404** and not a 403 — an id that answers differently
 * depending on whether it exists is an id somebody can enumerate.
 *
 * **The list and the download ask the same question.** `MaterialAccessService::visibleToStudent()` and
 * `grantFor()` are the two faces of INV-19-3, and the download action runs the grant again rather than
 * trusting that the link was on a page the student was allowed to see. §6.2 [D-19-4] is the reason: a
 * student suspended, un-enrolled or fee-blocked five minutes ago must not still get the bytes.
 */
final class MaterialController extends Controller
{
    use ResolvesTheSignedInStudent;

    public function __construct(
        private readonly MaterialAccessService $access,
    ) {}

    public function index(Request $request): View
    {
        $student = $this->student($request);

        $materials = $this->access->visibleToStudent($student)
            ->with(['course:id,name,code', 'topic:id,title'])
            ->when($request->string('q')->toString() !== '', function ($q) use ($request): void {
                $q->where('title', 'like', '%'.$request->string('q')->toString().'%');
            })
            ->when($request->integer('course_id') > 0, fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->orderByDesc('published_at')
            ->paginate(20)
            ->withQueryString();

        return view('student.materials.index', [
            'materials' => $materials,
            'canDownload' => (bool) $request->user()?->can('student_portal.material_download'),
        ]);
    }

    public function show(Request $request, CourseMaterial $material): View
    {
        $student = $this->student($request);

        // The list query is the gate: a material outside it is not theirs, and saying so would
        // confirm it exists.
        abort_unless(
            $this->access->visibleToStudent($student)->whereKey($material->getKey())->exists(),
            Response::HTTP_NOT_FOUND,
        );

        return view('student.materials.show', [
            'material' => $material->load(['course:id,name', 'topic:id,title']),
            'grant' => $this->access->grantFor($material, $request->user()),
        ]);
    }

    /**
     * §6.4 steps 3–7, run again at the moment the bytes are asked for.
     *
     * `is_downloadable = false` comes back as a `view` and an inline disposition — §6.5 says outright
     * that this is deterrence and not DRM, and the request does not get to choose which it was.
     */
    public function download(Request $request, CourseMaterial $material): StreamedResponse
    {
        return $this->access->stream(
            $material,
            $request->user(),
            MaterialAccessAction::Download,
        );
    }

    /** A `link` material: the same grant, the same log, then a 302 with no referrer. */
    public function open(Request $request, CourseMaterial $material): RedirectResponse
    {
        return $this->access->openLink($material, $request->user());
    }
}
