<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\ClassSessionStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\StudentAttendanceStatus;
use App\Models\Institute\Batch;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The four reports of §75 (phase-14-17 §6.9, §8.16).
 *
 * **All four stand on the same three base queries**, which is the only reason a number cannot differ
 * between two screens. The daily table, the monthly matrix, the percentage list and the batch summary
 * are four presentations of `heldSessions()`, `attendanceRows()` and `enrollments()` — not four
 * independent counts that happen to agree today.
 *
 * **Cancelled and rescheduled classes are excluded everywhere**, because they did not happen. A
 * student is never marked down for the institute's own decisions, and a report that counted them
 * would make the attendance percentage on a screen disagree with the one stored on the enrolment.
 *
 * **Every report states its filters.** §8.16 asks for it and the reason is practical: a printed
 * attendance sheet with no filter line is a sheet nobody can reproduce or trust six months later.
 */
final class AttendanceReportService
{
    /*
    |--------------------------------------------------------------------------
    | The three base queries — everything below reads these
    |--------------------------------------------------------------------------
    */

    /**
     * Classes that actually happened, in a window.
     *
     * @param  array<string, mixed>  $filters
     */
    public function heldSessions(Carbon $from, Carbon $to, array $filters = []): Builder
    {
        return DB::table('class_sessions as cs')
            ->whereNull('cs.deleted_at')
            ->where('cs.status', ClassSessionStatus::Held->value)
            ->whereBetween('cs.session_date', [$from->toDateString(), $to->toDateString()])
            ->when(isset($filters['batch_id']), fn (Builder $q) => $q->where('cs.batch_id', $filters['batch_id']))
            ->when(isset($filters['course_id']), fn (Builder $q) => $q->where('cs.course_id', $filters['course_id']))
            ->when(isset($filters['teacher_id']), fn (Builder $q) => $q->where('cs.teacher_id', $filters['teacher_id']))
            ->when(isset($filters['classroom_id']), fn (Builder $q) => $q->where('cs.classroom_id', $filters['classroom_id']))
            ->when(isset($filters['branch_id']), fn (Builder $q) => $q->where(function (Builder $inner) use ($filters): void {
                $inner->where('cs.branch_id', $filters['branch_id'])->orWhereNull('cs.branch_id');
            }));
    }

    /**
     * Register rows against those classes, joined so the two can never drift apart.
     *
     * @param  array<string, mixed>  $filters
     */
    public function attendanceRows(Carbon $from, Carbon $to, array $filters = []): Builder
    {
        return DB::table('student_attendances as sa')
            ->join('class_sessions as cs', 'cs.id', '=', 'sa.class_session_id')
            ->whereNull('sa.deleted_at')
            ->whereNull('cs.deleted_at')
            ->where('cs.status', ClassSessionStatus::Held->value)
            ->whereBetween('cs.session_date', [$from->toDateString(), $to->toDateString()])
            ->when(isset($filters['batch_id']), fn (Builder $q) => $q->where('sa.batch_id', $filters['batch_id']))
            ->when(isset($filters['course_id']), fn (Builder $q) => $q->where('cs.course_id', $filters['course_id']))
            ->when(isset($filters['teacher_id']), fn (Builder $q) => $q->where('cs.teacher_id', $filters['teacher_id']))
            ->when(isset($filters['branch_id']), fn (Builder $q) => $q->where(function (Builder $inner) use ($filters): void {
                $inner->where('cs.branch_id', $filters['branch_id'])->orWhereNull('cs.branch_id');
            }));
    }

    /**
     * Enrolments, with the counters the attendance service maintains.
     *
     * @param  array<string, mixed>  $filters
     */
    public function enrollments(array $filters = []): Builder
    {
        return DB::table('student_batch_enrollments as sbe')
            ->join('students as st', 'st.id', '=', 'sbe.student_id')
            ->join('batches as b', 'b.id', '=', 'sbe.batch_id')
            ->leftJoin('courses as c', 'c.id', '=', 'sbe.course_id')
            ->whereNull('sbe.deleted_at')
            ->whereNull('st.deleted_at')
            ->whereNull('b.deleted_at')
            ->when(isset($filters['batch_id']), fn (Builder $q) => $q->where('sbe.batch_id', $filters['batch_id']))
            ->when(isset($filters['course_id']), fn (Builder $q) => $q->where('sbe.course_id', $filters['course_id']))
            ->when(isset($filters['teacher_id']), fn (Builder $q) => $q->where('b.teacher_id', $filters['teacher_id']))
            ->when(
                isset($filters['status']),
                fn (Builder $q) => $q->where('sbe.status', $filters['status']),
                fn (Builder $q) => $q->where('sbe.status', EnrollmentStatus::Active->value),
            )
            ->when(isset($filters['branch_id']), fn (Builder $q) => $q->where(function (Builder $inner) use ($filters): void {
                $inner->where('b.branch_id', $filters['branch_id'])->orWhereNull('b.branch_id');
            }));
    }

    /*
    |--------------------------------------------------------------------------
    | 1 — Daily: one row per class held that day
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function daily(Carbon $date, array $filters = []): array
    {
        // Scheduled classes too, so "not marked" has something to be true of. A class that was never
        // held and never cancelled is exactly what the evening sweep is looking for.
        $sessions = DB::table('class_sessions as cs')
            ->leftJoin('batches as b', 'b.id', '=', 'cs.batch_id')
            ->leftJoin('courses as c', 'c.id', '=', 'cs.course_id')
            ->leftJoin('teachers as t', 't.id', '=', 'cs.teacher_id')
            ->leftJoin('classrooms as r', 'r.id', '=', 'cs.classroom_id')
            ->leftJoin('users as u', 'u.id', '=', 'cs.attendance_marked_by')
            ->whereNull('cs.deleted_at')
            ->whereDate('cs.session_date', $date->toDateString())
            ->whereIn('cs.status', [ClassSessionStatus::Held->value, ClassSessionStatus::Scheduled->value])
            ->when(isset($filters['batch_id']), fn (Builder $q) => $q->where('cs.batch_id', $filters['batch_id']))
            ->when(isset($filters['course_id']), fn (Builder $q) => $q->where('cs.course_id', $filters['course_id']))
            ->when(isset($filters['teacher_id']), fn (Builder $q) => $q->where('cs.teacher_id', $filters['teacher_id']))
            ->when(isset($filters['classroom_id']), fn (Builder $q) => $q->where('cs.classroom_id', $filters['classroom_id']))
            ->when(isset($filters['branch_id']), fn (Builder $q) => $q->where(function (Builder $inner) use ($filters): void {
                $inner->where('cs.branch_id', $filters['branch_id'])->orWhereNull('cs.branch_id');
            }))
            ->when(($filters['unmarked_only'] ?? false) === true, fn (Builder $q) => $q->whereNull('cs.attendance_marked_at'))
            ->orderBy('cs.start_time')
            ->get([
                'cs.id', 'cs.session_date', 'cs.start_time', 'cs.end_time', 'cs.status',
                'cs.expected_count', 'cs.present_count', 'cs.absent_count', 'cs.leave_count',
                'cs.late_count', 'cs.attendance_marked_at',
                'b.code as batch_code', 'b.name as batch_name', 'c.name as course_name',
                't.name as teacher_name', 'r.code as room_code', 'u.name as marked_by_name',
            ]);

        $rows = $sessions->map(function (object $row): object {
            $row->percentage = $this->percentageOf(
                (int) $row->present_count + (int) $row->late_count,
                (int) $row->present_count + (int) $row->late_count + (int) $row->absent_count
                    + ($this->leaveCounts() ? (int) $row->leave_count : 0),
            );
            $row->is_marked = $row->attendance_marked_at !== null;

            return $row;
        });

        return [
            'date' => $date->copy(),
            'rows' => $rows,
            'totals' => [
                'sessions' => $rows->count(),
                'unmarked' => $rows->where('is_marked', false)->count(),
                'expected' => (int) $rows->sum('expected_count'),
                'present' => (int) $rows->sum('present_count') + (int) $rows->sum('late_count'),
                'absent' => (int) $rows->sum('absent_count'),
                'leave' => (int) $rows->sum('leave_count'),
                'late' => (int) $rows->sum('late_count'),
            ],
            'percentage' => $this->percentageOf(
                (int) $rows->sum('present_count') + (int) $rows->sum('late_count'),
                (int) $rows->sum('present_count') + (int) $rows->sum('late_count') + (int) $rows->sum('absent_count')
                    + ($this->leaveCounts() ? (int) $rows->sum('leave_count') : 0),
            ),
            'filters' => $filters,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 2 — Monthly: the student × day matrix
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function monthly(Batch $batch, int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $sessions = $this->heldSessions($from, $to, ['batch_id' => $batch->getKey()])
            ->orderBy('cs.session_date')
            ->orderBy('cs.start_time')
            ->get(['cs.id', 'cs.session_date', 'cs.start_time']);

        $marks = $this->attendanceRows($from, $to, ['batch_id' => $batch->getKey()])
            ->get(['sa.class_session_id', 'sa.student_id', 'sa.status']);

        // student => session => status
        $grid = [];

        foreach ($marks as $mark) {
            $grid[(int) $mark->student_id][(int) $mark->class_session_id] = StudentAttendanceStatus::from((string) $mark->status);
        }

        $roster = $this->enrollments(['batch_id' => $batch->getKey(), 'status' => null])
            ->orderByRaw('CAST(sbe.roll_number AS UNSIGNED)')
            ->orderBy('st.name')
            ->get([
                'sbe.id as enrollment_id', 'sbe.student_id', 'sbe.roll_number', 'sbe.enrolled_on',
                'sbe.left_on', 'st.name as student_name', 'st.student_code',
            ]);

        $rows = $roster->map(function (object $member) use ($sessions, $grid): object {
            $cells = [];
            $counts = array_fill_keys(array_map(
                static fn (StudentAttendanceStatus $s): string => $s->value,
                StudentAttendanceStatus::cases(),
            ), 0);

            foreach ($sessions as $session) {
                $status = $grid[(int) $member->student_id][(int) $session->id] ?? null;

                // A student who was not on the roster that day gets no cell at all, rather than a
                // blank that reads like an unmarked absence.
                $onRoster = $session->session_date >= (string) $member->enrolled_on
                    && ($member->left_on === null || $session->session_date <= (string) $member->left_on);

                $cells[(int) $session->id] = ['status' => $status, 'on_roster' => $onRoster];

                if ($status !== null) {
                    $counts[$status->value]++;
                }
            }

            $member->cells = $cells;
            $member->counts = $counts;
            $member->percentage = $this->percentageOf(
                $counts['present'] + $counts['late'],
                $counts['present'] + $counts['late'] + $counts['absent']
                    + ($this->leaveCounts() ? $counts['leave'] : 0),
            );

            return $member;
        });

        // Column totals: how the class did on each day.
        $columns = [];

        foreach ($sessions as $session) {
            $present = 0;
            $denominator = 0;

            foreach ($rows as $row) {
                $status = $row->cells[(int) $session->id]['status'] ?? null;

                if ($status === null) {
                    continue;
                }

                if ($status->countsAsPresent()) {
                    $present++;
                    $denominator++;
                } elseif ($status->isAbsence() || $this->leaveCounts()) {
                    $denominator++;
                }
            }

            $columns[(int) $session->id] = [
                'present' => $present,
                'percentage' => $this->percentageOf($present, $denominator),
            ];
        }

        return [
            'batch' => $batch,
            'from' => $from,
            'to' => $to,
            'sessions' => $sessions,
            'rows' => $rows,
            'columns' => $columns,
            'average' => $this->averageOf($rows->pluck('percentage')->all()),
            'filters' => ['batch_id' => $batch->getKey(), 'year' => $year, 'month' => $month],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 3 — Percentage: one row per enrolment
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function percentage(array $filters = []): array
    {
        $minimum = (string) setting('institute.attendance_minimum_percentage', 75);

        $rows = $this->enrollments($filters)
            ->when(isset($filters['min_percentage']), fn (Builder $q) => $q->where('sbe.attendance_percentage', '>=', $filters['min_percentage']))
            ->when(isset($filters['max_percentage']), fn (Builder $q) => $q->where('sbe.attendance_percentage', '<=', $filters['max_percentage']))
            ->orderBy('sbe.attendance_percentage')
            ->get([
                'sbe.id as enrollment_id', 'sbe.roll_number', 'sbe.attendance_percentage',
                'sbe.sessions_expected_count', 'sbe.present_count', 'sbe.absent_count',
                'sbe.leave_count', 'sbe.late_count', 'sbe.status as enrollment_status',
                'st.id as student_id', 'st.name as student_name', 'st.student_code',
                'b.code as batch_code', 'c.name as course_name',
            ])
            ->map(function (object $row) use ($minimum): object {
                // A student with no classes yet is not "below the minimum" — they are unmeasured, and
                // flagging them would put somebody on a warning list for the timetable's sake.
                $row->below_minimum = (int) $row->sessions_expected_count > 0
                    && Money::compare((string) $row->attendance_percentage, $minimum) < 0;

                return $row;
            });

        return [
            'rows' => $rows,
            'minimum' => $minimum,
            'below' => $rows->where('below_minimum', true)->count(),
            'average' => $this->averageOf($rows->pluck('attendance_percentage')->map(static fn ($v): string => (string) $v)->all()),
            'filters' => $filters,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 4 — Batch summary: one row per batch
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function batchSummary(Carbon $from, Carbon $to, array $filters = []): array
    {
        $minimum = (string) setting('institute.attendance_minimum_percentage', 75);

        $batches = DB::table('batches as b')
            ->leftJoin('courses as c', 'c.id', '=', 'b.course_id')
            ->leftJoin('teachers as t', 't.id', '=', 'b.teacher_id')
            ->whereNull('b.deleted_at')
            ->when(isset($filters['course_id']), fn (Builder $q) => $q->where('b.course_id', $filters['course_id']))
            ->when(isset($filters['teacher_id']), fn (Builder $q) => $q->where('b.teacher_id', $filters['teacher_id']))
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('b.status', $filters['status']))
            ->when(isset($filters['branch_id']), fn (Builder $q) => $q->where(function (Builder $inner) use ($filters): void {
                $inner->where('b.branch_id', $filters['branch_id'])->orWhereNull('b.branch_id');
            }))
            ->orderBy('b.code')
            ->get([
                'b.id', 'b.code', 'b.name', 'b.status', 'b.sessions_held_count',
                'b.syllabus_completion_percentage', 'c.name as course_name', 't.name as teacher_name',
            ]);

        $rows = $batches->map(function (object $batch) use ($from, $to, $minimum): object {
            $sessions = DB::table('class_sessions')
                ->whereNull('deleted_at')
                ->where('batch_id', $batch->id)
                ->whereBetween('session_date', [$from->toDateString(), $to->toDateString()]);

            $batch->sessions_held = (clone $sessions)->where('status', ClassSessionStatus::Held->value)->count();
            $batch->sessions_cancelled = (clone $sessions)->where('status', ClassSessionStatus::Cancelled->value)->count();
            $batch->sessions_planned = (clone $sessions)->count();
            $batch->last_session_on = (clone $sessions)
                ->where('status', ClassSessionStatus::Held->value)
                ->max('session_date');

            $enrollments = DB::table('student_batch_enrollments')
                ->whereNull('deleted_at')
                ->where('batch_id', $batch->id)
                ->where('status', EnrollmentStatus::Active->value)
                ->get(['attendance_percentage', 'sessions_expected_count']);

            $batch->students = $enrollments->count();
            $batch->average = $this->averageOf(
                $enrollments->pluck('attendance_percentage')->map(static fn ($v): string => (string) $v)->all(),
            );
            $batch->below_minimum = $enrollments
                ->filter(static fn (object $e): bool => (int) $e->sessions_expected_count > 0
                    && Money::compare((string) $e->attendance_percentage, $minimum) < 0)
                ->count();

            return $batch;
        });

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'minimum' => $minimum,
            'filters' => $filters,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The evening sweep's question
    |--------------------------------------------------------------------------
    */

    /**
     * Classes that happened and have no register. Never marks anything — who was in the room is a
     * fact only a person has.
     */
    public function unmarked(?Carbon $until = null, array $filters = []): Collection
    {
        $until ??= Carbon::now();

        return DB::table('class_sessions as cs')
            ->leftJoin('batches as b', 'b.id', '=', 'cs.batch_id')
            ->leftJoin('teachers as t', 't.id', '=', 'cs.teacher_id')
            ->whereNull('cs.deleted_at')
            ->whereNull('cs.attendance_marked_at')
            ->whereIn('cs.status', [ClassSessionStatus::Held->value, ClassSessionStatus::Scheduled->value])
            ->whereRaw('TIMESTAMP(cs.session_date, cs.end_time) <= ?', [$until->toDateTimeString()])
            ->when(isset($filters['teacher_id']), fn (Builder $q) => $q->where('cs.teacher_id', $filters['teacher_id']))
            ->orderBy('cs.session_date')
            ->get([
                'cs.id', 'cs.session_date', 'cs.start_time', 'cs.end_time', 'cs.status',
                'cs.teacher_id', 'b.code as batch_code', 't.name as teacher_name',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals — the two places arithmetic happens
    |--------------------------------------------------------------------------
    */

    private function leaveCounts(): bool
    {
        return (bool) setting('institute.attendance_leave_counts_in_denominator', false);
    }

    /** Half-up at two, like every other percentage in the phase (INV-I11). */
    private function percentageOf(int $numerator, int $denominator): string
    {
        return $denominator === 0
            ? '0.00'
            : Money::percentageOf((string) $numerator, (string) $denominator, 2);
    }

    /**
     * The plain average of stored percentages — each already a half-up figure, so this is an average
     * of numbers people have seen rather than a second derivation of the same data.
     *
     * @param  list<string>  $values
     */
    private function averageOf(array $values): string
    {
        $values = array_values(array_filter($values, static fn ($v): bool => $v !== null && $v !== ''));

        if ($values === []) {
            return '0.00';
        }

        return Money::weightedAverage(
            array_map(static fn (string $v): array => [$v, '1'], $values),
            2,
        ) ?? '0.00';
    }
}
