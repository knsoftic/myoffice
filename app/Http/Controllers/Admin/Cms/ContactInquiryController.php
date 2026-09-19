<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\ContactInquiryStatus;
use App\Enums\InquiryRoutingStatus;
use App\Enums\InquirySource;
use App\Enums\InquiryType;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForContent;
use App\Http\Controllers\Admin\Cms\Concerns\StreamsCsv;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\AssignRequest;
use App\Http\Requests\Cms\ChangeInquiryStatusRequest;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\MarkInquirySpamRequest;
use App\Http\Requests\Cms\UpdateContactInquiryRequest;
use App\Models\Activity;
use App\Models\Cms\ContactInquiry;
use App\Models\Cms\Service;
use App\Models\User;
use App\Services\Cms\CmsAuditor;
use App\Services\Cms\ContactInquiryService;
use App\Services\Cms\InquiryRouter;
use Generator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contact inquiries — the routing queue, `admin.contact-inquiries.*` (phase-04 §2.20, §6.10, §7.2,
 * §8.10, §9.1.2), `module:contact_inquiries`.
 *
 * **Row scoping (§9.1.2).** Every read goes through `ContactInquiry::visibleTo()`: `view_any` sees the
 * whole queue, anyone else only what is assigned to them. The referral snapshot columns are never part
 * of that decision (D37). A row outside the scope is a **404**.
 *
 * **The technical / PII block is a third gate (F-12.4).** Every query whose rows reach a response uses
 * `ContactInquiry::selectVisibleColumns()`, so `ip_address`, `user_agent`, `utm_*`, `referrer_url`,
 * `filled_in_seconds` and `spam_reason` are **not selected** unless the user holds
 * `contact_inquiries.view_logs` — absent from the query, the view and the CSV, not merely hidden
 * (resolutions §8 #6). Opening the metadata panel as a `view_logs` holder is recorded as a sensitive access
 * (§10.5).
 *
 * **Spam** rows appear only on the Spam tab (`ContactInquiry::tab()`). **Routing** (`Route now`,
 * `Route all pending`) is `contact_inquiries.change_status` (§4, §12 Q1) and goes through
 * `InquiryRouter`, which never throws for a missing or disabled target (§6.10.3); the view receives
 * `canRoute` per row and disables the button while the target is unavailable.
 */
final class ContactInquiryController extends Controller
{
    use RespondsForContent;
    use StreamsCsv;

    private const SORTABLE = ['name', 'email', 'inquiry_type', 'status', 'routing_status', 'created_at'];

    public function __construct(
        private readonly ContactInquiryService $inquiries,
        private readonly InquiryRouter $router,
        private readonly CmsAuditor $auditor,
    ) {}

    public function index(ContentListRequest $request): View
    {
        // `view`, not view_any: a reviewer holding only `view` gets the list of what is assigned to them
        // (§9.1.2, tests 55-56); the rows stay scoped by `visibleTo()`.
        $this->authorize('contact_inquiries.view');

        $user = $this->actor($request);
        $tab = $this->resolveTab($request, $user);
        $sort = $request->sortColumn(self::SORTABLE, 'created_at');
        $direction = $request->sortDirection('desc');

        $inquiries = $this->withAvailable($this->filteredQuery($request, $user, $tab), ['service', 'assignee'])
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $routing = [];

        foreach ($inquiries->getCollection() as $inquiry) {
            $routing[(int) $inquiry->getKey()] = [
                'canRoute' => $this->router->canRoute($inquiry),
                'waitingReason' => $this->router->waitingReason($inquiry),
            ];
        }

        return view('admin.contact-inquiries.index', [
            'inquiries' => $inquiries,
            'routing' => $routing,
            'tab' => $tab,
            'tabs' => $this->tabCounts($user),
            'filters' => $request->activeFilters(),
            'sort' => $sort,
            'direction' => $direction,
            'typeOptions' => InquiryType::options(),
            'statusOptions' => ContactInquiryStatus::options(),
            'routingOptions' => InquiryRoutingStatus::options(),
            'sourceOptions' => InquirySource::options(),
            'services' => Service::query()->withTrashed()->orderBy('name')->pluck('name', 'id')->all(),
            'assignees' => $this->assignees(),
            'targets' => $this->targets(),
            'contactUrl' => Route::has('site.contact.index') ? route('site.contact.index') : null,
            'showTechnical' => $user->can('contact_inquiries.view_logs'),
            'can' => $this->abilities($user),
        ]);
    }

    public function show(Request $request, string $inquiry): View
    {
        $this->authorize('contact_inquiries.view');

        $user = $this->actor($request);
        $id = $this->routeId($inquiry);
        $technical = $user->can('contact_inquiries.view_logs');

        $find = static fn (): ?ContactInquiry => ContactInquiry::query()
            ->visibleTo($user)
            ->selectVisibleColumns($user)
            ->find($id);

        $record = $find();

        $this->abortUnlessVisible($record instanceof ContactInquiry);
        $this->authorize('view', $record);

        // Opening a new inquiry marks it read — a queue side effect, so only for someone who works it.
        $status = $record->status instanceof ContactInquiryStatus ? $record->status : ContactInquiryStatus::tryFrom((string) $record->status);

        if ($status === ContactInquiryStatus::New && $user->can('contact_inquiries.change_status')) {
            $full = ContactInquiry::query()->find($id);

            if ($full instanceof ContactInquiry) {
                $this->inquiries->markRead($full);
                $record = $find() ?? $record;
            }
        }

        if ($technical) {
            // §10.5 / F-12.4: the technical block is a PII read, trailed like a CV download.
            $this->auditor->record(
                module: 'contact_inquiries',
                description: sprintf('Technical details of inquiry #%d viewed', $id),
                subject: $record,
                properties: ['contact_inquiry_id' => $id, 'sensitive' => true],
                event: 'technical_block_viewed',
            );
        }

        $this->loadAvailable($record, ['service', 'assignee', 'reader']);

        return view('admin.contact-inquiries.show', [
            'inquiry' => $record,
            'showTechnical' => $technical,
            'routedRecord' => $record->routedRecord(),
            'canRoute' => $this->router->canRoute($record),
            'waitingReason' => $this->router->waitingReason($record),
            'targets' => $this->targets(),
            'history' => $this->history($record),
            'statusOptions' => ContactInquiryStatus::options(),
            'assignees' => $user->can('contact_inquiries.assign') ? $this->assignees() : [],
            'can' => $this->abilities($user),
        ]);
    }

    /**
     * Internal response notes and, optionally, the status (§6.11).
     */
    public function update(UpdateContactInquiryRequest $request, ContactInquiry $inquiry): Response
    {
        $this->authorize('contact_inquiries.edit');
        $this->assertVisible($this->actor($request), $inquiry);
        $this->authorize('update', $inquiry);

        $current = $inquiry->status instanceof ContactInquiryStatus ? $inquiry->status : ContactInquiryStatus::tryFrom((string) $inquiry->status);
        $status = $request->inquiryStatus();
        $statusChanges = $status !== null && $status !== $current;

        if ($statusChanges) {
            $this->authorize('contact_inquiries.change_status');
        }

        return $this->attempt($request, function () use ($request, $inquiry, $status, $statusChanges): Response {
            if ($request->hasNotes()) {
                $this->inquiries->saveNotes($inquiry, $request->notes());
            }

            if ($statusChanges && $status !== null) {
                $this->inquiries->changeStatus($inquiry, $status);
            }

            return $this->done($request, 'The inquiry was saved.', redirect()->route('admin.contact-inquiries.show', $inquiry->getKey()));
        }, field: 'response_notes');
    }

    public function destroy(Request $request, ContactInquiry $inquiry): Response
    {
        $this->authorize('contact_inquiries.delete');
        $this->assertVisible($this->actor($request), $inquiry);
        $this->authorize('delete', $inquiry);

        return $this->attempt($request, function () use ($request, $inquiry): Response {
            $this->inquiries->delete($inquiry);

            return $this->done($request, 'The inquiry was deleted. Any lead or course inquiry created from it is kept.', redirect()->route('admin.contact-inquiries.index'));
        }, Response::HTTP_FORBIDDEN);
    }

    public function status(ChangeInquiryStatusRequest $request, ContactInquiry $inquiry): Response
    {
        $this->authorize('contact_inquiries.change_status');
        $this->assertVisible($this->actor($request), $inquiry);
        $this->authorize('changeStatus', $inquiry);

        $to = $request->inquiryStatus();

        return $this->attempt($request, function () use ($request, $inquiry, $to): Response {
            $this->inquiries->changeStatus($inquiry, $to, $request->note());

            return $this->done(
                $request,
                sprintf('The inquiry is now marked %s.', mb_strtolower($to->label())),
                null,
                ['id' => (int) $inquiry->getKey(), 'status' => $to->value],
            );
        }, field: 'status');
    }

    /**
     * *Route now* / *Retry routing* for one inquiry (§6.10.2, test 48). Idempotent: an inquiry that is
     * already routed creates nothing; a missing or disabled target leaves it pending, never an error.
     */
    public function route(Request $request, ContactInquiry $inquiry): Response
    {
        $this->authorize('contact_inquiries.change_status');
        $this->assertVisible($this->actor($request), $inquiry);
        $this->authorize('route', $inquiry);

        return $this->attempt($request, function () use ($request, $inquiry): Response {
            $outcome = $this->inquiries->routeNow($inquiry);
            $fresh = ContactInquiry::query()->find($inquiry->getKey()) ?? $inquiry;

            [$message, $type] = match ($outcome) {
                InquiryRoutingStatus::Routed => ['The inquiry was routed.', 'success'],
                InquiryRoutingStatus::Pending => ['Not routed yet: '.($this->router->waitingReason($fresh) ?? 'waiting for its target').'. The inquiry stays in the queue.', 'warning'],
                InquiryRoutingStatus::Failed => ['Routing failed after several attempts. The inquiry is kept and can be retried.', 'error'],
                default => ['This inquiry is not routed anywhere (a general inquiry or spam).', 'info'],
            };

            return $this->done(
                $request,
                $message,
                null,
                [
                    'id' => (int) $fresh->getKey(),
                    'routing_status' => $outcome->value,
                    'routed_id' => $fresh->routed_id,
                    'routing_attempts' => (int) $fresh->routing_attempts,
                ],
                $type,
            );
        }, field: 'routing');
    }

    /**
     * *Route all pending* — "7 routed, 3 still waiting" (§8.10). Walks pending and failed, non-spam,
     * oldest first; safe to run repeatedly.
     */
    public function routePending(Request $request): Response
    {
        $this->authorize('contact_inquiries.change_status');
        $this->authorize('routePending', ContactInquiry::class);

        return $this->attempt($request, function () use ($request): Response {
            $result = $this->router->routePending();
            $routed = (int) ($result['routed'] ?? 0);
            $pending = (int) ($result['pending'] ?? 0);
            $failed = (int) ($result['failed'] ?? 0);

            $message = sprintf('%d routed, %d still waiting.', $routed, $pending);

            if ($failed > 0) {
                $message .= sprintf(' %d failed and can be retried.', $failed);
            }

            return $this->done($request, $message, null, ['routed' => $routed, 'pending' => $pending, 'failed' => $failed]);
        }, field: 'routing');
    }

    public function assign(AssignRequest $request, ContactInquiry $inquiry): Response
    {
        $this->authorize('contact_inquiries.assign');
        $this->assertVisible($this->actor($request), $inquiry);
        $this->authorize('assign', $inquiry);

        $assignee = $request->assignee();

        return $this->attempt($request, function () use ($request, $inquiry, $assignee): Response {
            $this->inquiries->assign($inquiry, $assignee);

            return $this->done(
                $request,
                $assignee === null ? 'The inquiry is now unassigned.' : sprintf('Assigned to %s.', $assignee->name),
                null,
                ['id' => (int) $inquiry->getKey(), 'assigned_to' => $assignee?->getKey()],
            );
        }, field: 'user_id');
    }

    public function spam(MarkInquirySpamRequest $request, ContactInquiry $inquiry): Response
    {
        $this->authorize('contact_inquiries.change_status');
        $this->assertVisible($this->actor($request), $inquiry);
        $this->authorize('markSpam', $inquiry);

        return $this->attempt($request, function () use ($request, $inquiry): Response {
            $this->inquiries->markSpam($inquiry, (string) $request->reason());

            return $this->done($request, 'Marked as spam. It will not be routed; anything already created from it is kept.', null, ['id' => (int) $inquiry->getKey(), 'is_spam' => true]);
        }, field: 'reason');
    }

    /**
     * "Not spam" clears the flags and re-queues routing (§6.10.5, test 53).
     */
    public function notSpam(Request $request, ContactInquiry $inquiry): Response
    {
        $this->authorize('contact_inquiries.change_status');
        $this->assertVisible($this->actor($request), $inquiry);
        $this->authorize('markNotSpam', $inquiry);

        return $this->attempt($request, function () use ($request, $inquiry): Response {
            $this->inquiries->markNotSpam($inquiry);

            return $this->done($request, 'Moved back to the queue. Routing has been queued again.', null, ['id' => (int) $inquiry->getKey(), 'is_spam' => false]);
        });
    }

    /**
     * CSV of the filtered, visible inquiries. The technical block is included only for `view_logs`.
     */
    public function export(ContentListRequest $request): StreamedResponse
    {
        $this->authorize('contact_inquiries.export');

        $user = $this->actor($request);
        $technical = $user->can('contact_inquiries.view_logs');
        $tab = $this->resolveTab($request, $user);

        $header = ['ID', 'Received at (UTC)', 'Type', 'Name', 'Email', 'Phone', 'WhatsApp', 'Company', 'Service', 'Course', 'Budget', 'Subject', 'Message', 'Source', 'Status', 'Routing', 'Routing target', 'Assigned to'];

        if ($technical) {
            $header = array_merge($header, ['IP address', 'User agent', 'Referrer', 'UTM source', 'UTM medium', 'UTM campaign', 'Fill time (s)', 'Spam reason']);
        }

        $rows = function () use ($request, $user, $tab, $technical): Generator {
            $query = $this->withAvailable($this->filteredQuery($request, $user, $tab), ['service', 'assignee'])->orderByDesc('created_at');

            foreach ($query->cursor() as $inquiry) {
                $row = [
                    $inquiry->getKey(),
                    $inquiry->created_at,
                    $inquiry->inquiry_type,
                    $inquiry->name,
                    $inquiry->email,
                    $inquiry->phone,
                    $inquiry->whatsapp,
                    $inquiry->company,
                    $inquiry->relationLoaded('service') ? $inquiry->service?->name : null,
                    $inquiry->course_name,
                    $inquiry->budget,
                    $inquiry->subject,
                    $inquiry->message,
                    $inquiry->source,
                    $inquiry->status,
                    $inquiry->routing_status,
                    $inquiry->routing_target,
                    $inquiry->relationLoaded('assignee') ? $inquiry->assignee?->name : null,
                ];

                if ($technical) {
                    $row = array_merge($row, [
                        $inquiry->ip_address, $inquiry->user_agent, $inquiry->referrer_url, $inquiry->utm_source,
                        $inquiry->utm_medium, $inquiry->utm_campaign, $inquiry->filled_in_seconds, $inquiry->spam_reason,
                    ]);
                }

                yield $row;
            }
        };

        return $this->csv('contact-inquiries-'.Carbon::now()->format('Y-m-d').'.csv', $header, $rows());
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function assertVisible(User $user, ContactInquiry $inquiry): void
    {
        $this->abortUnlessVisible(ContactInquiry::query()->withTrashed()->visibleTo($user)->whereKey($inquiry->getKey())->exists());
    }

    private function resolveTab(ContentListRequest $request, User $user): string
    {
        $tab = $request->filterString('tab');
        $tab = in_array($tab, ContactInquiry::TABS, true) ? $tab : 'all';

        return $tab === 'trashed' && ! $user->can('contact_inquiries.restore') ? 'all' : $tab;
    }

    /**
     * The §8.10 filters over the visible, column-restricted rows of one tab.
     *
     * @return Builder<ContactInquiry>
     */
    private function filteredQuery(ContentListRequest $request, User $user, string $tab): Builder
    {
        $search = $request->searchTerm();
        $type = $request->filterEnum('type', InquiryType::class);
        $status = $request->filterEnum('status', ContactInquiryStatus::class);
        $routing = $request->filterEnum('routing', InquiryRoutingStatus::class);
        $source = $request->filterEnum('source', InquirySource::class);
        $assigned = $request->filterId('assigned');
        $unassigned = $request->filterBool('unassigned');
        $service = $request->filterId('service');
        $from = $request->fromDate();
        $to = $request->toDate();

        return ContactInquiry::query()
            ->visibleTo($user)
            ->selectVisibleColumns($user)
            ->tab($tab)
            ->when($type instanceof InquiryType, static fn (Builder $query) => $query->where('inquiry_type', $type->value))
            ->when($status instanceof ContactInquiryStatus, static fn (Builder $query) => $query->where('status', $status->value))
            ->when($routing instanceof InquiryRoutingStatus, static fn (Builder $query) => $query->where('routing_status', $routing->value))
            ->when($source instanceof InquirySource, static fn (Builder $query) => $query->where('source', $source->value))
            ->when($assigned !== null, static fn (Builder $query) => $query->where('assigned_to', $assigned))
            ->when($unassigned === true, static fn (Builder $query) => $query->whereNull('assigned_to'))
            ->when($service !== null, static fn (Builder $query) => $query->where('service_id', $service))
            ->when($from !== null, static fn (Builder $query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, static fn (Builder $query) => $query->where('created_at', '<=', $to))
            ->when($search !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', $this->like($search))
                    ->orWhere('email', 'like', $this->like($search))
                    ->orWhere('phone', 'like', $this->like($search))
                    ->orWhere('subject', 'like', $this->like($search))
                    ->orWhere('message', 'like', $this->like($search));
            }));
    }

    /**
     * Live tab counts through the same scopes as the list.
     *
     * @return array<string, int>
     */
    private function tabCounts(User $user): array
    {
        $counts = [];

        foreach (ContactInquiry::TABS as $tab) {
            if ($tab === 'trashed' && ! $user->can('contact_inquiries.restore')) {
                continue;
            }

            $counts[$tab] = ContactInquiry::query()->visibleTo($user)->tab($tab)->count();
        }

        return $counts;
    }

    /**
     * The routing targets keyed by target key: whether one is registered and available (§6.10.3).
     *
     * @return array<string, array{label: string, registered: bool, available: bool}>
     */
    private function targets(): array
    {
        $defaults = [InquiryType::TARGET_CRM_LEAD => 'CRM lead', InquiryType::TARGET_COURSE_INQUIRY => 'Course inquiry'];
        $targets = [];

        foreach (InquiryType::cases() as $type) {
            $key = $type->routingTarget();

            if ($key === null) {
                continue;
            }

            $target = $this->router->target($key);

            $targets[$key] = [
                'label' => $target?->label() ?? ($defaults[$key] ?? $key),
                'registered' => $target !== null,
                'available' => $target !== null && $target->isAvailable(),
            ];
        }

        return $targets;
    }

    /**
     * Users an inquiry may be assigned to: holders of `contact_inquiries.view_any` or `contact_inquiries.view`
     * (§6.11, §9.1.2 — a reviewer without the whole queue is exactly who an inquiry is handed to), active only.
     *
     * @return array<int, string>
     */
    private function assignees(): array
    {
        return User::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(static fn (User $candidate): bool => $candidate->can('contact_inquiries.view_any') || $candidate->can('contact_inquiries.view'))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The routing / status history from the activity log (§8.10 detail screen). The model keeps the
     * technical columns out of its own activity entries.
     *
     * @return list<array<string, mixed>>
     */
    private function history(ContactInquiry $inquiry): array
    {
        return Activity::query()
            ->where('subject_type', $inquiry->getMorphClass())
            ->where('subject_id', $inquiry->getKey())
            ->with('causer')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(static fn (Activity $activity): array => [
                'id' => (int) $activity->getKey(),
                'event' => $activity->event,
                'description' => $activity->description,
                'causer' => $activity->causer?->getAttribute('name'),
                'at' => $activity->created_at,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, bool>
     */
    private function abilities(User $user): array
    {
        return [
            'edit' => $user->can('contact_inquiries.edit'),
            'delete' => $user->can('contact_inquiries.delete'),
            'changeStatus' => $user->can('contact_inquiries.change_status'),
            // The backlog walk reaches rows the user is not assigned (ContactInquiryPolicy::routePending()).
            'routePending' => $user->can('contact_inquiries.change_status') && $user->can('contact_inquiries.view_any'),
            'assign' => $user->can('contact_inquiries.assign'),
            'export' => $user->can('contact_inquiries.export'),
            'restore' => $user->can('contact_inquiries.restore'),
            'technical' => $user->can('contact_inquiries.view_logs'),
        ];
    }
}
