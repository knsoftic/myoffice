<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Collaborator;

use App\Enums\CollaborationType;
use App\Enums\CollaboratorStatus;
use App\Http\Controllers\Controller;
use App\Models\Cms\Service;
use App\Models\Collaborator\Collaborator;
use App\Services\Collaborator\CollaboratorActivityService;
use App\Services\Collaborator\CollaboratorCodeService;
use App\Services\Collaborator\CollaboratorOnboardingService;
use App\Services\Collaborator\CollaboratorService;
use App\Support\Collaborator\CollaboratorData;
use App\Support\CsvWriter;
use App\Support\Modules;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Collaborators — `admin.collaborators.*` (phase-08-09 §7.1, §8.1–8.3), `module:collaborators`.
 *
 * **Money is omitted, not hidden.** Without `collaborators.view_financial` the earnings figures are
 * absent from the view data and from the markup entirely — hiding a column with CSS puts it in the page
 * source, which is the same as publishing it (§9).
 *
 * **The picker endpoint is deliberately narrow.** A Sales Executive attributing a lead needs a name and
 * a code; they do not need an email, a phone number, a rate or a balance, so `options()` selects five
 * columns and nothing can widen it from the request.
 */
final class CollaboratorController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CollaboratorService $collaborators,
        private readonly CollaboratorOnboardingService $onboarding,
        private readonly CollaboratorCodeService $codes,
        private readonly CollaboratorActivityService $activity,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Collaborator::class);

        $term = trim((string) $request->query('q', ''));

        return view('admin.collaborators.index', [
            'collaborators' => $this->filtered($request, $term)
                ->withCount('skills')
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'statuses' => $this->statusOptions(),
            'types' => $this->typeOptions(),
            'term' => $term,
            'showMoney' => (bool) $request->user()?->can('collaborators.view_financial'),
            'pendingCount' => $request->user()?->can('collaborators.approve')
                ? Collaborator::query()->where('status', CollaboratorStatus::Pending->value)->count()
                : null,
        ]);
    }

    /**
     * The approval queue (§7.1). Oldest first: an application that has waited longest is the one that
     * should be looked at, which is the opposite of every other list on the admin side.
     */
    public function pending(Request $request): View
    {
        $this->authorize('viewAny', Collaborator::class);

        return view('admin.collaborators.pending', [
            'collaborators' => Collaborator::query()
                ->where('status', CollaboratorStatus::Pending->value)
                ->withCount('skills')
                ->orderBy('applied_at')
                ->paginate(20)
                ->withQueryString(),
            'alertDays' => (int) setting('collaborator.pending_application_alert_days', 3),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Collaborator::class);

        return view('admin.collaborators.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Collaborator::class);

        $input = $this->validated($request);

        $collaborator = $this->collaborators->create(
            CollaboratorData::fromArray($input),
            $request->user(),
            active: (bool) ($input['activate'] ?? false),
        );

        return redirect()
            ->route('admin.collaborators.show', $collaborator)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('%s registered as %s.', $collaborator->displayName(), $collaborator->collaborator_code),
            ]);
    }

    public function show(Request $request, Collaborator $collaborator): View
    {
        $this->authorize('view', $collaborator);

        $collaborator->load(['skills', 'account:id,name,email,status', 'approver:id,name', 'statusChanger:id,name']);

        if (Schema::hasTable('collaborator_service')) {
            $collaborator->load('services:id,name');
        }

        return view('admin.collaborators.show', [
            'collaborator' => $collaborator,
            'showMoney' => $request->user()?->can('viewFinancial', $collaborator) === true,
            'canApprove' => $request->user()?->can('approve', $collaborator) === true,
            'canChangeStatus' => $request->user()?->can('changeStatus', $collaborator) === true,
            'canEdit' => $request->user()?->can('update', $collaborator) === true,
            'canSeeLogs' => $request->user()?->can('viewLogs', $collaborator) === true,
            'transitions' => $collaborator->status->allowedTransitions(),
            'referralUrls' => $this->codes->referralUrls($collaborator),
            'codeIsLocked' => $this->codes->referralCodeIsLocked($collaborator),
            'codeIsEditable' => (bool) setting('collaborator.referral_code_editable', true),
            // Absent, not zeroed, while the spine is not installed: a tile that showed 0.00 would be a
            // statement about money, and this phase has no right to make one (INV-C7).
            'spineInstalled' => Modules::enabled('collaborator_wallets') && Schema::hasTable('collaborator_wallets'),
        ]);
    }

    public function edit(Collaborator $collaborator): View
    {
        $this->authorize('update', $collaborator);

        return view('admin.collaborators.edit', array_merge($this->formData(), [
            'collaborator' => $collaborator->load('skills'),
            'serviceIds' => Schema::hasTable('collaborator_service')
                ? $collaborator->services()->pluck('services.id')->all()
                : [],
        ]));
    }

    public function update(Request $request, Collaborator $collaborator): RedirectResponse
    {
        $this->authorize('update', $collaborator);

        $this->collaborators->update(
            $collaborator,
            CollaboratorData::fromArray($this->validated($request, $collaborator)),
            $request->string('reason')->toString() ?: null,
        );

        return redirect()
            ->route('admin.collaborators.show', $collaborator)
            ->with('toast', ['type' => 'success', 'message' => 'Profile updated.']);
    }

    public function destroy(Request $request, Collaborator $collaborator): RedirectResponse
    {
        $this->authorize('delete', $collaborator);

        $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->collaborators->delete($collaborator, $request->string('reason')->toString());

        return redirect()
            ->route('admin.collaborators.index')
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('%s removed. The record and its history are kept.', $collaborator->displayName()),
            ]);
    }

    public function restore(Collaborator $collaborator): RedirectResponse
    {
        $this->authorize('restore', $collaborator);

        $this->collaborators->restore($collaborator);

        return redirect()
            ->route('admin.collaborators.show', $collaborator)
            ->with('toast', ['type' => 'success', 'message' => 'Collaborator restored.']);
    }

    public function approve(Request $request, Collaborator $collaborator): RedirectResponse
    {
        $this->authorize('approve', $collaborator);

        $input = $request->validate([
            'comment' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
            'provision_login' => ['nullable', 'boolean'],
        ]);

        $collaborator = $this->onboarding->approve(
            $collaborator,
            $request->user(),
            $input['comment'] ?? null,
            isset($input['joining_date']) ? Carbon::parse($input['joining_date']) : null,
            (bool) ($input['provision_login'] ?? true),
        );

        return redirect()
            ->route('admin.collaborators.show', $collaborator)
            ->with('toast', [
                'type' => 'success',
                'message' => $collaborator->user_id !== null
                    ? 'Approved. An invitation was sent to their email address.'
                    : 'Approved. No panel account was created.',
            ]);
    }

    public function reject(Request $request, Collaborator $collaborator): RedirectResponse
    {
        $this->authorize('reject', $collaborator);

        $input = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->onboarding->reject($collaborator, $input['reason'], $request->user());

        return redirect()
            ->route('admin.collaborators.pending')
            ->with('toast', ['type' => 'success', 'message' => 'Application refused, with the reason on the record.']);
    }

    public function status(Request $request, Collaborator $collaborator): RedirectResponse
    {
        $this->authorize('changeStatus', $collaborator);

        $input = $request->validate([
            'status' => ['required', Rule::enum(CollaboratorStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $target = CollaboratorStatus::from($input['status']);

        $this->onboarding->changeStatus($collaborator, $target, $input['reason'] ?? null, $request->user());

        return redirect()
            ->route('admin.collaborators.show', $collaborator)
            ->with('toast', ['type' => 'success', 'message' => sprintf('Now %s.', $target->label())]);
    }

    /**
     * Create the panel login for a collaborator who was approved without one.
     */
    public function storeUser(Request $request, Collaborator $collaborator): RedirectResponse
    {
        $this->authorize('update', $collaborator);

        $this->onboarding->provisionUser($collaborator, $request->user());

        return redirect()
            ->route('admin.collaborators.show', $collaborator)
            ->with('toast', [
                'type' => 'success',
                'message' => 'Panel account created. The invitation went to their email address.',
            ]);
    }

    public function referralCode(Request $request, Collaborator $collaborator): RedirectResponse
    {
        $this->authorize('update', $collaborator);

        $input = $request->validate([
            'referral_code' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->codes->changeReferralCode($collaborator, $input['referral_code'], $input['reason']);

        return redirect()
            ->route('admin.collaborators.show', $collaborator)
            ->with('toast', ['type' => 'success', 'message' => 'Referral code changed.']);
    }

    public function referralLinks(Collaborator $collaborator): View
    {
        $this->authorize('view', $collaborator);

        return view('admin.collaborators.referral-links', [
            'collaborator' => $collaborator,
            'urls' => $this->codes->referralUrls($collaborator),
            // The same base the two real links are built from, so the live preview cannot show a host
            // the link never has.
            'baseUrl' => $this->codes->baseUrl(),
            'queryParam' => (string) setting('collaborator.referral_query_param', 'ref'),
        ]);
    }

    public function activity(Request $request, Collaborator $collaborator): View
    {
        $this->authorize('viewLogs', $collaborator);

        return view('admin.collaborators.activity', [
            'collaborator' => $collaborator,
            'entries' => $this->activity->auditTrail($collaborator),
        ]);
    }

    /**
     * The §9 picker: five columns, `active` first, never a suspended or trashed partner.
     *
     * The column list is a literal here and cannot be widened from the request — the whole point of a
     * separate endpoint is that a role which may attribute a lead never receives a contact detail, a
     * rate or a balance along the way.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Collaborator::class);

        $term = trim((string) $request->query('q', ''));

        $rows = Collaborator::query()
            ->select(['id', 'collaborator_code', 'name', 'company_name', 'status'])
            ->whereIn('status', [
                CollaboratorStatus::Active->value,
                CollaboratorStatus::Pending->value,
                CollaboratorStatus::Inactive->value,
            ])
            ->when($term !== '', static fn ($query) => $query->where(static fn ($inner) => $inner
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('company_name', 'like', '%'.$term.'%')
                ->orWhere('collaborator_code', 'like', '%'.$term.'%')
                ->orWhere('referral_code', 'like', '%'.$term.'%')))
            // Active partners first; the other two are selectable but flagged, because somebody
            // genuinely does refer a student the week before their application is approved.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [CollaboratorStatus::Active->value])
            ->orderBy('name')
            ->limit(25)
            ->get();

        return response()->json([
            'data' => $rows->map(static fn (Collaborator $row): array => [
                'id' => $row->id,
                'code' => $row->collaborator_code,
                'name' => $row->name,
                'company' => $row->company_name,
                'status' => $row->status->value,
                'label' => $row->status->label(),
                'needs_confirmation' => $row->status->needsStaffConfirmationForReferral(),
            ])->all(),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse|RedirectResponse
    {
        $this->authorize('export', Collaborator::class);

        if ($format !== 'csv') {
            return back()->with('toast', ['type' => 'error', 'message' => 'Only CSV export is available.']);
        }

        // No money column in this export at all — not even behind `view_financial`. A CSV leaves the
        // system the moment it is written, and what a partner earns belongs on a statement the spine
        // produces, not on a contact list somebody mails around.
        $rows = $this->filtered($request, trim((string) $request->query('q', '')))->orderBy('name')->get();

        $headers = ['Code', 'Referral code', 'Name', 'Company', 'Type', 'Status', 'Email', 'Phone', 'Country', 'Joined'];

        $lines = $rows->map(static fn (Collaborator $row): array => [
            $row->collaborator_code,
            $row->referral_code,
            $row->name,
            $row->company_name,
            $row->collaboration_type->label(),
            $row->status->label(),
            $row->email,
            $row->phone,
            $row->country,
            $row->joining_date !== null ? app_date($row->joining_date) : null,
        ])->all();

        return (new CsvWriter)->download('collaborators-'.app_date(now(), 'Y-m-d').'.csv', $headers, $lines);
    }

    /**
     * The index / export query, so a filtered export matches the list it was taken from.
     */
    private function filtered(Request $request, string $term): Builder
    {
        return Collaborator::query()
            ->when($request->boolean('trashed'), static fn ($query) => $query->onlyTrashed())
            ->when($term !== '', static fn ($query) => $query->where(static fn ($inner) => $inner
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('company_name', 'like', '%'.$term.'%')
                ->orWhere('collaborator_code', 'like', '%'.$term.'%')
                ->orWhere('referral_code', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%')))
            ->when($request->filled('status'), static fn ($query) => $query
                ->where('status', $request->string('status')->toString()))
            ->when($request->filled('collaboration_type'), static fn ($query) => $query
                ->where('collaboration_type', $request->string('collaboration_type')->toString()))
            ->when($request->filled('skill'), static fn ($query) => $query
                ->whereHas('skills', static fn ($inner) => $inner->where('slug', $request->string('skill')->toString())));
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'types' => $this->typeOptions(),
            'services' => Schema::hasTable('services')
                ? Service::query()->orderBy('name')->get(['id', 'name'])
                : collect(),
            'codeIsEditable' => (bool) setting('collaborator.referral_code_editable', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Collaborator $collaborator = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'collaboration_type' => ['required', Rule::enum(CollaborationType::class)],
            'email' => [
                'nullable', 'email:rfc', 'max:150',
                Rule::unique('collaborators', 'email')->ignore($collaborator?->getKey())->withoutTrashed(),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // The form posts one comma-separated field because that is how somebody actually types a
            // skill list; the array form is accepted too, for an importer or an API client.
            'skills_text' => ['nullable', 'string', 'max:2000'],
            'skills' => ['nullable', 'array', 'max:50'],
            'skills.*' => ['string', 'max:80'],
            'service_ids' => ['nullable', 'array', 'max:50'],
            'service_ids.*' => ['integer', Rule::exists('services', 'id')],
            'reason' => ['nullable', 'string', 'max:255'],
        ];

        // A vanity code is a create-time choice only; afterwards it moves through its own route, which
        // carries its own mandatory reason and its own INV-C2 check.
        if ($collaborator === null) {
            $rules['referral_code'] = ['nullable', 'string', 'max:32'];
            $rules['activate'] = ['nullable', 'boolean'];
        }

        $input = $request->validate($rules);

        if (! isset($input['skills']) && isset($input['skills_text'])) {
            $input['skills'] = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) $input['skills_text']),
            ), static fn (string $skill): bool => $skill !== ''));
        }

        unset($input['skills_text']);

        // An unticked checkbox group posts nothing at all, and "nothing" has to mean "no services"
        // rather than "leave them alone" — otherwise a service can be added but never removed.
        $input['service_ids'] = $input['service_ids'] ?? [];
        $input['skills'] = $input['skills'] ?? [];

        return $input;
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        $options = [];

        foreach (CollaboratorStatus::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function typeOptions(): array
    {
        $options = [];

        foreach (CollaborationType::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
