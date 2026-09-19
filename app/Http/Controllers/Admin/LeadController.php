<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\Referrals\ReferralRecorder;
use App\DataObjects\Crm\BulkResult;
use App\DataObjects\Crm\DuplicateReport;
use App\DataObjects\Crm\LeadFilters;
use App\Enums\InquirySource;
use App\Enums\LeadActivityType;
use App\Enums\LeadContactOutcome;
use App\Enums\LeadFollowUpType;
use App\Enums\LeadStatus;
use App\Http\Controllers\Admin\Crm\Concerns\RespondsForCrm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\AssignLeadRequest;
use App\Http\Requests\Crm\BulkLeadAssignRequest;
use App\Http\Requests\Crm\BulkLeadDestroyRequest;
use App\Http\Requests\Crm\BulkLeadStatusRequest;
use App\Http\Requests\Crm\ChangeLeadStatusRequest;
use App\Http\Requests\Crm\CrmListRequest;
use App\Http\Requests\Crm\DeleteRecordRequest;
use App\Http\Requests\Crm\DownloadCrmExportRequest;
use App\Http\Requests\Crm\DuplicateCheckRequest;
use App\Http\Requests\Crm\LinkDuplicateLeadRequest;
use App\Http\Requests\Crm\StoreLeadRequest;
use App\Http\Requests\Crm\UpdateLeadRequest;
use App\Models\Crm\Lead;
use App\Models\User;
use App\Services\Crm\CrmExportFiles;
use App\Services\Crm\LeadDuplicateDetector;
use App\Services\Crm\LeadExporter;
use App\Services\Crm\LeadService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Leads — the pipeline as a working list, the lead record and every per-lead action that is not a board move,
 * a timeline entry, a follow-up or a conversion (phase-05 §6.1, §6.2, §7, §8.1, §8.3, §8.4), `module:leads`.
 *
 * **Visibility (§9.1, D30).** `leads.view_any` sees the whole pipeline; `leads.view` alone sees only leads the user is
 * assigned to or created. `LeadVisibilityScope` applies that to every `Lead` query and to route-model binding, so
 * another rep's lead id is a **404** before an action runs; each list query here also names `visibleTo($actor)`
 * explicitly, so the rule survives even a `withoutGlobalScopes()` refactor.
 *
 * **Thin.** Every write goes through `LeadService` (duplicate detection through `LeadDuplicateDetector`, exports
 * through `LeadExporter`); a rule the service refuses comes back as `CrmRuleException` and is answered as a 422 or
 * a toast. Every action repeats its route's `can:` before touching anything.
 */
final class LeadController extends Controller
{
    use RespondsForCrm;

    private const SORTABLE = ['lead_no', 'name', 'budget_amount', 'status', 'follow_up_at', 'last_activity_at', 'created_at'];

    public function __construct(
        private readonly LeadService $leads,
        private readonly LeadDuplicateDetector $detector,
    ) {}

    /**
     * §8.1 — search, the eleven filters, sortable headers, pagination, bulk selection.
     */
    public function index(CrmListRequest $request): View
    {
        $this->authorize('leads.view');
        $this->authorize('viewAny', Lead::class);

        $actor = $this->actor($request);
        $sort = $request->sortColumn(self::SORTABLE, 'created_at');
        $direction = $request->sortDirection('desc');
        $filters = $this->leadFilters($request, $actor);

        $leads = $filters
            ->apply(Lead::query()->visibleTo($actor), (int) $actor->getKey())
            ->with(['assignee:id,name,email', 'service:id,name'])
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        return view('admin.leads.index', array_merge($this->dialogData($actor), [
            'leads' => $leads,
            'filters' => $request->activeFilters(),
            'sort' => $sort,
            'direction' => $direction,
            'trashed' => $filters->trashed,
            'statusOptions' => $this->statusOptions(),
            'sourceOptions' => InquirySource::options(),
            'serviceOptions' => $this->serviceOptions(),
            'isEmptyModule' => ! $filters->trashed && ! Lead::query()->visibleTo($actor)->exists(),
            'bulkMax' => $this->crmInt('bulk_max_ids', 200, 1),
            'staleDays' => $this->crmInt('stale_lead_days', 7),
            'whatsappTemplate' => $this->whatsappTemplate(),
        ]));
    }

    public function create(Request $request): View
    {
        $this->authorize('leads.create');
        $this->authorize('create', Lead::class);

        $actor = $this->actor($request);
        $default = InquirySource::tryFrom((string) $this->crmSetting('default_lead_source', InquirySource::Website->value)) ?? InquirySource::Website;
        $referralCode = $request->query('ref');

        return view('admin.leads.create', array_merge($this->formData(), [
            'lead' => new Lead(['source' => $default->value]),
            'assigneeOptions' => $actor->can('leads.assign') ? $this->usersHolding('leads.view') : [],
            'followUpTypeOptions' => LeadFollowUpType::options(),
            'followUpDefaultAt' => $this->followUpDefaultAt(),
            'followUpReminderMinutes' => $this->crmInt('follow_up_reminder_minutes', 60),
            'defaultSource' => $default->value,
            'autoAssignMode' => (string) $this->crmSetting('auto_assign_mode', 'off'),
            'referralCode' => is_string($referralCode) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/', $referralCode) === 1 ? $referralCode : null,
        ]));
    }

    public function store(StoreLeadRequest $request): Response
    {
        $this->authorize('leads.create');
        $this->authorize('create', Lead::class);

        $link = $request->duplicateLink();

        return $this->attempt($request, function () use ($request, $link): Response {
            $lead = $this->leads->create($request->toData());

            if ($link !== null) {
                $this->leads->linkDuplicate($lead, $link['lead'], $link['note'] !== '' ? $link['note'] : 'Linked while creating the lead.');
            }

            return $this->done(
                $request,
                sprintf('Lead %s was added.', $lead->lead_no),
                redirect()->route('admin.leads.show', $lead),
                ['id' => (int) $lead->getKey(), 'lead_no' => $lead->lead_no, 'url' => route('admin.leads.show', $lead)],
            );
        });
    }

    /**
     * §8.3 — overview, timeline, follow-ups and conversion on one screen.
     */
    public function show(Request $request, Lead $lead): View
    {
        $this->authorize('view', $lead);

        $actor = $this->actor($request);
        $typeValue = $request->query('type');
        $type = is_string($typeValue) ? LeadActivityType::tryFrom($typeValue) : null;

        $lead->load([
            'assignee:id,name,email',
            'assigner:id,name',
            'converter:id,name',
            'creator:id,name',
            'service:id,name',
            'client',
            'duplicateOf',
            'leadImport',
        ]);

        $activities = $lead->activities()
            ->with(['creator:id,name', 'fromUser:id,name', 'toUser:id,name', 'relatedLead'])
            ->when($type instanceof LeadActivityType, static fn ($query) => $query->where('type', $type?->value))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50, ['*'], 'timeline_page')
            ->withQueryString();

        $followUps = $lead->followUps()
            ->with(['assignee:id,name', 'completedBy:id,name'])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->get();

        return view('admin.leads.show', array_merge($this->dialogData($actor), [
            'lead' => $lead,
            'activities' => $activities,
            'activityType' => $type?->value,
            'followUps' => $followUps,
            'openFollowUp' => $followUps->first(static fn ($followUp): bool => $followUp->status?->isOpen() === true),
            'conversions' => $lead->conversions()->with(['client', 'convertedBy:id,name'])->orderByDesc('id')->get(),
            'duplicateReport' => $this->safeReport(fn (): DuplicateReport => $this->detector->checkLead($lead, $actor)),
            'referral' => $this->referral($lead),
            'allActivityTypeOptions' => LeadActivityType::options(),
            'whatsappTemplate' => $this->whatsappTemplate(),
            'staleDays' => $this->crmInt('stale_lead_days', 7),
            'canConvert' => $actor->can('convert', $lead) && Route::has('admin.leads.convert.form'),
        ]));
    }

    public function edit(Lead $lead): View
    {
        $this->authorize('update', $lead);

        $lead->load('assignee:id,name');

        return view('admin.leads.edit', array_merge($this->formData(), [
            'lead' => $lead,
        ]));
    }

    public function update(UpdateLeadRequest $request, Lead $lead): Response
    {
        $this->authorize('update', $lead);

        return $this->attempt($request, function () use ($request, $lead): Response {
            $lead = $this->leads->update($lead, $request->toData());

            return $this->done(
                $request,
                sprintf('Lead %s was updated.', $lead->lead_no),
                redirect()->route('admin.leads.show', $lead),
                ['id' => (int) $lead->getKey()],
            );
        });
    }

    /**
     * Soft delete, with the reason. Refused while a live conversion exists (supersede it first, §6.1).
     */
    public function destroy(DeleteRecordRequest $request, Lead $lead): Response
    {
        $this->authorize('delete', $lead);

        return $this->attempt($request, function () use ($request, $lead): Response {
            $this->leads->delete($lead, (string) $request->reason());

            return $this->done($request, sprintf('Lead %s was deleted.', $lead->lead_no), redirect()->route('admin.leads.index'));
        });
    }

    /**
     * The route binds `withTrashed()`; the visibility scope still applies, so a hidden lead stays a 404.
     */
    public function restore(Request $request, Lead $lead): Response
    {
        $this->authorize('leads.restore');
        $this->authorize('restore', $lead);

        return $this->attempt($request, function () use ($request, $lead): Response {
            $this->leads->restore($lead);

            return $this->done($request, sprintf('Lead %s was restored.', $lead->lead_no), redirect()->route('admin.leads.show', $lead));
        });
    }

    public function print(Request $request, Lead $lead): View
    {
        $this->authorize('leads.print');
        $this->authorize('print', $lead);

        $lead->load(['assignee:id,name', 'service:id,name', 'client']);

        return view('admin.leads.print', [
            'lead' => $lead,
            'activities' => $lead->activities()->with('creator:id,name')->orderByDesc('occurred_at')->orderByDesc('id')->limit(25)->get(),
            'followUps' => $lead->followUps()->with('assignee:id,name')->orderByDesc('scheduled_at')->orderByDesc('id')->limit(25)->get(),
            'companyName' => $this->companyName(),
            'printedBy' => $this->actor($request),
        ]);
    }

    /**
     * A status change outside the board (the lead page and the list's row action). The board posts to
     * `LeadBoardController::move()`, which answers with refreshed column figures.
     */
    public function status(ChangeLeadStatusRequest $request, Lead $lead): Response
    {
        $this->authorize('changeStatus', $lead);

        $to = $request->targetStatus();

        return $this->attempt($request, function () use ($request, $lead, $to): Response {
            $lead = $this->leads->changeStatus($lead, $to, $request->toData());

            return $this->done(
                $request,
                sprintf('Lead %s is now %s.', $lead->lead_no, mb_strtolower($to->label())),
                null,
                [
                    'id' => (int) $lead->getKey(),
                    'status' => $to->value,
                    'status_label' => $to->label(),
                    'convert_url' => $to === LeadStatus::Won ? $this->convertUrl($request, $lead) : null,
                ],
            );
        });
    }

    public function assign(AssignLeadRequest $request, Lead $lead): Response
    {
        $this->authorize('assign', $lead);

        $assignee = $request->assignee();

        return $this->attempt($request, function () use ($request, $lead, $assignee): Response {
            $this->leads->assign($lead, $assignee, $request->reason());

            return $this->done(
                $request,
                $assignee instanceof User ? sprintf('Lead %s is now assigned to %s.', $lead->lead_no, $assignee->name) : sprintf('Lead %s is now unassigned.', $lead->lead_no),
                null,
                ['id' => (int) $lead->getKey(), 'assigned_to' => $assignee?->getKey(), 'assignee_name' => $assignee?->name],
            );
        });
    }

    /**
     * "187 assigned, 3 skipped" — explicit ids only; ids the actor cannot see come back as `forbidden`.
     */
    public function bulkAssign(BulkLeadAssignRequest $request): Response
    {
        $this->authorize('leads.assign');

        return $this->attempt($request, function () use ($request): Response {
            $result = $this->leads->bulkAssign($request->ids(), $request->assignee(), $request->reason());

            return $this->bulkDone($request, $result, 'assigned');
        });
    }

    /**
     * Each row is checked against §2.11 on its own: an illegal move is a per-row `skipped` with its reason.
     */
    public function bulkStatus(BulkLeadStatusRequest $request): Response
    {
        $this->authorize('leads.change_status');

        return $this->attempt($request, function () use ($request): Response {
            $result = $this->leads->bulkChangeStatus($request->ids(), $request->targetStatus(), $request->statusReason());

            return $this->bulkDone($request, $result, 'moved');
        });
    }

    /**
     * Bulk soft delete: a lead with a live conversion is a per-row `skipped`, an id the actor cannot see is
     * `forbidden` and untouched.
     */
    public function bulkDestroy(BulkLeadDestroyRequest $request): Response
    {
        $this->authorize('leads.delete');

        return $this->attempt($request, function () use ($request): Response {
            $result = $this->leads->bulkDelete($request->ids(), (string) $request->reason());

            return $this->bulkDone($request, $result, 'deleted');
        });
    }

    /**
     * §8.4 live duplicate check. A match on a lead the actor may not see is `restricted`: match type and record type
     * only — no name, contact, id or link (test 23).
     */
    public function duplicateCheck(DuplicateCheckRequest $request): JsonResponse
    {
        $this->authorize('leads.create');

        $actor = $this->actor($request);
        $candidate = $request->candidate();
        $report = $this->detector->check($candidate, $request->ignoredLeadId(), $actor);
        $payload = $report->toArray();

        $payload['matches'] = array_map(static function (array $match): array {
            $match['match_type_label'] = $match['match_label'] ?? null;
            $match['record_type_label'] = match ($match['record_type'] ?? null) {
                'lead' => 'Lead',
                'client' => 'Client',
                'client_contact' => 'Client contact',
                default => null,
            };

            if (($match['restricted'] ?? true) === false) {
                $match['lead_id'] = ($match['record_type'] ?? null) === 'lead' ? ($match['id'] ?? null) : null;
            }

            return $match;
        }, $payload['matches']);

        $payload['block_on_exact'] = $this->crmBool('duplicate_block_on_exact', false);

        return new JsonResponse($payload);
    }

    public function duplicateLink(LinkDuplicateLeadRequest $request, Lead $lead): Response
    {
        $this->authorize('update', $lead);

        $original = $request->original();

        return $this->attempt($request, function () use ($request, $lead, $original): Response {
            $this->leads->linkDuplicate($lead, $original, $request->note());

            return $this->done(
                $request,
                sprintf('Lead %s is now marked as a duplicate of %s.', $lead->lead_no, $original->lead_no),
                redirect()->route('admin.leads.show', $lead),
                ['id' => (int) $lead->getKey(), 'duplicate_of_lead_id' => (int) $original->getKey()],
            );
        });
    }

    /**
     * CSV of the filtered, visible leads (§6.10 `LeadExporter`). Above `crm.export_max_rows` the exporter queues
     * `BuildCrmExport` instead and the user is told a notification will follow (test 84).
     */
    public function export(CrmListRequest $request): Response
    {
        $this->authorize('leads.export');
        $this->authorize('export', Lead::class);

        $actor = $this->actor($request);
        $response = app(LeadExporter::class)->stream($this->leadFilters($request, $actor), $request->filterList('columns'));

        if ($response instanceof Response) {
            return $response;
        }

        return $this->done($request, 'The export is larger than can be downloaded at once. It is being prepared and you will be notified when it is ready.', null, ['queued' => true], 'info');
    }

    /**
     * The file of a queued export, from the notification's link. `CrmExportFiles` refuses anyone but its requester.
     */
    public function exportDownload(DownloadCrmExportRequest $request): Response
    {
        $this->authorize('leads.export');

        return app(CrmExportFiles::class)->download(LeadExporter::TYPE, $request->token(), $this->actor($request));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The list filters as the service's DTO.
     */
    private function leadFilters(CrmListRequest $request, User $actor): LeadFilters
    {
        return LeadFilters::fromArray($this->leadFilterInput($request, $actor));
    }

    /**
     * The list filters in the DTO's keys — also what a queued export stores to rebuild them. The trashed view is
     * honoured only for `leads.restore`.
     *
     * @return array<string, mixed>
     */
    private function leadFilterInput(CrmListRequest $request, User $actor): array
    {
        return [
            'search' => $request->searchTerm(),
            'status' => array_map(static fn (LeadStatus $status): string => $status->value, $request->filterEnums('status', LeadStatus::class)),
            'source' => array_map(static fn (InquirySource $source): string => $source->value, $request->filterEnums('source', InquirySource::class)),
            'assignee' => $request->filterString('assignee') === 'all' ? null : $request->filterString('assignee'),
            'follow_up' => $request->filterString('follow_up'),
            'created_preset' => $request->filterString('range'),
            'created_from' => $request->filterString('from'),
            'created_to' => $request->filterString('to'),
            'budget_min' => $request->filterMoney('budget_min'),
            'budget_max' => $request->filterMoney('budget_max'),
            'has_duplicate' => $request->filterBool('has_duplicate'),
            'converted' => $request->filterBool('converted'),
            'trashed' => $request->filterBool('trashed') === true && $actor->can('leads.restore'),
            'service_id' => $request->filterId('service_id'),
        ];
    }

    /**
     * The variables of `admin.leads.partials.dialogs` (status, assign, activity, follow-up and bulk dialogs).
     *
     * @return array<string, mixed>
     */
    private function dialogData(User $actor): array
    {
        return [
            'assigneeOptions' => $actor->can('leads.assign') ? $this->usersHolding('leads.view') : [],
            'statusLabels' => $this->statusOptions(),
            'transitions' => $this->transitions(),
            'followUpRequiredStatuses' => $this->followUpRequiredStatuses(),
            'lostReasons' => $this->lostReasons(),
            'activityTypeOptions' => $this->manualActivityTypeOptions(),
            'outcomeOptions' => LeadContactOutcome::options(),
            'followUpTypeOptions' => LeadFollowUpType::options(),
            'followUpDefaultAt' => $this->followUpDefaultAt(),
            'followUpReminderMinutes' => $this->crmInt('follow_up_reminder_minutes', 60),
        ];
    }

    /**
     * The variables of `admin.leads.partials.form`.
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'serviceOptions' => $this->serviceOptions(),
            'sourceOptions' => InquirySource::options(),
            'duplicateCheckEnabled' => $this->crmBool('duplicate_detection_enabled', true),
            'duplicateBlockOnExact' => $this->crmBool('duplicate_block_on_exact', false),
        ];
    }

    /**
     * The referral block of §8.3: the captured code and when it was recorded are on the lead itself; the resolved
     * collaborator is shown only while a real `ReferralRecorder` is bound.
     *
     * @return array{available: bool, code: ?string, recorded_at: mixed, collaborator_name: ?string, collaborator_code: ?string}
     */
    private function referral(Lead $lead): array
    {
        $recorder = app(ReferralRecorder::class);
        $available = $recorder->isAvailable();
        $recorded = null;

        if ($available) {
            try {
                $recorded = $recorder->activeReferralFor($lead);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return [
            'available' => $available,
            'code' => $lead->referral_code_captured,
            'recorded_at' => $lead->referral_recorded_at,
            'collaborator_name' => $recorded?->collaboratorName,
            'collaborator_code' => $recorded?->referralCode,
        ];
    }

    /**
     * A duplicate report for a screen that must still render if detection fails.
     *
     * @param  callable(): DuplicateReport  $check
     */
    private function safeReport(callable $check): ?DuplicateReport
    {
        try {
            return $check();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function companyName(): ?string
    {
        try {
            $name = setting('company.name');
        } catch (Throwable) {
            return null;
        }

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    private function convertUrl(Request $request, Lead $lead): ?string
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->can('convert', $lead) && Route::has('admin.leads.convert.form')
            ? route('admin.leads.convert.form', $lead)
            : null;
    }

    private function bulkDone(Request $request, BulkResult $result, string $verb): Response
    {
        $done = $result->count(BulkResult::DONE);

        return $this->done(
            $request,
            $result->message($verb).'.',
            null,
            $result->toArray(),
            $done === 0 ? 'warning' : 'success',
        );
    }
}
