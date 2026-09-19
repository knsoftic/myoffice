<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\DataObjects\Crm\ContactCandidate;
use App\DataObjects\Crm\DuplicateMatch;
use App\DataObjects\Crm\DuplicateReport;
use App\Enums\ClientStatus;
use App\Enums\LeadDuplicateMatchType;
use App\Enums\LeadStatus;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\Crm\Lead;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use App\Services\Crm\Concerns\InteractsWithCrm;
use App\Support\ContactNormalizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Duplicate detection for leads (phase-05 §6.2, [D-P5-5], D29, R-4, tests 21-25).
 *
 * **Warned, never blocked by a constraint.** The normalized phone / WhatsApp / email (only the fields in
 * `crm.duplicate_match_fields`) are compared against `leads` — trashed rows included and reported
 * `is_trashed` — and, when `crm.duplicate_check_clients` is on, against `clients` and `client_contacts`.
 * Cross-field matching (a phone that is another lead's WhatsApp) runs only with `crm.duplicate_cross_field`;
 * `crm.duplicate_lookback_days` (0 = all time) narrows every query. One query per enabled comparison, each an
 * equality on an indexed `*_normalized` column — never a `LIKE '%…%'` scan. At most ten matches, newest first.
 *
 * **Isolation-safe by construction.** Candidate rows are read without the §9 visibility scope, and then every
 * match the actor may not open (`LeadPolicy::view`, `ClientPolicy::view`) is reduced to a restricted match:
 * the match type, the record type and "owned by another user" — no id, no name, no contact, no link. A system
 * caller with no actor (an import, the inquiry router) acts on ids and renders nothing, so it sees full matches.
 */
final class LeadDuplicateDetector
{
    use InteractsWithCrm;

    public const MAX_MATCHES = 10;

    /** @var list<string> */
    private const LEAD_COLUMNS = ['id', 'lead_no', 'name', 'company', 'status', 'assigned_to', 'created_by', 'last_activity_at', 'created_at', 'deleted_at', 'duplicate_of_lead_id'];

    /** @var array<int, string|null> user id => name, memoised for one check */
    private array $userNames = [];

    /**
     * @param  User|null  $actor  the viewer; null resolves the signed-in user
     */
    public function check(ContactCandidate $c, ?int $ignoreLeadId = null, ?User $actor = null, bool $asSystem = false): DuplicateReport
    {
        if (! $this->crmBool('duplicate_detection_enabled', true)) {
            return DuplicateReport::none(false);
        }

        $actor ??= $asSystem ? null : $this->actor();

        $fields = $this->crmList('duplicate_match_fields', ['phone', 'whatsapp', 'email']);
        $cross = $this->crmBool('duplicate_cross_field', true);
        $checkClients = $this->crmBool('duplicate_check_clients', true);
        $since = $this->lookbackStart();

        $phone = in_array('phone', $fields, true) ? ContactNormalizer::phone($c->phone, $c->countryCode) : null;
        $whatsapp = in_array('whatsapp', $fields, true) ? ContactNormalizer::phone($c->whatsapp, $c->countryCode) : null;
        $email = in_array('email', $fields, true) ? ContactNormalizer::email($c->email) : null;

        /** @var array<string, array{0: LeadDuplicateMatchType, 1: Model, 2: string}> $hits keyed record type + id */
        $hits = [];

        // Exact comparisons first, so a row matching both ways is reported by its exact type.
        if ($phone !== null) {
            $this->collectLeads($hits, 'phone_normalized', $phone, $this->type('phone'), $ignoreLeadId, $since);
        }

        if ($whatsapp !== null) {
            $this->collectLeads($hits, 'whatsapp_normalized', $whatsapp, $this->type('whatsapp'), $ignoreLeadId, $since);
        }

        if ($email !== null) {
            $this->collectLeads($hits, 'email_normalized', $email, $this->type('email'), $ignoreLeadId, $since);
        }

        if ($cross) {
            if ($phone !== null) {
                $this->collectLeads($hits, 'whatsapp_normalized', $phone, $this->type('phone_vs_whatsapp'), $ignoreLeadId, $since);
            }

            if ($whatsapp !== null && $whatsapp !== $phone) {
                $this->collectLeads($hits, 'phone_normalized', $whatsapp, $this->type('phone_vs_whatsapp'), $ignoreLeadId, $since);
            }
        }

        if ($checkClients) {
            // A client carries one company number and one WhatsApp number; either key matches either column.
            foreach (array_values(array_unique(array_filter([$phone, $whatsapp]))) as $key) {
                $this->collectClients($hits, ['phone_normalized', 'whatsapp_normalized'], $key, $this->type('client_phone'), $since);
                $this->collectContacts($hits, 'phone_normalized', $key, $this->type('client_contact_phone'), $since);
            }

            if ($email !== null) {
                $this->collectClients($hits, ['email_normalized'], $email, $this->type('client_email'), $since);
                $this->collectContacts($hits, 'email_normalized', $email, $this->type('client_contact_email'), $since);
            }
        }

        $matches = [];

        foreach ($hits as [$type, $model, $recordType]) {
            $matches[] = $this->describe($type, $model, $recordType, $actor, $asSystem);
        }

        usort($matches, static fn (DuplicateMatch $a, DuplicateMatch $b): int => ($b->sortAt?->getTimestamp() ?? 0) <=> ($a->sortAt?->getTimestamp() ?? 0));

        return new DuplicateReport(array_slice($matches, 0, self::MAX_MATCHES), true);
    }

    /**
     * The same check for an existing lead: excludes the lead itself and anything already linked to it.
     */
    public function checkLead(Lead $lead, ?User $actor = null): DuplicateReport
    {
        $report = $this->check(
            new ContactCandidate(
                phone: $this->nullableString($lead->getAttribute('phone')),
                whatsapp: $this->nullableString($lead->getAttribute('whatsapp')),
                email: $this->nullableString($lead->getAttribute('email')),
                countryCode: $this->nullableString($lead->getAttribute('country_code')),
            ),
            (int) $lead->getKey(),
            $actor,
        );

        $leadId = (int) $lead->getKey();
        $originalId = $lead->getAttribute('duplicate_of_lead_id') === null ? null : (int) $lead->getAttribute('duplicate_of_lead_id');

        $linkedIds = Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->withTrashed()
            ->where('duplicate_of_lead_id', $leadId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($originalId !== null) {
            $linkedIds[] = $originalId;
        }

        if ($linkedIds === []) {
            return $report;
        }

        // Restricted matches carry no id, so they cannot be filtered by it; they are kept as the anonymous warning.
        $matches = array_values(array_filter(
            $report->matches,
            static fn (DuplicateMatch $match): bool => $match->recordType !== DuplicateMatch::RECORD_LEAD
                || $match->id === null
                || ! in_array($match->id, $linkedIds, true),
        ));

        return new DuplicateReport($matches, $report->enabled);
    }

    /**
     * @param  array<string, array{0: LeadDuplicateMatchType, 1: Model, 2: string}>  $hits
     */
    private function collectLeads(array &$hits, string $column, string $value, LeadDuplicateMatchType $type, ?int $ignoreLeadId, ?CarbonInterface $since): void
    {
        $query = Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->withTrashed()
            ->where($column, $value)
            ->when($ignoreLeadId !== null, static fn (Builder $q) => $q->whereKeyNot($ignoreLeadId))
            ->when($since !== null, static fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_MATCHES);

        foreach ($query->get(self::LEAD_COLUMNS) as $lead) {
            $hits[DuplicateMatch::RECORD_LEAD.':'.$lead->getKey()] ??= [$type, $lead, DuplicateMatch::RECORD_LEAD];
        }
    }

    /**
     * @param  array<string, array{0: LeadDuplicateMatchType, 1: Model, 2: string}>  $hits
     * @param  list<string>  $columns
     */
    private function collectClients(array &$hits, array $columns, string $value, LeadDuplicateMatchType $type, ?CarbonInterface $since): void
    {
        $query = Client::query()
            ->where(static function (Builder $q) use ($columns, $value): void {
                foreach ($columns as $index => $column) {
                    $index === 0 ? $q->where($column, $value) : $q->orWhere($column, $value);
                }
            })
            ->when($since !== null, static fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->orderByDesc('created_at')
            ->limit(self::MAX_MATCHES);

        foreach ($query->get(['id', 'client_code', 'name', 'company_name', 'status', 'account_manager_id', 'created_at']) as $client) {
            $hits[DuplicateMatch::RECORD_CLIENT.':'.$client->getKey()] ??= [$type, $client, DuplicateMatch::RECORD_CLIENT];
        }
    }

    /**
     * @param  array<string, array{0: LeadDuplicateMatchType, 1: Model, 2: string}>  $hits
     */
    private function collectContacts(array &$hits, string $column, string $value, LeadDuplicateMatchType $type, ?CarbonInterface $since): void
    {
        $query = ClientContact::query()
            ->where($column, $value)
            ->when($since !== null, static fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->orderByDesc('created_at')
            ->limit(self::MAX_MATCHES);

        foreach ($query->get(['id', 'client_id', 'name', 'designation', 'created_at']) as $contact) {
            $hits[DuplicateMatch::RECORD_CLIENT_CONTACT.':'.$contact->getKey()] ??= [$type, $contact, DuplicateMatch::RECORD_CLIENT_CONTACT];
        }
    }

    private function describe(LeadDuplicateMatchType $type, Model $model, string $recordType, ?User $actor, bool $asSystem): DuplicateMatch
    {
        $sortAt = $this->carbon($model->getAttribute('created_at'));

        if ($recordType === DuplicateMatch::RECORD_LEAD) {
            if (! $asSystem && ! $this->canView($actor, $model)) {
                return DuplicateMatch::restricted($type, $recordType, $sortAt);
            }

            $status = $model->getAttribute('status');
            $status = $status instanceof LeadStatus ? $status : LeadStatus::tryFrom((string) $status);
            $ownerId = $model->getAttribute('assigned_to');

            return new DuplicateMatch(
                matchType: $type,
                recordType: $recordType,
                restricted: false,
                id: (int) $model->getKey(),
                reference: $this->nullableString($model->getAttribute('lead_no')),
                name: $this->nullableString($model->getAttribute('name')),
                company: $this->nullableString($model->getAttribute('company')),
                status: $status?->value,
                statusLabel: $status?->label(),
                ownerName: $ownerId === null ? null : $this->userName((int) $ownerId),
                lastActivityAt: $this->carbon($model->getAttribute('last_activity_at')),
                isTrashed: $model->getAttribute('deleted_at') !== null,
                url: $this->url('admin.leads.show', ['lead' => $model->getKey()]),
                sortAt: $sortAt,
            );
        }

        $client = $recordType === DuplicateMatch::RECORD_CLIENT
            ? $model
            : Client::query()->whereKey($model->getAttribute('client_id'))->first(['id', 'client_code', 'name', 'company_name', 'status', 'account_manager_id']);

        if (! $client instanceof Client || (! $asSystem && ! $this->canView($actor, $client))) {
            return DuplicateMatch::restricted($type, $recordType, $sortAt);
        }

        $status = $client->getAttribute('status');
        $status = $status instanceof ClientStatus ? $status : ClientStatus::tryFrom((string) $status);
        $managerId = $client->getAttribute('account_manager_id');

        return new DuplicateMatch(
            matchType: $type,
            recordType: $recordType,
            restricted: false,
            id: (int) $client->getKey(),
            reference: $this->nullableString($client->getAttribute('client_code')),
            name: $recordType === DuplicateMatch::RECORD_CLIENT
                ? $this->nullableString($client->getAttribute('name'))
                : $this->nullableString($model->getAttribute('name')),
            company: $this->nullableString($client->getAttribute('company_name')) ?? $this->nullableString($client->getAttribute('name')),
            status: $status?->value,
            statusLabel: $status?->label(),
            ownerName: $managerId === null ? null : $this->userName((int) $managerId),
            url: $this->url('admin.clients.show', ['client' => $client->getKey()]),
            sortAt: $sortAt,
        );
    }

    private function canView(?User $actor, Model $model): bool
    {
        if (! $actor instanceof User) {
            return false;
        }

        try {
            return Gate::forUser($actor)->allows('view', $model);
        } catch (Throwable) {
            return false;
        }
    }

    private function type(string $value): LeadDuplicateMatchType
    {
        return LeadDuplicateMatchType::from($value);
    }

    private function lookbackStart(): ?CarbonImmutable
    {
        $days = $this->crmInt('duplicate_lookback_days', 0, 0, 36500);

        return $days === 0 ? null : CarbonImmutable::now()->subDays($days);
    }

    private function userName(int $id): ?string
    {
        if (! array_key_exists($id, $this->userNames)) {
            $name = User::query()->whereKey($id)->value('name');
            $this->userNames[$id] = is_string($name) ? $name : null;
        }

        return $this->userNames[$id];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $name, array $parameters): ?string
    {
        try {
            return Route::has($name) ? route($name, $parameters) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function carbon(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
