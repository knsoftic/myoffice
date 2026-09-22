<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\BatchStatus;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\Teacher;
use App\Services\Institute\AttendanceReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The four reports of §75 — `admin.attendance.reports.*` (phase-14-17 §7.7, §8.16).
 *
 * **Each one states the filters in force**, on screen and in print. A printed attendance sheet with
 * no filter line is a sheet nobody can reproduce or defend six months later, which is exactly when
 * somebody asks about it.
 *
 * The monthly matrix needs a batch — a student × day grid across every batch in the institute is not
 * a report, it is a wall — so it asks for one and says so rather than rendering something useless.
 */
final class AttendanceReportController extends Controller
{
    public function __construct(
        private readonly AttendanceReportService $reports,
    ) {}

    public function daily(Request $request): View
    {
        $date = Carbon::parse((string) $request->input('date', Carbon::today()->toDateString()));

        return view('admin.attendance.reports.daily', $this->pickers($request) + [
            'date' => $date,
            'report' => $this->reports->daily($date, $this->filters($request)),
            'filters' => $request->only(['batch_id', 'teacher_id', 'course_id', 'classroom_id', 'unmarked_only']),
        ]);
    }

    public function monthly(Request $request): View
    {
        $batchId = $request->integer('batch_id');
        $batch = $batchId > 0 ? Batch::query()->find($batchId) : null;

        $year = (int) $request->input('year', Carbon::today()->year);
        $month = (int) $request->input('month', Carbon::today()->month);

        return view('admin.attendance.reports.monthly', $this->pickers($request) + [
            'batch' => $batch,
            'year' => $year,
            'month' => $month,
            'report' => $batch !== null ? $this->reports->monthly($batch, $year, $month) : null,
        ]);
    }

    public function percentage(Request $request): View
    {
        return view('admin.attendance.reports.percentage', $this->pickers($request) + [
            'report' => $this->reports->percentage($this->filters($request)),
            'statuses' => EnrollmentStatus::options(),
            'filters' => $request->only(['batch_id', 'course_id', 'teacher_id', 'status', 'min_percentage', 'max_percentage']),
        ]);
    }

    public function batchSummary(Request $request): View
    {
        [$from, $to] = $this->window($request);

        return view('admin.attendance.reports.batch', $this->pickers($request) + [
            'from' => $from,
            'to' => $to,
            'report' => $this->reports->batchSummary($from, $to, $this->filters($request)),
            'statuses' => BatchStatus::options(),
            'filters' => $request->only(['course_id', 'teacher_id', 'status', 'from', 'to']),
        ]);
    }

    public function print(Request $request, string $report): View
    {
        return view('admin.attendance.reports.print', [
            'report' => $report,
            'payload' => $this->payloadFor($request, $report),
            'printedAt' => Carbon::now(),
        ]);
    }

    public function export(Request $request, string $report, string $format): StreamedResponse
    {
        abort_unless(in_array($format, ['csv'], true), 404);

        $payload = $this->payloadFor($request, $report);

        return response()->streamDownload(function () use ($report, $payload): void {
            $handle = fopen('php://output', 'wb');

            match ($report) {
                'daily' => $this->writeDaily($handle, $payload),
                'monthly' => $this->writeMonthly($handle, $payload),
                'percentage' => $this->writePercentage($handle, $payload),
                default => $this->writeBatchSummary($handle, $payload),
            };

            fclose($handle);
        }, 'attendance-'.$report.'-'.Carbon::now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>|null
     */
    private function payloadFor(Request $request, string $report): ?array
    {
        [$from, $to] = $this->window($request);

        if ($report === 'monthly') {
            $batch = Batch::query()->find($request->integer('batch_id'));

            return $batch === null ? null : $this->reports->monthly(
                $batch,
                (int) $request->input('year', Carbon::today()->year),
                (int) $request->input('month', Carbon::today()->month),
            );
        }

        return match ($report) {
            'daily' => $this->reports->daily(
                Carbon::parse((string) $request->input('date', Carbon::today()->toDateString())),
                $this->filters($request),
            ),
            'percentage' => $this->reports->percentage($this->filters($request)),
            default => $this->reports->batchSummary($from, $to, $this->filters($request)),
        };
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>|null  $payload
     */
    private function writeDaily($handle, ?array $payload): void
    {
        fputcsv($handle, ['Batch', 'Course', 'Teacher', 'Room', 'From', 'To', 'Expected', 'Present', 'Absent', 'Leave', 'Late', '%', 'Marked']);

        foreach ($payload['rows'] ?? [] as $row) {
            fputcsv($handle, [
                $row->batch_code, $row->course_name, $row->teacher_name, $row->room_code,
                app_clock($row->start_time, 'H:i'), app_clock($row->end_time, 'H:i'),
                $row->expected_count, $row->present_count, $row->absent_count,
                $row->leave_count, $row->late_count, $row->percentage,
                $row->is_marked ? 'yes' : 'no',
            ]);
        }
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>|null  $payload
     */
    private function writeMonthly($handle, ?array $payload): void
    {
        if ($payload === null) {
            fputcsv($handle, ['Pick a batch first — a student by day matrix across every batch is not a report.']);

            return;
        }

        $header = ['Roll', 'Student', 'Code'];

        foreach ($payload['sessions'] as $session) {
            $header[] = app_date($session->session_date, 'd M');
        }

        $header = array_merge($header, ['Present', 'Absent', 'Leave', 'Late', '%']);
        fputcsv($handle, $header);

        foreach ($payload['rows'] as $row) {
            $line = [$row->roll_number, $row->student_name, $row->student_code];

            foreach ($payload['sessions'] as $session) {
                $cell = $row->cells[(int) $session->id] ?? null;

                $line[] = $cell === null || ! $cell['on_roster']
                    ? '—'
                    : ($cell['status']?->glyph() ?? '');
            }

            $line = array_merge($line, [
                $row->counts['present'], $row->counts['absent'],
                $row->counts['leave'], $row->counts['late'], $row->percentage,
            ]);

            fputcsv($handle, $line);
        }
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>|null  $payload
     */
    private function writePercentage($handle, ?array $payload): void
    {
        fputcsv($handle, ['Student', 'Code', 'Batch', 'Course', 'Classes', 'Present', 'Absent', 'Leave', 'Late', '%', 'Below minimum']);

        foreach ($payload['rows'] ?? [] as $row) {
            fputcsv($handle, [
                $row->student_name, $row->student_code, $row->batch_code, $row->course_name,
                $row->sessions_expected_count, $row->present_count, $row->absent_count,
                $row->leave_count, $row->late_count, $row->attendance_percentage,
                $row->below_minimum ? 'yes' : 'no',
            ]);
        }
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>|null  $payload
     */
    private function writeBatchSummary($handle, ?array $payload): void
    {
        fputcsv($handle, ['Batch', 'Course', 'Teacher', 'Status', 'Students', 'Planned', 'Held', 'Cancelled', 'Average %', 'Below minimum', 'Last class']);

        foreach ($payload['rows'] ?? [] as $row) {
            fputcsv($handle, [
                $row->code, $row->course_name, $row->teacher_name, $row->status,
                $row->students, $row->sessions_planned, $row->sessions_held, $row->sessions_cancelled,
                $row->average, $row->below_minimum, $row->last_session_on,
            ]);
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request): array
    {
        $from = Carbon::parse((string) $request->input('from', Carbon::today()->startOfMonth()->toDateString()));
        $to = Carbon::parse((string) $request->input('to', Carbon::today()->endOfMonth()->toDateString()));

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (['batch_id', 'teacher_id', 'course_id', 'classroom_id'] as $key) {
            if ($request->filled($key)) {
                $filters[$key] = $request->integer($key);
            }
        }

        foreach (['status', 'min_percentage', 'max_percentage'] as $key) {
            if ($request->filled($key)) {
                $filters[$key] = $request->input($key);
            }
        }

        if ($request->boolean('unmarked_only')) {
            $filters['unmarked_only'] = true;
        }

        $branch = $request->user()?->branch_id;

        if ($branch !== null) {
            $filters['branch_id'] = (int) $branch;
        }

        return $filters;
    }

    /**
     * The filter pickers, branch-scoped.
     *
     * A dropdown is a report too: listing every batch code in the institute would tell a branch user
     * exactly what runs elsewhere, which is the thing §9 exists to prevent — and it is the kind of
     * leak that hides behind a correctly scoped table.
     *
     * @return array<string, mixed>
     */
    private function pickers(?Request $request = null): array
    {
        $branch = $request?->user()?->branch_id;
        $branch = $branch === null ? null : (int) $branch;

        return [
            'batches' => Batch::query()->forBranch($branch)->orderBy('code')->pluck('code', 'id'),
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'teachers' => Teacher::query()->forBranch($branch)->orderBy('name')->pluck('name', 'id'),
        ];
    }
}
