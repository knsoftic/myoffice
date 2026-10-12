<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\AttendanceMarkSource;
use App\Enums\ClassSessionStatus;
use App\Enums\StudentAttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StoreStudentAttendanceMarksRequest;
use App\Models\Institute\Batch;
use App\Models\Institute\ClassSession;
use App\Models\Institute\Student;
use App\Models\Institute\StudentAttendance;
use App\Models\Institute\StudentBatchEnrollment;
use App\Services\Institute\AttendanceService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The student register as JSON — the same register the marking screen keeps, for a script or a front end
 * that wants to read or take it without the page.
 *
 * **Nothing here is a second register.** Every read is `student_attendances` and the enrolment counters,
 * every write goes through `AttendanceService::mark()`, so the roster check (INV-I9), the late promotion,
 * the session stamp and the percentage (INV-I11) are exactly what the screen gets. Signed-in staff only,
 * through the admin panel's session and CSRF, with the module's own permissions; every batch, class and
 * student is narrowed to the user's branch, and anything outside it answers 404.
 */
final class AttendanceApiController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * `GET ?batch_id=&date=` — the classes of one batch on one date, each with its roster and whatever is
     * already marked. A date with no class answers an empty list: attendance is taken per class, so a day
     * without one has nothing to mark until a class is added under Batches → Classes.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $batch = $this->batch($request, (int) $data['batch_id']);
        $date = Carbon::parse($data['date'])->startOfDay();

        return response()->json([
            'batch' => ['id' => (int) $batch->getKey(), 'code' => $batch->code, 'name' => $batch->name],
            'date' => $date->toDateString(),
            'statuses' => $this->statuses(),
            'sessions' => $this->sessionsOn($request, $batch, $date)
                ->map(fn (ClassSession $session): array => $this->sessionPayload($session))
                ->values(),
        ]);
    }

    /**
     * `POST` — record a register. Names the class by `session_id`, or by `batch_id` + `date` when the batch
     * has exactly one class that day. `marks` is a list of `{student_id, status, check_in_time?, remarks?}`;
     * marking again updates rather than duplicates (`uq_sa_session_student`).
     */
    public function store(StoreStudentAttendanceMarksRequest $request): JsonResponse
    {
        $data = $request->validated();

        $session = $this->sessionFor($request, $data);

        $marks = [];

        foreach ($data['marks'] as $mark) {
            $marks[(int) $mark['student_id']] = [
                'status' => $mark['status'],
                'check_in_time' => $mark['check_in_time'] ?? null,
                'remarks' => $mark['remarks'] ?? null,
            ];
        }

        $result = $this->attendance->mark($session, $marks, ['marked_via' => AttendanceMarkSource::Manual], $request->user());

        return response()->json([
            'message' => sprintf('%d marked, %d updated.', $result->created, $result->updated),
            'result' => $result->toArray(),
            'session' => $this->sessionPayload($session->fresh(['batch:id,code,name', 'course:id,name', 'teacher:id,name', 'classroom:id,code,name'])),
        ]);
    }

    /**
     * `GET summary/{student}` — the student's counters and percentage per enrolment, plus a total. The
     * figures are the enrolment counters the service maintains (INV-I11), so they agree with every report.
     */
    public function summary(Request $request, int $student): JsonResponse
    {
        $record = Student::query()->forBranch($this->branch($request))->find($student);

        abort_if($record === null, 404);

        $minimum = (string) setting('institute.attendance_minimum_percentage', 75);

        $enrollments = StudentBatchEnrollment::query()
            ->with(['batch:id,code,name', 'course:id,name'])
            ->where('student_id', $record->getKey())
            ->orderByDesc('id')
            ->get();

        $totals = ['classes' => 0, 'present' => 0, 'absent' => 0, 'leave' => 0, 'late' => 0];

        $rows = $enrollments->map(function (StudentBatchEnrollment $enrollment) use (&$totals, $minimum): array {
            $row = [
                'classes' => (int) $enrollment->sessions_expected_count,
                'present' => (int) $enrollment->present_count,
                'absent' => (int) $enrollment->absent_count,
                'leave' => (int) $enrollment->leave_count,
                'late' => (int) $enrollment->late_count,
            ];

            foreach ($row as $key => $count) {
                $totals[$key] += $count;
            }

            return [
                'enrollment_id' => (int) $enrollment->getKey(),
                'batch' => $enrollment->batch === null ? null : ['id' => (int) $enrollment->batch->getKey(), 'code' => $enrollment->batch->code, 'name' => $enrollment->batch->name],
                'course' => $enrollment->course === null ? null : ['id' => (int) $enrollment->course->getKey(), 'name' => $enrollment->course->name],
                'enrollment_status' => $enrollment->status?->value,
            ] + $row + [
                'percentage' => Money::round((string) $enrollment->attendance_percentage, 2),
                'below_minimum' => $row['classes'] > 0 && Money::compare((string) $enrollment->attendance_percentage, $minimum) < 0,
            ];
        })->values();

        // The same formula as each enrolment: attended (present + late) over the classes counted.
        $overall = $totals['classes'] === 0
            ? '0.00'
            : Money::percentageOf((string) ($totals['present'] + $totals['late']), (string) $totals['classes'], 2);

        return response()->json([
            'student' => ['id' => (int) $record->getKey(), 'name' => $record->name, 'student_code' => $record->student_code],
            'minimum_percentage' => Money::round($minimum, 2),
            'totals' => $totals + ['percentage' => Money::round($overall, 2)],
            'enrollments' => $rows,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function branch(Request $request): ?int
    {
        $branch = $request->user()?->branch_id;

        return $branch === null ? null : (int) $branch;
    }

    private function batch(Request $request, int $id): Batch
    {
        $batch = Batch::query()->forBranch($this->branch($request))->find($id);

        abort_if($batch === null, 404);

        return $batch;
    }

    /**
     * @return Collection<int, ClassSession>
     */
    private function sessionsOn(Request $request, Batch $batch, Carbon $date): Collection
    {
        return ClassSession::query()
            ->forBranch($this->branch($request))
            ->with(['batch:id,code,name', 'course:id,name', 'teacher:id,name', 'classroom:id,code,name'])
            ->where('batch_id', $batch->getKey())
            ->whereDate('session_date', $date->toDateString())
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sessionFor(Request $request, array $data): ClassSession
    {
        if (! empty($data['session_id'])) {
            $session = ClassSession::query()
                ->forBranch($this->branch($request))
                ->with(['batch:id,code,name', 'course:id,name', 'teacher:id,name', 'classroom:id,code,name'])
                ->find((int) $data['session_id']);

            abort_if($session === null, 404);

            return $session;
        }

        $batch = $this->batch($request, (int) $data['batch_id']);
        $sessions = $this->sessionsOn($request, $batch, Carbon::parse((string) $data['date']));

        if ($sessions->count() !== 1) {
            throw ValidationException::withMessages([
                'session_id' => $sessions->isEmpty()
                    ? sprintf('%s has no class on %s. Add one under Batches → Classes, then mark it.', $batch->code, $data['date'])
                    : sprintf('%s has %d classes on %s — name the one being marked with session_id.', $batch->code, $sessions->count(), $data['date']),
            ]);
        }

        return $sessions->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(ClassSession $session): array
    {
        $roster = $this->attendance->roster($session);
        $counts = array_fill_keys(array_map(static fn (StudentAttendanceStatus $s): string => $s->value, StudentAttendanceStatus::cases()), 0);
        $counts['unmarked'] = 0;

        $students = $roster->map(function (StudentBatchEnrollment $enrollment) use (&$counts): array {
            /** @var StudentAttendance|null $mark */
            $mark = $enrollment->getRelation('attendanceForSession');

            if ($mark === null) {
                $counts['unmarked']++;
            } else {
                $counts[$mark->status->value]++;
            }

            return [
                'student_id' => (int) $enrollment->student_id,
                'enrollment_id' => (int) $enrollment->getKey(),
                'roll_number' => $enrollment->roll_number,
                'name' => $enrollment->student?->name,
                'student_code' => $enrollment->student?->student_code,
                'attendance' => $mark === null ? null : [
                    'id' => (int) $mark->getKey(),
                    'status' => $mark->status->value,
                    'check_in_time' => $mark->check_in_time,
                    'minutes_late' => $mark->minutes_late,
                    'remarks' => $mark->remarks,
                    'marked_at' => $mark->marked_at?->toIso8601String(),
                    'amended' => $mark->wasAmended(),
                ],
            ];
        })->values();

        return [
            'id' => (int) $session->getKey(),
            'date' => $session->session_date->toDateString(),
            'start_time' => $session->start_time,
            'end_time' => $session->end_time,
            'status' => $session->status->value,
            'can_be_marked' => ! in_array($session->status, [ClassSessionStatus::Cancelled, ClassSessionStatus::Rescheduled], true),
            'course' => $session->course === null ? null : ['id' => (int) $session->course->getKey(), 'name' => $session->course->name],
            'teacher' => $session->teacher === null ? null : ['id' => (int) $session->teacher->getKey(), 'name' => $session->teacher->name],
            'classroom' => $session->classroom === null ? null : ['id' => (int) $session->classroom->getKey(), 'code' => $session->classroom->code],
            'counts' => $counts,
            'students' => $students,
        ];
    }

    /**
     * @return list<array{value: string, label: string, glyph: string}>
     */
    private function statuses(): array
    {
        return array_map(static fn (StudentAttendanceStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
            'glyph' => $status->glyph(),
        ], StudentAttendanceStatus::cases());
    }
}
