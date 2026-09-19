<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DataObjects\Crm\FollowUpFilters;
use App\Enums\LeadContactOutcome;
use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadFollowUpType;
use App\Http\Controllers\Admin\Crm\Concerns\RespondsForCrm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CancelLeadFollowUpRequest;
use App\Http\Requests\Crm\CompleteLeadFollowUpRequest;
use App\Http\Requests\Crm\CrmListRequest;
use App\Http\Requests\Crm\RescheduleLeadFollowUpRequest;
use App\Http\Requests\Crm\StoreLeadFollowUpRequest;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadFollowUp;
use App\Services\Crm\LeadFollowUpService;
use App\Support\DateRange;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Follow-ups — the daily worklist and every follow-up action on a lead, `admin.leads.follow-ups.*` (phase-05 §2.3,
 * §6.3, §8.5, tests 26-31), `module:leads`.
 *
 * **The worklist (§8.5).** Assignee defaults to "Me"; "All" (and another person) needs `leads.view_any`, and the
 * service falls back to the actor's own list otherwise. The list pages through the chosen range (pending by default);
 * the calendar asks for the visible month or week grid (one page of up to 100 rows) and draws a plain grid — no
 * calendar library. Every row already passed the lead visibility scope inside `LeadFollowUpService::worklist()`.
 *
 * **Actions.** `{lead}` binds through the lead visibility scope; `{followUp}` must belong to it or the answer is 404.
 * A second open follow-up is refused by `uq_lfu_open` and comes back from the service as
 * `FollowUpAlreadyOpenException` — a 422 naming the existing date and owner, never a 500 (test 26).
 */
final class LeadFollowUpController extends Controller
{
    use RespondsForCrm;

    public function __construct(
        private readonly LeadFollowUpService $followUps,
    ) {}

    public function index(CrmListRequest $request): View
    {
        $this->authorize('leads.view');
        $this->authorize('viewAny', Lead::class);

        $actor = $this->actor($request);
        $seesAll = $actor->can('leads.view_any');
        $view = $request->filterString('view') === 'calendar' ? 'calendar' : 'list';
        $mode = $request->filterString('mode') === 'week' ? 'week' : 'month';
        $zone = Format::displayTimezone();
        $weekStartsOn = Format::weekStartsOn();

        // "All" and another person's list need `leads.view_any`; the service applies the same fallback.
        $assignee = $seesAll ? ($request->filterString('assignee') ?? FollowUpFilters::ASSIGNEE_ME) : FollowUpFilters::ASSIGNEE_ME;

        $anchor = $this->anchorDate($request, $zone);
        $status = $request->filterString('status') ?? LeadFollowUpStatus::Pending->value;

        $listRange = $request->dateRange() ?? DateRange::custom(
            CarbonImmutable::now($zone)->subDays(30)->startOfDay(),
            CarbonImmutable::now($zone)->addDays(60)->endOfDay(),
            $zone,
        );

        $gridStart = $mode === 'week' ? $anchor->startOfWeek($weekStartsOn) : $anchor->startOfMonth()->startOfWeek($weekStartsOn);
        $gridEnd = $mode === 'week'
            ? $gridStart->addDays(6)->endOfDay()
            : $anchor->endOfMonth()->endOfWeek(($weekStartsOn + 6) % 7);

        $filters = [
            'assignee' => $assignee,
            'type' => $request->filterString('type'),
            'status' => $status,
            'overdue' => $request->filterBool('overdue') === true ? '1' : null,
        ];

        $followUps = $view === 'list'
            ? $this->followUps->worklist($actor, $listRange, FollowUpFilters::fromArray($filters + ['per_page' => $this->perPage()]))
            : null;

        // The grid shows every status unless one was chosen, bounded to one page of the largest size the filter DTO
        // allows — a planning view, not an archive.
        $calendarFollowUps = $view === 'calendar'
            ? $this->followUps->worklist(
                $actor,
                DateRange::custom($gridStart, $gridEnd, $zone),
                FollowUpFilters::fromArray(['status' => $request->filterString('status'), 'per_page' => 100] + $filters),
            )->getCollection()
            : new Collection;

        return view('admin.leads.follow-ups.index', [
            'view' => $view,
            'followUps' => $followUps ?? new LengthAwarePaginator([], 0, $this->perPage()),
            'calendarFollowUps' => $calendarFollowUps,
            'calendarMode' => $mode,
            'anchorDate' => $anchor->format('Y-m-d'),
            'gridStart' => $gridStart->format('Y-m-d'),
            'gridEnd' => $gridEnd->format('Y-m-d'),
            'weekStartsOn' => $weekStartsOn,
            'sort' => 'scheduled_at',
            'direction' => 'asc',
            'filters' => $request->activeFilters(),
            'assignee' => $assignee,
            'status' => $status,
            'assigneeOptions' => $seesAll ? $this->usersHolding('leads.view') : [],
            'canViewAll' => $seesAll,
            'followUpTypeOptions' => LeadFollowUpType::options(),
            'followUpStatusOptions' => LeadFollowUpStatus::options(),
            'outcomeOptions' => LeadContactOutcome::options(),
            'followUpDefaultAt' => $this->followUpDefaultAt(),
            'followUpReminderMinutes' => $this->crmInt('follow_up_reminder_minutes', 60),
            'today' => CarbonImmutable::now($zone)->format('Y-m-d'),
        ]);
    }

    public function store(StoreLeadFollowUpRequest $request, Lead $lead): Response
    {
        $this->authorize('update', $lead);
        $this->authorize('scheduleFollowUp', $lead);

        return $this->attempt($request, function () use ($request, $lead): Response {
            $followUp = $this->followUps->schedule($lead, $request->toData());

            return $this->done(
                $request,
                'The follow-up was scheduled.',
                redirect()->route('admin.leads.show', ['lead' => $lead, 'tab' => 'follow-ups']),
                ['id' => (int) $followUp->getKey(), 'lead_id' => (int) $lead->getKey(), 'scheduled_at' => $followUp->scheduled_at?->toIso8601String()],
            );
        });
    }

    public function complete(CompleteLeadFollowUpRequest $request, Lead $lead, LeadFollowUp $followUp): Response
    {
        $this->assertBelongs($lead, $followUp);
        $this->authorize('complete', $followUp);

        return $this->attempt($request, function () use ($request, $lead, $followUp): Response {
            $this->followUps->complete($followUp, $request->toData());

            return $this->done(
                $request,
                'The follow-up was completed.',
                redirect()->route('admin.leads.show', ['lead' => $lead, 'tab' => 'follow-ups']),
                ['id' => (int) $followUp->getKey()],
            );
        });
    }

    public function reschedule(RescheduleLeadFollowUpRequest $request, Lead $lead, LeadFollowUp $followUp): Response
    {
        $this->assertBelongs($lead, $followUp);
        $this->authorize('complete', $followUp);
        $this->authorize('reschedule', $followUp);

        return $this->attempt($request, function () use ($request, $lead, $followUp): Response {
            $next = $this->followUps->reschedule($followUp, $request->scheduledAt(), $request->reasonText());

            return $this->done(
                $request,
                'The follow-up was rescheduled.',
                redirect()->route('admin.leads.show', ['lead' => $lead, 'tab' => 'follow-ups']),
                ['id' => (int) $next->getKey(), 'previous_id' => (int) $followUp->getKey()],
            );
        });
    }

    public function cancel(CancelLeadFollowUpRequest $request, Lead $lead, LeadFollowUp $followUp): Response
    {
        $this->assertBelongs($lead, $followUp);
        $this->authorize('complete', $followUp);
        $this->authorize('cancel', $followUp);

        return $this->attempt($request, function () use ($request, $lead, $followUp): Response {
            $this->followUps->cancel($followUp, $request->reasonText());

            return $this->done(
                $request,
                'The follow-up was cancelled.',
                redirect()->route('admin.leads.show', ['lead' => $lead, 'tab' => 'follow-ups']),
                ['id' => (int) $followUp->getKey()],
            );
        });
    }

    /**
     * The day the calendar is anchored on (`?date=`, else `?month=`, else today), in the display timezone.
     */
    private function anchorDate(CrmListRequest $request, string $zone): CarbonImmutable
    {
        $date = $request->filterString('date');

        if ($date !== null) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone);

                if ($parsed instanceof CarbonImmutable) {
                    return $parsed;
                }
            } catch (Throwable) {
                // fall through to the month / today
            }
        }

        return $request->month() ?? CarbonImmutable::now($zone)->startOfDay();
    }

    private function assertBelongs(Lead $lead, LeadFollowUp $followUp): void
    {
        $this->abortUnlessVisible((int) $followUp->lead_id === (int) $lead->getKey());
    }
}
