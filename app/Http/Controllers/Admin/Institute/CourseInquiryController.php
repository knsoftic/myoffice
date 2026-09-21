<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\CourseInquiryStatus;
use App\Enums\FollowUpChannel;
use App\Enums\FollowUpOutcome;
use App\Enums\InquirySource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Institute\StoreCourseInquiryRequest;
use App\Models\Institute\Course;
use App\Models\Institute\CourseInquiry;
use App\Models\User;
use App\Services\Institute\CourseInquiryService;
use App\Services\Institute\StudentApplicationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The counsellor's queue — `admin.course-inquiries.*` (§86, phase-14-17 §7.3, §8.5).
 *
 * **Nothing here decides a status.** Every move goes through `CourseInquiryService::changeStatus()`
 * and §2.30.2's table, including the ones a follow-up outcome implies, so the queue and its own
 * history cannot disagree.
 *
 * **The list is scoped by branch in the query, not by the policy.** A policy answers about one row;
 * a list has to be narrowed before the rows are fetched, or a branch user's page count tells them how
 * many enquiries exist elsewhere.
 */
final class CourseInquiryController extends Controller
{
    public function __construct(
        private readonly CourseInquiryService $inquiries,
        private readonly StudentApplicationService $applications,
    ) {}

    public function index(Request $request): View
    {
        $query = $this->filtered($request);

        return view('admin.course-inquiries.index', [
            'inquiries' => $query->clone()
                ->with(['course:id,name', 'assignee:id,name', 'collaborator:id,name'])
                ->orderByRaw('CASE WHEN follow_up_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('follow_up_date')
                ->orderByDesc('id')
                ->paginate(per_page())
                ->withQueryString(),
            'stats' => $this->stats($request),
            'statuses' => CourseInquiryStatus::options(),
            'sources' => InquirySource::options(),
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'assignees' => User::query()->permission('course_inquiries.edit')->orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only(['status', 'source', 'course_id', 'assigned_to', 'due', 'q']),
            'staleDays' => (int) setting('institute.inquiry_stale_days', 14),
        ]);
    }

    public function create(): View
    {
        return view('admin.course-inquiries.create', [
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'sources' => InquirySource::options(),
            'assignees' => User::query()->permission('course_inquiries.edit')->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(StoreCourseInquiryRequest $request): RedirectResponse
    {
        $inquiry = $this->inquiries->create($request->validated(), $request->user());

        return redirect()
            ->route('admin.course-inquiries.show', $inquiry)
            ->with('toast', ['type' => 'success', 'message' => sprintf('Inquiry %s recorded.', $inquiry->inquiry_number)]);
    }

    public function show(CourseInquiry $inquiry): View
    {
        $inquiry->load([
            'course:id,name,slug', 'assignee:id,name', 'collaborator:id,name,referral_code',
            'branch:id,name', 'followUps.user:id,name', 'demoClasses',
            'convertedApplication:id,application_number,status', 'convertedStudent:id,name,student_code',
        ]);

        return view('admin.course-inquiries.show', [
            'inquiry' => $inquiry,
            'channels' => FollowUpChannel::options(),
            'outcomes' => FollowUpOutcome::options(),
            'statuses' => CourseInquiryStatus::options(),
            'assignees' => User::query()->permission('course_inquiries.edit')->orderBy('name')->pluck('name', 'id'),
            'followUpDays' => (int) setting('institute.inquiry_followup_days', 2),
        ]);
    }

    public function update(StoreCourseInquiryRequest $request, CourseInquiry $inquiry): RedirectResponse
    {
        $inquiry->fill($request->safe()->only([
            'name', 'email', 'city', 'education', 'course_id', 'branch_id',
            'preferred_delivery_mode', 'preferred_timing', 'source', 'source_url', 'message', 'notes',
        ]));
        $inquiry->updated_by = $request->user()?->getKey();
        $inquiry->save();

        return back()->with('toast', ['type' => 'success', 'message' => 'Inquiry updated.']);
    }

    public function destroy(CourseInquiry $inquiry): RedirectResponse
    {
        $inquiry->delete();

        return redirect()
            ->route('admin.course-inquiries.index')
            ->with('toast', ['type' => 'success', 'message' => 'Inquiry removed.']);
    }

    public function storeFollowUp(Request $request, CourseInquiry $inquiry): RedirectResponse
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::enum(FollowUpChannel::class)],
            'outcome' => ['required', Rule::enum(FollowUpOutcome::class)],
            'contacted_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'next_follow_up_at' => ['nullable', 'date'],
        ]);

        $this->inquiries->logFollowUp($inquiry, $validated, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Follow-up logged.']);
    }

    public function assign(Request $request, CourseInquiry $inquiry): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->inquiries->assign(
            $inquiry,
            User::query()->findOrFail($validated['assigned_to']),
            $validated['note'] ?? null,
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Inquiry reassigned.']);
    }

    public function status(Request $request, CourseInquiry $inquiry): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(CourseInquiryStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->inquiries->changeStatus(
            $inquiry,
            CourseInquiryStatus::from($validated['status']),
            $validated['reason'] ?? null,
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Status updated.']);
    }

    /** Turn the enquiry into a reviewable application, carrying its contact details and referral. */
    public function promote(Request $request, CourseInquiry $inquiry): RedirectResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
        ]);

        $application = $this->applications->createForStaff([
            'name' => $inquiry->name,
            'phone' => $inquiry->phone,
            'whatsapp' => $inquiry->whatsapp,
            'email' => $inquiry->email,
            'city' => $inquiry->city,
            'education' => $inquiry->education,
            'branch_id' => $inquiry->branch_id,
            'course_id' => $validated['course_id'],
            'preferred_timing' => $inquiry->preferred_timing?->value,
            'preferred_delivery_mode' => $inquiry->preferred_delivery_mode?->value,
            'referral_code' => $inquiry->referral_code,
            'message' => $inquiry->message,
        ], $request->user(), $inquiry);

        $inquiry->forceFill([
            'converted_application_id' => $application->getKey(),
            'updated_by' => $request->user()?->getKey(),
        ])->save();

        return redirect()
            ->route('admin.student-applications.show', $application)
            ->with('toast', ['type' => 'success', 'message' => sprintf('Application %s created.', $application->application_number)]);
    }

    /**
     * The walk-in shortcut: application, student and admission in one go, so the funnel report is
     * never missing a step for somebody who turned up with the money in their hand.
     */
    public function convert(Request $request, CourseInquiry $inquiry): RedirectResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
        ]);

        $application = $this->applications->createForStaff([
            'name' => $inquiry->name,
            'phone' => $inquiry->phone,
            'whatsapp' => $inquiry->whatsapp,
            'email' => $inquiry->email,
            'city' => $inquiry->city,
            'education' => $inquiry->education,
            'branch_id' => $inquiry->branch_id,
            'course_id' => $validated['course_id'],
            'referral_code' => $inquiry->referral_code,
        ], $request->user(), $inquiry);

        $admission = $this->applications->convert($application, [], $request->user());

        return redirect()
            ->route('admin.admissions.show', $admission)
            ->with('toast', ['type' => 'success', 'message' => sprintf('Admission %s started.', $admission->admission_number)]);
    }

    /** The §88 funnel: counts per status and per source, with the conversion rate. */
    public function funnel(Request $request): View
    {
        $from = Carbon::parse((string) $request->input('from', Carbon::now()->subDays(90)->toDateString()))->startOfDay();
        $to = Carbon::parse((string) $request->input('to', Carbon::now()->toDateString()))->endOfDay();

        $base = CourseInquiry::query()->whereBetween('created_at', [$from, $to]);

        $byStatus = $base->clone()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $bySource = $base->clone()->selectRaw('source, COUNT(*) AS total')->groupBy('source')->pluck('total', 'source');

        $total = (int) $byStatus->sum();
        $won = (int) ($byStatus[CourseInquiryStatus::AdmissionConfirmed->value] ?? 0);

        return view('admin.course-inquiries.funnel', [
            'from' => $from,
            'to' => $to,
            'byStatus' => $byStatus,
            'bySource' => $bySource,
            'total' => $total,
            'won' => $won,
            // Rounded here rather than in the view: a percentage computed in Blade is one nobody can
            // test, and this number is read by a manager.
            'conversionRate' => $total > 0 ? round(($won / $total) * 100, 1) : 0.0,
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse
    {
        abort_unless($format === 'csv', 404);

        $rows = $this->filtered($request)->with(['course:id,name', 'assignee:id,name'])->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, ['Inquiry #', 'Name', 'Phone', 'Course', 'Source', 'Status', 'Assigned to', 'Next follow-up', 'Attempts', 'Created']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->inquiry_number,
                    $row->name,
                    $row->phone,
                    (string) $row->course?->name,
                    $row->source->label(),
                    $row->status->label(),
                    (string) $row->assignee?->name,
                    $row->follow_up_date ? app_date($row->follow_up_date) : '',
                    (string) $row->contact_attempts,
                    app_date($row->created_at),
                ]);
            }

            fclose($out);
        }, 'course-inquiries-'.app_date(Carbon::now(), 'Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return \Illuminate\Database\Eloquent\Builder<CourseInquiry>
     */
    private function filtered(Request $request)
    {
        return CourseInquiry::query()
            ->forBranch($request->user()?->branch_id === null ? null : (int) $request->user()->branch_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')->toString()))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->filled('assigned_to'), fn ($q) => $q->where('assigned_to', $request->integer('assigned_to')))
            ->when($request->input('due') === 'overdue', fn ($q) => $q->open()->whereDate('follow_up_date', '<', Carbon::now()->toDateString()))
            ->when($request->input('due') === 'today', fn ($q) => $q->open()->whereDate('follow_up_date', Carbon::now()->toDateString()))
            ->when($request->input('due') === 'week', fn ($q) => $q->open()->whereBetween('follow_up_date', [
                Carbon::now()->toDateString(), Carbon::now()->addWeek()->toDateString(),
            ]))
            ->search($request->string('q')->toString());
    }

    /**
     * @return array<string, int>
     */
    private function stats(Request $request): array
    {
        $branchId = $request->user()?->branch_id === null ? null : (int) $request->user()->branch_id;
        $today = Carbon::now()->toDateString();

        $scoped = fn () => CourseInquiry::query()->forBranch($branchId);

        return [
            'new_today' => (int) $scoped()->whereDate('created_at', $today)->count(),
            'due_today' => (int) $scoped()->open()->whereDate('follow_up_date', $today)->count(),
            'overdue' => (int) $scoped()->open()->whereDate('follow_up_date', '<', $today)->count(),
            'converted_this_month' => (int) $scoped()
                ->where('status', CourseInquiryStatus::AdmissionConfirmed->value)
                ->whereBetween('converted_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
                ->count(),
        ];
    }
}
