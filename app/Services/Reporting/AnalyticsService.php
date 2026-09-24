<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\DataObjects\Reporting\ChartDefinition;
use App\Enums\ChartType;
use App\Models\User;
use App\Services\Institute\AssignmentService;
use App\Services\Institute\AttendanceReportService;
use App\Services\Institute\CertificateService;
use App\Services\Institute\CourseMaterialService;
use App\Services\Institute\ExamStatisticsService;
use App\Services\Support\MeetingService;
use App\Services\Support\TicketService;
use App\Services\Support\TicketSlaService;
use App\Support\DateRange;
use App\Support\Modules;
use App\Support\Money;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The nine charts of §6.22 (phase-19-23).
 *
 * **One method per chart, and every one of them delegates.** Phase 23 adds only charts whose
 * subject these five phases own; it never re-registers a widget another phase already put in
 * `DashboardRegistry`, and it never derives a figure a service already derives. Each method's job
 * is to call the source named in §6.22 and shape what comes back into `{chart, rows, totals}`.
 *
 * **Every method is permission-gated and returns an *unavailable* payload rather than throwing.**
 * A dashboard renders nine of these at once; one that threw would take the page down over a chart
 * the viewer was never going to see anyway. So a chart the viewer may not have, or whose module is
 * off, comes back as `available: false` with a reason — the same shape a report uses, for the same
 * reason: a plausible empty chart reads as "nothing happened", which is a different and worse claim
 * than "not for you".
 *
 * **A chart is not a report.** These return small, already-aggregated series for rendering, not
 * rows for export — the equivalent report is where somebody goes for the detail, and duplicating
 * the row list here would be a second query over the same data with no second purpose.
 */
final class AnalyticsService
{
    public function __construct(
        private readonly ExamStatisticsService $exams,
        private readonly AssignmentService $assignments,
        private readonly CourseMaterialService $materials,
        private readonly CertificateService $certificates,
        private readonly TicketService $tickets,
        private readonly TicketSlaService $sla,
        private readonly AttendanceReportService $attendance,
        private readonly MeetingService $meetings,
    ) {}

    /**
     * Every chart this viewer may see, keyed by its own name.
     *
     * Used by the analytics screen; the dashboard asks for them one at a time.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, array<string, mixed>>
     */
    public function all(DateRange $range, User $viewer, array $filters = []): array
    {
        $charts = [];

        foreach (self::CHARTS as $name => [$module, $permission]) {
            $payload = $this->{$name}($range, $viewer, $filters);

            if (($payload['available'] ?? true) === true) {
                $charts[$name] = $payload;
            }
        }

        return $charts;
    }

    /**
     * name => [module, permission]. The declaration the screen, the gate and `all()` share.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CHARTS = [
        'examPassRateTrend' => ['exams', 'exams.view_reports'],
        'resultGradeDistribution' => ['results', 'results.view_reports'],
        'assignmentCompliance' => ['assignments', 'assignments.view_reports'],
        'materialEngagement' => ['course_materials', 'course_materials.view_reports'],
        'certificateIssuanceTrend' => ['certificates', 'certificates.view_any'],
        'ticketVolumeAndSla' => ['support_tickets', 'support_tickets.view_reports'],
        'ticketsByDepartmentAndPriority' => ['support_tickets', 'support_tickets.view_reports'],
        'meetingLoad' => ['meetings', 'meetings.view_reports'],
        'notificationHealth' => ['notifications', 'notifications.view_any'],
    ];

    /** The chart catalogue, for the screen's tile list. */
    public static function catalogue(): array
    {
        return self::CHARTS;
    }

    /*
    |--------------------------------------------------------------------------
    | The nine
    |--------------------------------------------------------------------------
    */

    /**
     * Pass rate per month per exam type — `ExamStatisticsService::forCourse()`.
     */
    public function examPassRateTrend(DateRange $range, User $viewer, array $filters = []): array
    {
        return $this->build('examPassRateTrend', $viewer, function () use ($range, $filters): array {
            $data = $this->exams->forCourse($range, $filters);

            return [
                'chart' => new ChartDefinition(
                    type: ChartType::Line,
                    title: 'Pass rate over time',
                    labelKey: 'bucket',
                    series: ['pass_rate' => 'Pass rate %'],
                    description: 'Of the students who sat each exam, the proportion who passed.',
                ),
                'rows' => $data['rows'],
                'totals' => $data['totals'],
            ];
        });
    }

    /**
     * Students per grade band — `ExamStatisticsService`.
     *
     * Read from `exam_results.grade`, which `ResultCalculator` wrote against the scale in force at
     * the time. Re-grading here against today's bands would silently restate published results.
     */
    public function resultGradeDistribution(DateRange $range, User $viewer, array $filters = []): array
    {
        return $this->build('resultGradeDistribution', $viewer, function () use ($range, $filters): array {
            $query = DB::table('exam_results as r')
                ->join('exams as e', 'e.id', '=', 'r.exam_id')
                ->whereNull('r.deleted_at')
                // Published only, for the same reason the exam chart excludes unpublished sheets.
                ->whereNotNull('r.published_at')
                ->whereBetween('r.published_at', [$range->start(), $range->end()])
                ->whereNotNull('r.grade');

            foreach (['course_id' => 'r.course_id', 'batch_id' => 'r.batch_id'] as $key => $column) {
                if (! empty($filters[$key])) {
                    $query->where($column, $filters[$key]);
                }
            }

            $rows = $query
                ->selectRaw('r.grade as bucket, COUNT(*) as students')
                ->groupBy('r.grade')
                ->orderBy('r.grade')
                ->get()
                ->map(static fn (object $r): array => [
                    'bucket' => (string) $r->bucket,
                    'students' => (int) $r->students,
                ])
                ->all();

            return [
                'chart' => new ChartDefinition(
                    type: ChartType::Bar,
                    title: 'Grades awarded',
                    labelKey: 'bucket',
                    series: ['students' => 'Students'],
                ),
                'rows' => $rows,
                'totals' => ['students' => array_sum(array_column($rows, 'students'))],
            ];
        });
    }

    /**
     * Submitted / late / missed per batch — `AssignmentService::statistics()`.
     */
    public function assignmentCompliance(DateRange $range, User $viewer, array $filters = []): array
    {
        return $this->build('assignmentCompliance', $viewer, function () use ($range, $filters): array {
            // `statistics()` is per-assignment; `compliance()` is the batch rollup this chart
            // needs, and it sums the same maintained counters rather than recounting.
            $data = $this->assignments->compliance($range, $filters);

            return [
                'chart' => new ChartDefinition(
                    type: ChartType::StackedBar,
                    title: 'Assignment compliance',
                    labelKey: 'bucket',
                    series: ['submitted' => 'On time', 'late' => 'Late', 'missed' => 'Missed'],
                    description: 'How each batch is keeping up with its assignments.',
                ),
                'rows' => $data['rows'] ?? [],
                'totals' => $data['totals'] ?? [],
            ];
        });
    }

    /**
     * Unique students who opened a material against who was targeted —
     * `CourseMaterialService::engagement()`.
     */
    public function materialEngagement(DateRange $range, User $viewer, array $filters = []): array
    {
        return $this->build('materialEngagement', $viewer, function () use ($range, $filters): array {
            $data = $this->materials->engagement($range, $filters);

            return [
                'chart' => new ChartDefinition(
                    type: ChartType::Bar,
                    title: 'Material engagement',
                    labelKey: 'material',
                    series: ['targeted' => 'Targeted', 'opened' => 'Opened'],
                    description: 'Unique students who opened each material, against how many it was aimed at.',
                ),
                'rows' => $data['rows'],
                'totals' => $data['totals'],
            ];
        });
    }

    /**
     * Issued and revoked per month — `CertificateService::register()`.
     */
    public function certificateIssuanceTrend(DateRange $range, User $viewer, array $filters = []): array
    {
        return $this->build('certificateIssuanceTrend', $viewer, function () use ($range, $filters): array {
            $data = $this->certificates->register($range, $filters);

            return [
                'chart' => new ChartDefinition(
                    type: ChartType::Line,
                    title: 'Certificates issued and revoked',
                    labelKey: 'bucket',
                    series: ['issued' => 'Issued', 'revoked' => 'Revoked'],
                ),
                'rows' => $data['rows'],
                'totals' => $data['totals'],
            ];
        });
    }

    /**
     * Created and resolved as bars, breach rate as a line — `TicketService::queue()` +
     * `TicketSlaService::breaches()`.
     *
     * **The combo type exists for exactly this chart.** A breach *rate* plotted on a count axis
     * would flatten to nothing next to bars in the hundreds; plotted on its own it would lose the
     * comparison that is the point. Two axes, and the rate on the right.
     */
    public function ticketVolumeAndSla(DateRange $range, User $viewer, array $filters = []): array
    {
        return $this->build('ticketVolumeAndSla', $viewer, function () use ($range, $filters): array {
            $queue = $this->tickets->queue($range, 'month', $filters);
            $breaches = $this->sla->breaches($range, $filters);

            return [
                'chart' => new ChartDefinition(
                    type: ChartType::Combo,
                    title: 'Ticket volume and SLA',
                    labelKey: 'bucket',
                    series: ['created' => 'Created', 'resolved' => 'Resolved', 'breach_rate' => 'Breach rate %'],
                    secondaryKey: 'breach_rate',
                    description: 'Arrivals and resolutions each month, with the proportion that missed their target.',
                ),
                'rows' => $queue['rows'],
                'totals' => array_merge($queue['totals'], [
                    'first_response_breached' => $breaches['first_response_breached'],
                    'resolution_breached' => $breaches['resolution_breached'],
                    'sla_enabled' => $breaches['sla_enabled'],
                ]),
            ];
        });
    }

    /**
     * Tickets stacked by department and priority — `TicketService::queue()`.
     */
    public function ticketsByDepartmentAndPriority(DateRange $range, User $viewer, array $filters = []): array
    {
        return $this->build('ticketsByDepartmentAndPriority', $viewer, function () use ($range, $filters): array {
            $data = $this->tickets->queue($range, 'department', $filters);

            return [
                'chart' => new ChartDefinition(
                    type: ChartType::StackedBar,
                    title: 'Tickets by queue',
                    labelKey: 'bucket',
                    series: ['created' => 'Created', 'resolved' => 'Resolved'],
                ),
                'rows' => $data['rows'],
                'totals' => $data['totals'],
            ];
        });
    }

    /**
     * Meetings and attendance rate per organiser — `MeetingService`.
     *
     * Attendance is `attended_count / participants_count`, both maintained on the meeting row by
     * Phase 22 when a participant responds or is marked present.
     */
    public function meetingLoad(DateRange $range, User $viewer, array $filters = []): array
    {
        return $this->build('meetingLoad', $viewer, function () use ($range, $filters): array {
            $query = DB::table('meetings as m')
                ->leftJoin('users as organizer', 'organizer.id', '=', 'm.organizer_id')
                ->whereNull('m.deleted_at')
                ->whereBetween('m.scheduled_at', [$range->start(), $range->end()]);

            if (! empty($filters['organizer_id'])) {
                $query->where('m.organizer_id', $filters['organizer_id']);
            }

            $rows = $query
                ->selectRaw('COALESCE(organizer.name, "Unknown") as bucket')
                ->selectRaw(
                    'COUNT(*) as meetings, '
                    .'COALESCE(SUM(m.participants_count), 0) as invited, '
                    .'COALESCE(SUM(m.attended_count), 0) as attended'
                )
                ->groupByRaw('COALESCE(organizer.name, "Unknown")')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(25)
                ->get()
                ->map(static function (object $r): array {
                    $invited = (int) $r->invited;

                    return [
                        'bucket' => (string) $r->bucket,
                        'meetings' => (int) $r->meetings,
                        'invited' => $invited,
                        'attended' => (int) $r->attended,
                        // Nobody invited means no rate. A meeting with no participants is not
                        // 0% attended, it is a meeting somebody held alone.
                        'attendance_rate' => $invited > 0
                            ? Money::round(Money::mul(Money::div((string) $r->attended, (string) $invited), '100'), 2)
                            : null,
                    ];
                })
                ->all();

            return [
                'chart' => new ChartDefinition(
                    type: ChartType::Bar,
                    title: 'Meeting load',
                    labelKey: 'bucket',
                    series: ['meetings' => 'Meetings', 'attended' => 'Attended'],
                ),
                'rows' => $rows,
                'totals' => [
                    'meetings' => array_sum(array_column($rows, 'meetings')),
                    'invited' => array_sum(array_column($rows, 'invited')),
                    'attended' => array_sum(array_column($rows, 'attended')),
                ],
            ];
        });
    }

    /**
     * Created vs read vs emailed per event group — the `notifications` aggregate.
     *
     * **Read rate is the number this chart exists for.** A notification nobody opens is noise, and
     * a group with a read rate near zero is a group that should probably not be sent — which is a
     * decision somebody can only take if the figure is in front of them.
     */
    public function notificationHealth(DateRange $range, User $viewer, array $filters = []): array
    {
        return $this->build('notificationHealth', $viewer, function () use ($range, $filters): array {
            $query = DB::table('notifications')
                ->whereBetween('created_at', [$range->start(), $range->end()]);

            if (! empty($filters['module'])) {
                $query->where('module', $filters['module']);
            }

            $rows = $query
                ->selectRaw('COALESCE(module, "other") as bucket')
                ->selectRaw(
                    'COUNT(*) as created, '
                    .'SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) as read_count, '
                    .'SUM(CASE WHEN emailed_at IS NOT NULL THEN 1 ELSE 0 END) as emailed'
                )
                ->groupByRaw('COALESCE(module, "other")')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(25)
                ->get()
                ->map(static function (object $r): array {
                    $created = (int) $r->created;

                    return [
                        'bucket' => (string) $r->bucket,
                        'created' => $created,
                        'read' => (int) $r->read_count,
                        'emailed' => (int) $r->emailed,
                        'read_rate' => $created > 0
                            ? Money::round(Money::mul(Money::div((string) $r->read_count, (string) $created), '100'), 2)
                            : null,
                    ];
                })
                ->all();

            return [
                'chart' => new ChartDefinition(
                    type: ChartType::StackedBar,
                    title: 'Notification health',
                    labelKey: 'bucket',
                    series: ['created' => 'Sent', 'read' => 'Read', 'emailed' => 'Emailed'],
                    description: 'How much of what the system sends is actually being read.',
                ),
                'rows' => $rows,
                'totals' => [
                    'created' => array_sum(array_column($rows, 'created')),
                    'read' => array_sum(array_column($rows, 'read')),
                    'emailed' => array_sum(array_column($rows, 'emailed')),
                ],
            ];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The gate every chart passes through
    |--------------------------------------------------------------------------
    */

    /**
     * Authorise, run, and turn any failure into an answer rather than an exception.
     *
     * @param  callable(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function build(string $name, User $viewer, callable $build): array
    {
        [$module, $permission] = self::CHARTS[$name];

        if (! Modules::enabled($module)) {
            return $this->unavailable($name, 'The '.$module.' module is switched off.');
        }

        $gate = app(Gate::class)->forUser($viewer);

        // `reports.view_reports` is the hub gate for the analytics screen; the per-chart permission
        // is what decides whether this particular subject is any of the viewer's business.
        if (! $gate->allows('reports.view_reports') || ! $gate->allows($permission)) {
            return $this->unavailable($name, 'You do not have permission to see this chart.');
        }

        try {
            $payload = $build();
        } catch (Throwable $exception) {
            // One chart failing must not take the other eight with it — see the class note.
            report($exception);

            return $this->unavailable($name, 'This chart could not be built.');
        }

        /** @var ChartDefinition $chart */
        $chart = $payload['chart'];

        return [
            'name' => $name,
            'available' => true,
            'module' => $module,
            'permission' => $permission,
            'chart' => $chart->toArray(),
            'rows' => $payload['rows'],
            'totals' => $payload['totals'],
            'empty' => $payload['rows'] === [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $name, string $reason): array
    {
        [$module, $permission] = self::CHARTS[$name];

        return [
            'name' => $name,
            'available' => false,
            'module' => $module,
            'permission' => $permission,
            'reason' => $reason,
            'rows' => [],
            'totals' => [],
            'empty' => true,
        ];
    }
}
