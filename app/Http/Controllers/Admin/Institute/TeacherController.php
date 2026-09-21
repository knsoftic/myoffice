<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\Gender;
use App\Enums\TeacherStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Institute\Course;
use App\Models\Institute\Teacher;
use App\Services\Institute\TeacherService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Teachers — `admin.teachers.*` (§72, phase-14-17 §7.5, §8.10).
 *
 * **`salary` never reaches a view without `teachers.view_financial`.** It is stripped from the
 * validated payload rather than hidden in the template, because a hidden field is still a field
 * somebody can post.
 */
final class TeacherController extends Controller
{
    public function __construct(
        private readonly TeacherService $teachers,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.teachers.index', [
            'teachers' => $this->filtered($request)
                ->withCount(['batches', 'courses'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->paginate(per_page())
                ->withQueryString(),
            'statuses' => TeacherStatus::options(),
            'filters' => $request->only(['status', 'q', 'course_id', 'public']),
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'counts' => $this->counts($request),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.teachers.create', $this->formData($request) + [
            'teacher' => new Teacher(['is_public' => false]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $teacher = $this->teachers->create($this->validated($request), $request->user());

        return redirect()
            ->route('admin.teachers.show', $teacher)
            ->with('toast', ['type' => 'success', 'message' => $teacher->name.' has been added, with code '.$teacher->teacher_code.'.']);
    }

    public function show(Request $request, Teacher $teacher): View
    {
        return view('admin.teachers.show', [
            'teacher' => $teacher->load(['courses:id,name', 'branch:id,name', 'user:id,email,status']),
            'batches' => $teacher->batches()->with('course:id,name')->orderByDesc('start_date')->limit(10)->get(),
            'upcoming' => $teacher->sessions()
                ->with('batch:id,code,name')
                ->where('status', 'scheduled')
                ->whereDate('session_date', '>=', Carbon::today()->toDateString())
                ->orderBy('session_date')
                ->limit(10)
                ->get(),
            'statuses' => TeacherStatus::options(),
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'canSeeSalary' => $request->user()?->can('viewFinancial', $teacher) ?? false,
        ]);
    }

    public function edit(Request $request, Teacher $teacher): View
    {
        return view('admin.teachers.edit', $this->formData($request) + ['teacher' => $teacher]);
    }

    public function update(Request $request, Teacher $teacher): RedirectResponse
    {
        $this->teachers->update($teacher, $this->validated($request, $teacher), $request->user());

        return redirect()
            ->route('admin.teachers.show', $teacher)
            ->with('toast', ['type' => 'success', 'message' => 'Saved.']);
    }

    public function destroy(Teacher $teacher): RedirectResponse
    {
        $teacher->delete();

        return redirect()
            ->route('admin.teachers.index')
            ->with('toast', ['type' => 'success', 'message' => $teacher->name.' has been removed.']);
    }

    public function status(Request $request, Teacher $teacher): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(TeacherStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->teachers->changeStatus(
            $teacher,
            TeacherStatus::from($validated['status']),
            $validated['reason'] ?? null,
            $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => $teacher->name.' is now '.TeacherStatus::from($validated['status'])->label().'.',
        ]);
    }

    public function courses(Request $request, Teacher $teacher): RedirectResponse
    {
        $validated = $request->validate([
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['integer', Rule::exists('courses', 'id')->whereNull('deleted_at')],
            'primary_course_id' => ['nullable', 'integer'],
        ]);

        $this->teachers->assignCourses(
            $teacher,
            $validated['course_ids'] ?? [],
            isset($validated['primary_course_id']) ? (int) $validated['primary_course_id'] : null,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Courses updated.']);
    }

    public function createLogin(Request $request, Teacher $teacher): RedirectResponse
    {
        $user = $this->teachers->createLogin($teacher, $request->user());

        return back()->with('toast', $user === null
            ? ['type' => 'info', 'message' => 'No login was created: teacher logins are switched off, or this teacher has no email address.']
            : ['type' => 'success', 'message' => 'A login has been created. The password was sent to '.$user->email.' and must be changed on first sign-in.']);
    }

    public function linkEmployee(Request $request, Teacher $teacher): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->teachers->linkEmployee($teacher, (int) $validated['employee_id'], $validated['reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Linked to the employee record.']);
    }

    public function unlinkEmployee(Request $request, Teacher $teacher): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->teachers->unlinkEmployee($teacher, $validated['reason'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'The employee link has been removed.']);
    }

    /** The §99 report: the week, the hours, and whether the registers were filled in. */
    public function workload(Request $request, Teacher $teacher): View
    {
        $from = Carbon::parse((string) $request->input('from', Carbon::today()->startOfMonth()->toDateString()));
        $to = Carbon::parse((string) $request->input('to', Carbon::today()->endOfMonth()->toDateString()));

        return view('admin.teachers.workload', [
            'teacher' => $teacher,
            'report' => $this->teachers->workload($teacher, $from, $to),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless(in_array($format, ['csv'], true), 404);

        $rows = $this->filtered($request)->orderBy('name')->get();
        $canSeeSalary = $request->user()?->can('teachers.view_financial') ?? false;

        return response()->streamDownload(function () use ($rows, $canSeeSalary): void {
            $handle = fopen('php://output', 'wb');

            $header = ['Code', 'Name', 'Email', 'Phone', 'Status', 'Specialization', 'Joined'];

            if ($canSeeSalary) {
                $header[] = 'Salary';
            }

            fputcsv($handle, $header);

            foreach ($rows as $teacher) {
                $line = [
                    $teacher->teacher_code,
                    $teacher->name,
                    $teacher->email,
                    $teacher->phone,
                    $teacher->status->label(),
                    $teacher->specialization,
                    $teacher->joining_date?->toDateString(),
                ];

                if ($canSeeSalary) {
                    $line[] = $teacher->salary;
                }

                fputcsv($handle, $line);
            }

            fclose($handle);
        }, 'teachers-'.Carbon::now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function filtered(Request $request): Builder
    {
        return Teacher::query()
            ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('public'), fn (Builder $q) => $q->where('is_public', $request->boolean('public')))
            ->when($request->filled('course_id'), fn (Builder $q) => $q->whereHas(
                'courses',
                fn (Builder $c) => $c->where('courses.id', $request->integer('course_id')),
            ))
            ->when($request->filled('q'), function (Builder $q) use ($request): void {
                $term = '%'.$request->string('q').'%';

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('teacher_code', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('specialization', 'like', $term);
                });
            });
    }

    /**
     * @return array<string, int>
     */
    private function counts(Request $request): array
    {
        $base = $this->filtered($request);

        $counts = ['all' => (clone $base)->count()];

        foreach (TeacherStatus::cases() as $status) {
            $counts[$status->value] = (clone $base)->where('status', $status->value)->count();
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        return [
            'statuses' => TeacherStatus::options(),
            'genders' => Gender::options(),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'canSeeSalary' => $request->user()?->can('teachers.view_financial') ?? false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Teacher $teacher = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email:rfc', 'max:180'],
            'phone' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'slug' => [
                'nullable', 'string', 'max:170', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('teachers', 'slug')->ignore($teacher?->getKey())->whereNull('deleted_at'),
            ],
            'qualification' => ['nullable', 'string', 'max:255'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:80'],
            'experience_note' => ['nullable', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['string', 'max:60'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'public_bio' => ['nullable', 'string', 'max:5000'],
            'joining_date' => ['nullable', 'date'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'is_public' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'create_login' => ['nullable', 'boolean'],
        ]);

        $validated['is_public'] = $request->boolean('is_public');
        $validated['create_login'] = $request->boolean('create_login');

        // The salary is not merely hidden from the form: without the ability, a posted value is
        // discarded before it reaches the service.
        if (! ($request->user()?->can('teachers.view_financial') ?? false)) {
            unset($validated['salary']);
        }

        return $validated;
    }
}
