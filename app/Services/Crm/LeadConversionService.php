<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Contracts\Projects\ProjectCreator;
use App\Contracts\Referrals\ReferralRecorder;
use App\DataObjects\Crm\ClientData;
use App\DataObjects\Crm\ContactCandidate;
use App\DataObjects\Crm\ConversionPreview;
use App\DataObjects\Crm\ConversionResult;
use App\DataObjects\Crm\ConvertLeadData;
use App\DataObjects\Crm\DuplicateReport;
use App\DataObjects\Crm\ProjectDraftData;
use App\DataObjects\Crm\RecordedReferral;
use App\DataObjects\Crm\StatusChangeData;
use App\Enums\ClientType;
use App\Enums\InquirySource;
use App\Enums\LeadActivityType;
use App\Enums\LeadConversionType;
use App\Enums\LeadStatus;
use App\Events\Crm\LeadConversionSuperseded;
use App\Events\Crm\LeadConverted;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadConversion;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Crm\Concerns\InteractsWithCrm;
use App\Services\Crm\Exceptions\CrmRuleException;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lead → client (→ project) conversion with an immutable audit trail (phase-05 §2.4, §6.4, tests 43-49, 53).
 *
 *   1. The lead must be `won` — or, with `promoteToWon` and `leads.change_status`, `LeadService::changeStatus()`
 *      moves it to `won` first **inside the same transaction** (both or neither, test 44).
 *   2. `uq_lc_lead_active` makes a double submit impossible: the lead row is locked, a live conversion found under
 *      the lock is returned with `created: false`, and a 1062 from a race is answered the same way (test 45).
 *   3. A new client via `ClientService::create()` (generated `client_code`), or a link to an **explicitly chosen**
 *      client — never an automatic match (test 48).
 *   4-6. The field map is copied, `clients.source` / `clients.lead_id` set, the conversion row written with its
 *      `lead_snapshot`, and `leads.client_id` / `converted_at` / `converted_by` stamped. **The lead is never
 *      deleted** and the snapshot never changes afterwards (test 46).
 *   7. Attribution travels through `ReferralRecorder` only: the lead's live referral is attached to the client
 *      with the **lead's** referral date and recorded as `collaborator_referral_id` + `referral_code`; without a
 *      recorder the captured code is copied to the client for `crm:record-captured-referrals` to attach later.
 *   8. With `createProject`, a bound `ProjectCreator` and `projects.create`, the project is created with
 *      `collaborator_id` + `referral_code` on its draft — the point at which attribution reaches money (§45).
 *   9. One `converted` timeline row; `LeadConverted` after commit.
 *
 * No commission row is written by anything here (INV-1, test 54). A wrong conversion is superseded, never deleted
 * (the model's `deleting` hook throws).
 */
final class LeadConversionService
{
    use InteractsWithCrm;
    use WritesAuditTrail;

    private const MODULE = 'leads';

    /** lead column => client column, the proposed map shown by the wizard and stored on the conversion. */
    private const FIELD_MAP = [
        'name' => 'name',
        'company' => 'company_name',
        'email' => 'email',
        'phone' => 'phone',
        'whatsapp' => 'whatsapp',
        'country' => 'country',
        'country_code' => 'country_code',
        'source' => 'source',
        'notes' => 'notes',
    ];

    /** Every §18 field the snapshot freezes. */
    private const SNAPSHOT_FIELDS = [
        'lead_no', 'name', 'company', 'email', 'phone', 'whatsapp', 'country', 'country_code', 'service_id',
        'interested_service', 'budget_amount', 'source', 'source_detail', 'status', 'assigned_to', 'follow_up_at',
        'notes', 'lost_reason', 'won_at', 'referral_code_captured', 'referral_recorded_at', 'contact_inquiry_id',
        'created_at',
    ];

    public function __construct(
        private readonly LeadService $leads,
        private readonly ClientService $clients,
        private readonly LeadDuplicateDetector $duplicates,
        private readonly LeadTimeline $timeline,
        private readonly ReferralRecorder $referrals,
        private readonly ProjectCreator $projects,
    ) {}

    public function preview(Lead $lead): ConversionPreview
    {
        $status = $this->statusOf($lead);
        $clientFields = $this->proposedClientFields($lead);

        $report = $this->duplicates->check(new ContactCandidate(
            phone: $this->nullable($lead->getAttribute('phone')),
            whatsapp: $this->nullable($lead->getAttribute('whatsapp')),
            email: $this->nullable($lead->getAttribute('email')),
            countryCode: $this->nullable($lead->getAttribute('country_code')),
        ), (int) $lead->getKey());

        $clientsOnly = new DuplicateReport($report->clientMatches(), $report->enabled);

        return new ConversionPreview(
            lead: $lead,
            isWon: $status->canConvert(),
            alreadyConverted: $this->liveConversion($lead) instanceof LeadConversion,
            clientFields: $clientFields,
            fieldMap: self::FIELD_MAP,
            duplicates: $clientsOnly,
            attribution: $this->attributionFor($lead),
            projectHandOffAvailable: $this->projectHandOffAvailable(),
        );
    }

    public function convert(Lead $lead, ConvertLeadData $data): ConversionResult
    {
        $actor = $this->actor();

        if ($data->createProject) {
            $this->assertProjectHandOffAllowed($actor, $data);
        }

        try {
            return DB::transaction(fn (): ConversionResult => $this->convertLocked($lead, $data, $actor));
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'uq_lc_lead_active')) {
                throw $exception;
            }

            // A concurrent submit won the race: everything this attempt wrote was rolled back with it.
            $existing = $this->liveConversion($lead);

            if (! $existing instanceof LeadConversion) {
                throw $exception;
            }

            return new ConversionResult(
                conversion: $existing,
                client: $this->clientOf($existing),
                created: false,
                projectId: $existing->getAttribute('project_id') === null ? null : (int) $existing->getAttribute('project_id'),
            );
        }
    }

    public function supersede(LeadConversion $conversion, string $reason): void
    {
        $reason = $this->cleanText($reason, 255);

        if ($reason === null) {
            throw CrmRuleException::reasonRequired('supersede_reason', 'Say why this conversion is being superseded.');
        }

        $actorId = $this->actorId();

        DB::transaction(function () use ($conversion, $reason, $actorId): void {
            /** @var LeadConversion $locked */
            $locked = LeadConversion::query()->whereKey($conversion->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->getAttribute('superseded_at') !== null) {
                throw CrmRuleException::refuse('supersede_reason', 'This conversion has already been superseded.');
            }

            $now = CarbonImmutable::now();

            $locked->forceFill([
                'superseded_at' => $now,
                'supersede_reason' => $reason,
            ]);

            $this->withoutModelLogging(static fn (): bool => $locked->save());

            $this->audit($locked, 'Lead conversion superseded', [
                'old' => ['superseded_at' => null, 'supersede_reason' => null],
                'attributes' => ['superseded_at' => $now->format('Y-m-d H:i:s'), 'supersede_reason' => $reason],
            ], self::MODULE, $reason);

            $lead = Lead::query()
                ->withoutGlobalScope(LeadVisibilityScope::class)
                ->withTrashed()
                ->whereKey($locked->getAttribute('lead_id'))
                ->lockForUpdate()
                ->first();

            if ($lead instanceof Lead) {
                $this->timeline->record($lead, LeadActivityType::from('system'), [
                    'subject' => 'Conversion superseded',
                    'body' => $reason,
                    'meta' => ['lead_conversion_id' => (int) $locked->getKey()],
                    'occurred_at' => $now,
                ]);
            }

            event(new LeadConversionSuperseded($locked, $reason, $actorId));

            $conversion->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function projectHandOffAvailable(): bool
    {
        try {
            return $this->projects->isAvailable();
        } catch (Throwable) {
            return false;
        }
    }

    private function convertLocked(Lead $lead, ConvertLeadData $data, ?User $actor): ConversionResult
    {
        /** @var Lead $locked */
        $locked = Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->whereKey($lead->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $existing = $this->liveConversion($locked);

        if ($existing instanceof LeadConversion) {
            return new ConversionResult(
                conversion: $existing,
                client: $this->clientOf($existing),
                created: false,
                projectId: $existing->getAttribute('project_id') === null ? null : (int) $existing->getAttribute('project_id'),
            );
        }

        $fromStatus = $this->statusOf($locked);

        if (! $fromStatus->canConvert()) {
            $mayPromote = $data->promoteToWon && (! $actor instanceof User || $actor->can('leads.change_status'));

            if (! $mayPromote) {
                throw CrmRuleException::notWon();
            }

            $this->leads->changeStatus($locked, LeadStatus::from('won'), new StatusChangeData(
                expectedFrom: $fromStatus,
                reason: $data->promotionReason ?? 'Marked as won to convert',
            ));

            $locked->refresh();
        }

        // The snapshot is taken after any promotion, so it is the lead exactly as it stood when it converted.
        $snapshot = $this->snapshotOf($locked);
        $now = CarbonImmutable::now();

        [$client, $createdClient] = $this->resolveClient($locked, $data);

        $leadReferral = $this->activeReferral($locked);
        $referralCode = $leadReferral?->referralCode ?? $this->nullable($locked->getAttribute('referral_code_captured'));

        $this->carryAttribution($locked, $client, $leadReferral, $createdClient);

        $conversion = new LeadConversion;
        $conversion->forceFill([
            'lead_id' => (int) $locked->getKey(),
            'conversion_type' => LeadConversionType::from($data->createProject && $this->projectHandOffAvailable() ? 'client_and_project' : 'client'),
            'client_id' => (int) $client->getKey(),
            'created_client' => $createdClient,
            'matched_by' => $createdClient ? null : $data->matchedBy,
            'from_status' => $fromStatus,
            'lead_snapshot' => $snapshot,
            'field_map' => self::FIELD_MAP,
            'budget_amount' => $this->budgetOf($locked),
            'referral_code' => $referralCode === null ? null : mb_substr($referralCode, 0, 32),
            'collaborator_referral_id' => $leadReferral?->id,
            'converted_at' => $now,
            'converted_by' => $actor?->getKey(),
            'notes' => $this->cleanText($data->notes),
        ]);

        $this->withoutModelLogging(static fn (): bool => $conversion->save());

        $before = $this->snapshot($locked, ['client_id', 'converted_at', 'converted_by']);

        $locked->forceFill([
            'client_id' => (int) $client->getKey(),
            'converted_at' => $now,
            'converted_by' => $actor?->getKey(),
        ]);

        $this->withoutModelLogging(static fn (): bool => $locked->save());

        $this->audit($locked, 'Lead converted', [
            ...$this->changes($before, $this->snapshot($locked, ['client_id', 'converted_at', 'converted_by'])),
            'lead_conversion_id' => (int) $conversion->getKey(),
            'created_client' => $createdClient,
        ], self::MODULE, $this->cleanText($data->notes));

        $projectId = null;

        if ($data->createProject && $data->project instanceof ProjectDraftData && $this->projectHandOffAvailable()) {
            $draft = $data->project->resolved(
                clientId: (int) $client->getKey(),
                leadId: (int) $locked->getKey(),
                collaboratorId: $leadReferral?->collaboratorId,
                referralCode: $referralCode,
            );

            $projectId = $this->projects->createFromLead($conversion, $draft);

            $conversion->forceFill(['project_id' => $projectId]);
            $this->withoutModelLogging(static fn (): bool => $conversion->save());
        }

        $this->timeline->record($locked, LeadActivityType::from('converted'), [
            'subject' => sprintf('Converted to client %s', (string) $client->getAttribute('client_code')),
            'body' => $this->cleanText($data->notes),
            'meta' => array_filter([
                'lead_conversion_id' => (int) $conversion->getKey(),
                'client_id' => (int) $client->getKey(),
                'created_client' => $createdClient,
                'project_id' => $projectId,
                'referral_code' => $referralCode,
            ], static fn (mixed $value): bool => $value !== null),
            'occurred_at' => $now,
        ]);

        event(new LeadConverted($conversion, $createdClient, $actor?->getKey() === null ? null : (int) $actor->getKey()));

        $lead->setRawAttributes($locked->getAttributes(), true);

        return new ConversionResult(
            conversion: $conversion,
            client: $client,
            created: true,
            createdClient: $createdClient,
            projectId: $projectId,
        );
    }

    /**
     * @return array{0: Client, 1: bool}
     */
    private function resolveClient(Lead $lead, ConvertLeadData $data): array
    {
        if ($data->existingClientId !== null) {
            /** @var Client|null $client */
            $client = Client::query()->whereKey($data->existingClientId)->lockForUpdate()->first();

            if (! $client instanceof Client) {
                throw CrmRuleException::clientNotFound();
            }

            // A linked client keeps its own code and record; only an empty origin link is filled.
            if ($client->getAttribute('lead_id') === null) {
                Client::query()->whereKey($client->getKey())->toBase()->update(['lead_id' => (int) $lead->getKey()]);
                $client->setAttribute('lead_id', (int) $lead->getKey());
                $client->syncOriginalAttribute('lead_id');
            }

            return [$client, false];
        }

        $proposed = $this->proposedClientData($lead);
        $clientData = $data->client instanceof ClientData ? $data->client : $proposed;

        $clientData = $clientData->with([
            'leadId' => (int) $lead->getKey(),
            'source' => $clientData->source ?? $proposed->source,
            'referralCode' => $this->nullable($lead->getAttribute('referral_code_captured')),
            'provided' => null,
        ]);

        return [$this->clients->create($clientData), true];
    }

    /**
     * Attribution through the recorder when one is bound; otherwise the captured code is copied for the backfill.
     */
    private function carryAttribution(Lead $lead, Client $client, ?RecordedReferral $leadReferral, bool $createdClient): void
    {
        if ($leadReferral instanceof RecordedReferral && $this->recorderAvailable()) {
            $recorded = $this->referrals->attach(
                $client,
                $leadReferral->referralCode,
                ReferralRecorder::SOURCE_MANUAL_SELECTION,
                $leadReferral->referralDate,
                null,
                sprintf('Carried forward from lead %s by conversion', (string) $lead->getAttribute('lead_no')),
            );

            if ($recorded instanceof RecordedReferral) {
                Client::query()->whereKey($client->getKey())->toBase()->update([
                    'referral_code_captured' => mb_substr($leadReferral->referralCode, 0, 32),
                    'referral_recorded_at' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
                ]);
                $client->setAttribute('referral_code_captured', mb_substr($leadReferral->referralCode, 0, 32));
                $client->setAttribute('referral_recorded_at', CarbonImmutable::now());
            }

            return;
        }

        $captured = $this->nullable($lead->getAttribute('referral_code_captured'));

        // An existing client's own captured code is never overwritten by a later lead.
        if ($captured !== null && ($createdClient || $client->getAttribute('referral_code_captured') === null)) {
            Client::query()->whereKey($client->getKey())->toBase()->update(['referral_code_captured' => mb_substr($captured, 0, 32)]);
            $client->setAttribute('referral_code_captured', mb_substr($captured, 0, 32));
        }
    }

    private function assertProjectHandOffAllowed(?User $actor, ConvertLeadData $data): void
    {
        if (! $this->projectHandOffAvailable()) {
            throw CrmRuleException::refuse('create_project', 'Projects are not available yet, so a project cannot be created from this lead.');
        }

        if ($actor instanceof User && ! $actor->can('projects.create')) {
            throw CrmRuleException::refuse('create_project', 'You do not have permission to create projects.');
        }

        if (! $data->project instanceof ProjectDraftData || trim($data->project->name) === '') {
            throw CrmRuleException::refuse('project.project_name', 'Name the project to create.');
        }
    }

    /**
     * @return array{code: string|null, recorded: bool, recorded_at: string|null, collaborator_id: int|null, collaborator_name: string|null}|null
     */
    private function attributionFor(Lead $lead): ?array
    {
        $code = $this->nullable($lead->getAttribute('referral_code_captured'));
        $recordedAt = $lead->getAttribute('referral_recorded_at');
        $referral = $this->activeReferral($lead);

        if ($code === null && ! $referral instanceof RecordedReferral) {
            return null;
        }

        return [
            'code' => $referral?->referralCode ?? $code,
            'recorded' => $recordedAt !== null || $referral instanceof RecordedReferral,
            'recorded_at' => $recordedAt instanceof CarbonInterface ? $recordedAt->toIso8601String() : null,
            'collaborator_id' => $referral?->collaboratorId,
            'collaborator_name' => $referral?->collaboratorName,
        ];
    }

    private function activeReferral(Lead $lead): ?RecordedReferral
    {
        if (! $this->recorderAvailable()) {
            return null;
        }

        try {
            return $this->referrals->activeReferralFor($lead);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function recorderAvailable(): bool
    {
        try {
            return $this->referrals->isAvailable();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function proposedClientFields(Lead $lead): array
    {
        $fields = [];

        foreach (self::FIELD_MAP as $leadColumn => $clientColumn) {
            $fields[$clientColumn] = $this->plain($lead->getAttribute($leadColumn));
        }

        $fields['client_type'] = $this->nullable($lead->getAttribute('company')) === null ? 'individual' : 'company';

        return $fields;
    }

    private function proposedClientData(Lead $lead): ClientData
    {
        $source = $lead->getAttribute('source');
        $company = $this->nullable($lead->getAttribute('company'));

        return new ClientData(
            name: (string) $lead->getAttribute('name'),
            clientType: ClientType::from($company === null ? 'individual' : 'company'),
            companyName: $company,
            email: $this->nullable($lead->getAttribute('email')),
            phone: $this->nullable($lead->getAttribute('phone')),
            whatsapp: $this->nullable($lead->getAttribute('whatsapp')),
            country: $this->nullable($lead->getAttribute('country')),
            countryCode: $this->nullable($lead->getAttribute('country_code')),
            source: $source instanceof InquirySource ? $source : InquirySource::tryFrom((string) $source),
            notes: $this->nullable($lead->getAttribute('notes')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOf(Lead $lead): array
    {
        $out = [];

        foreach (self::SNAPSHOT_FIELDS as $field) {
            $value = $lead->getAttribute($field);
            $out[$field] = $value instanceof CarbonInterface ? $value->toIso8601String() : $this->plain($value);
        }

        return $out;
    }

    private function budgetOf(Lead $lead): ?string
    {
        $budget = $lead->getAttribute('budget_amount');

        return $budget === null || $budget === '' ? null : Money::of((string) $budget);
    }

    private function liveConversion(Lead $lead): ?LeadConversion
    {
        return LeadConversion::query()
            ->where('lead_id', $lead->getKey())
            ->whereNull('superseded_at')
            ->first();
    }

    private function clientOf(LeadConversion $conversion): ?Client
    {
        $id = $conversion->getAttribute('client_id');

        return $id === null ? null : Client::query()->withTrashed()->find((int) $id);
    }

    private function statusOf(Lead $lead): LeadStatus
    {
        $status = $lead->getAttribute('status');

        return $status instanceof LeadStatus ? $status : LeadStatus::from((string) $status);
    }

    private function nullable(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
