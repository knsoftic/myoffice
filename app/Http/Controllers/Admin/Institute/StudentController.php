<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StoreStudentRequest;
use App\Models\Branch;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Services\Institute\StudentService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The student directory — `admin.students.*` (§66, phase-14-17 §7.4, §8.7).
 *
 * **The fee column is not rendered without `student_fees.view_financial`, and it is absent rather
 * than blank.** That is Phase 13's discipline (`FinanceVisibility`) applied here: a column of dashes
 * tells a reader there is money they may not see, which is itself information.
 *
 * **Import is deliberately a stub that refuses.** §7.4 lists the route; a half-built importer that
 * accepted a file and silently dropped rows would be worse than one that says it is not ready — and
 * the route exists so the permission and the manifest row are true from day one.
 */
final class StudentController extends Controller
{
    public function __construct(
        private readonly StudentService $students,
    ) {}

    public function index(Request $request): View
    {
        $query = $this->filtered($request);

        return view('admin.students.index', [
            'students' => $query->clone()
                ->with(['branch:id,name', 'collaborator:id,name'])
                ->withCount('admissions')
                ->orderByDesc('id')
                ->paginate(per_page())
                ->withQueryString(),
            'stats' => $this->stats($request),
            'statuses' => StudentStatus::options(),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only(['status', 'branch_id', 'course_id', 'gender', 'q']),
            'canSeeFees' => $request->user()?->can('student_fees.view_financial') ?? false,
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.students.create', [
            'student' => new Student,
            'genders' => Gender::options(),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(StoreStudentRequest $request): RedirectResponse
    {
        $student = $this->students->create($request->validated(), $request->user());

        return redirect()
            ->route('admin.students.show', $student)
            ->with('toast', ['type' => 'success', 'message' => sprintf('%s added as %s.', $student->name, $student->student_code)]);
    }

    public function show(Request $request, Student $student): View
    {
        $student->load([
            'branch:id,name', 'user:id,name,email,status', 'collaborator:id,name,referral_code',
            'admissions.course:id,name', 'demoClasses.course:id,name',
        ]);

        return view('admin.students.show', [
            'student' => $student,
            'statuses' => StudentStatus::options(),
            'canSeeFees' => $request->user()?->can('student_fees.view_financial') ?? false,
            'canSeeAdmissionMoney' => $request->user()?->can('admissions.view_financial') ?? false,
            // Asked once, here, so the screen can explain instead of offering a delete that the
            // database would refuse.
            'hasHistory' => $student->hasHistory(),
        ]);
    }

    public function edit(Student $student): View
    {
        return view('admin.students.edit', [
            'student' => $student,
            'genders' => Gender::options(),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function update(StoreStudentRequest $request, Student $student): RedirectResponse
    {
        $this->students->update($student, $request->validated(), $request->user());

        return redirect()
            ->route('admin.students.show', $student)
            ->with('toast', ['type' => 'success', 'message' => 'Student updated.']);
    }

    public function destroy(Student $student): RedirectResponse
    {
        $student->delete();

        return redirect()
            ->route('admin.students.index')
            ->with('toast', ['type' => 'success', 'message' => 'Student removed.']);
    }

    public function status(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(StudentStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->students->changeStatus(
            $student,
            StudentStatus::from($validated['status']),
            $validated['reason'] ?? null,
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Status updated.']);
    }

    public function createLogin(Request $request, Student $student): RedirectResponse
    {
        $user = $this->students->createLogin($student, $request->user());

        if ($user === null) {
            return back()->with('toast', [
                'type' => 'warning',
                'message' => 'No login was created: the student has no email address, or automatic '
                    .'logins are switched off in the institute settings.',
            ]);
        }

        // The screen used to say "the password was sent" whatever happened. It is only allowed to
        // say that when the send actually went out, because the operator is the only person who can
        // start a reset and they will not do it if they were told everything worked.
        if ($this->students->credentialsMailFailed()) {
            return back()->with('toast', [
                'type' => 'warning',
                'message' => sprintf(
                    'The login for %s was created, but the email carrying the password could not be sent. '
                    .'The password is not recoverable — issue a password reset for this account, and check '
                    .'Settings → Email.',
                    $user->email,
                ),
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Login created. The password has been emailed to %s and is not shown here.', $user->email),
        ]);
    }

    public function merge(Request $request, Student $student): RedirectResponse
    {
        $request->validate([
            'duplicate_id' => ['required', 'integer', 'different:student', Rule::exists('students', 'id')],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        // Merging moves fee, enrolment and attendance rows between two students, and those tables
        // belong to Phases 16 to 18. Refusing plainly is the honest answer until they exist.
        return back()->with('toast', [
            'type' => 'warning',
            'message' => 'Merging needs the enrolment, attendance and fee tables to move rows between, '
                .'and those arrive with Phases 16 to 18. Until then, mark the duplicate record dropped '
                .'with a reason naming the record that is being kept.',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        return back()->with('toast', [
            'type' => 'warning',
            'message' => 'The student importer is not built yet. A partial importer that dropped rows '
                .'quietly would be worse than none — add students one at a time until it ships.',
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless($format === 'csv', 404);

        $rows = $this->filtered($request)->with('branch:id,name')->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, ['Student code', 'Registration #', 'Name', 'Father name', 'Phone', 'Email', 'CNIC', 'City', 'Status', 'Branch', 'Joined']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->student_code,
                    (string) $row->registration_number,
                    $row->name,
                    (string) $row->father_name,
                    $row->phone,
                    (string) $row->email,
                    (string) $row->formattedCnic(),
                    (string) $row->city,
                    $row->status->label(),
                    (string) $row->branch?->name,
                    $row->joining_date ? app_date($row->joining_date) : '',
                ]);
            }

            fclose($out);
        }, 'students-'.app_date(Carbon::now(), 'Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<Student>
     */
    private function filtered(Request $request)
    {
        return Student::query()
            ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->string('gender')->toString()))
            ->when($request->filled('course_id'), fn ($q) => $q->whereHas(
                'admissions',
                fn ($a) => $a->where('course_id', $request->integer('course_id')),
            ))
            ->search($request->string('q')->toString());
    }

    /**
     * @return array<string, int>
     */
    private function stats(Request $request): array
    {
        $branchId = $request->user()?->branch_id === null ? null : (int) $request->user()->branch_id;
        $scoped = fn () => Student::query()->forBranch($branchId);

        return [
            'total' => (int) $scoped()->count(),
            'active' => (int) $scoped()->active()->count(),
            'new_this_month' => (int) $scoped()->whereBetween('created_at', [
                Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(),
            ])->count(),
            'dropped' => (int) $scoped()->where('status', StudentStatus::Dropped->value)->count(),
        ];
    }
}
