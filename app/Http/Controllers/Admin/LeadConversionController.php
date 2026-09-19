<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\Projects\ProjectCreator;
use App\Contracts\Referrals\ReferralRecorder;
use App\DataObjects\Crm\ConversionPreview;
use App\DataObjects\Crm\DuplicateMatch;
use App\Enums\ClientType;
use App\Enums\InquirySource;
use App\Enums\LeadDuplicateMatchType;
use App\Http\Controllers\Admin\Crm\Concerns\RespondsForCrm;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ConvertLeadRequest;
use App\Http\Requests\Crm\SupersedeConversionRequest;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadConversion;
use App\Services\Crm\LeadConversionService;
use BackedEnum;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Converting a won lead into a client, and optionally handing it to a project — `admin.leads.convert.*`,
 * `admin.leads.conversions.supersede` (phase-05 §2.4, §6.4, §8.3 Conversion tab, tests 43-49, 53), `module:leads`.
 *
 * **Authorization.** `convert` is `leads.edit` **and** `clients.create` (`LeadPolicy::convert`); "mark as won and
 * convert" additionally needs `leads.change_status`; the project hand-off additionally needs `projects.create` — each
 * missing grant is a 403 with nothing written (test 47). While `ProjectCreator::isAvailable()` is false the hand-off
 * control is not offered and a posted `create_project` answers **404** (D28).
 *
 * **Never silent.** `preview()` writes nothing; a client is never auto-matched — the duplicate report is shown and a
 * person picks one (§6.4 step 3). A double-submitted wizard returns the existing conversion (`created: false`) rather
 * than a second client. A conversion is never deleted: it is superseded with a reason.
 */
final class LeadConversionController extends Controller
{
    use RespondsForCrm;

    public function __construct(
        private readonly LeadConversionService $conversions,
    ) {}

    /**
     * The wizard: the proposed client field map, the duplicate report against existing clients, the attribution that
     * will be carried forward, and whether the project hand-off is available.
     */
    public function create(Request $request, Lead $lead): View
    {
        $this->authorize('convert', $lead);

        $actor = $this->actor($request);
        $preview = $this->conversions->preview($lead);
        $handOff = $preview->projectHandOffAvailable && app(ProjectCreator::class)->isAvailable();

        $lead->loadMissing('assignee:id,name');

        return view('admin.leads.convert', [
            'lead' => $lead,
            'preview' => $this->previewForView($preview, $handOff),
            'conversionPreview' => $preview,
            'liveConversion' => $lead->activeConversion()->with(['client', 'convertedBy:id,name'])->first(),
            'requiresPromotion' => $preview->requiresPromotion(),
            'canPromote' => $preview->requiresPromotion() && $actor->can('leads.change_status'),
            'accountManagerOptions' => $actor->can('clients.assign') ? $this->usersHolding('clients.view') : [],
            'projectHandOffAvailable' => $handOff,
            'canCreateProject' => $handOff && $actor->can('projects.create'),
            'clientTypeOptions' => ClientType::options(),
            'sourceOptions' => InquirySource::options(),
            'matchTypeOptions' => LeadDuplicateMatchType::options(),
            'serviceOptions' => $this->serviceOptions(),
        ]);
    }

    /**
     * The preview in the wizard's shape: the proposed client fields with labels, the client matches (restricted ones
     * carry no identifying data), the attribution and whether the hand-off is offered. Presentation only.
     *
     * @return array{field_map: list<array{client_field: string, label: string, value: ?string}>, client_matches: list<array<string, mixed>>, attribution: array{referral_code: ?string, collaborator_name: ?string, recorder_available: bool}, project_available: bool, is_won: bool, already_converted: bool}
     */
    private function previewForView(ConversionPreview $preview, bool $handOff): array
    {
        $labels = [
            'name' => 'Contact name',
            'company_name' => 'Company',
            'email' => 'Email',
            'phone' => 'Phone',
            'whatsapp' => 'WhatsApp',
            'country' => 'Country',
            'country_code' => 'Country code',
            'source' => 'Source',
            'notes' => 'Notes',
        ];

        $fields = [];

        foreach ($preview->fieldMap as $clientField) {
            $value = $preview->clientFields[$clientField] ?? null;
            $value = $value instanceof BackedEnum ? $value->value : $value;

            $fields[] = [
                'client_field' => $clientField,
                'label' => $labels[$clientField] ?? Str::headline($clientField),
                'value' => is_scalar($value) ? (string) $value : null,
            ];
        }

        $matches = array_map(static function (DuplicateMatch $match): array {
            $row = [
                'restricted' => $match->restricted,
                'record_type' => $match->recordType,
                'match_type' => $match->matchType->value,
                'match_type_label' => $match->matchType->label(),
            ];

            if ($match->restricted) {
                return $row;
            }

            return $row + [
                'client_id' => $match->id,
                'client_code' => $match->reference,
                'name' => $match->name,
                'company_name' => $match->company,
                'status_label' => $match->statusLabel,
                'url' => $match->url,
            ];
        }, $preview->duplicates->clientMatches());

        return [
            'field_map' => $fields,
            'client_matches' => $matches,
            'attribution' => [
                'referral_code' => $preview->attribution['code'] ?? null,
                'collaborator_name' => $preview->attribution['collaborator_name'] ?? null,
                'recorder_available' => app(ReferralRecorder::class)->isAvailable(),
            ],
            'project_available' => $handOff,
            'is_won' => $preview->isWon,
            'already_converted' => $preview->alreadyConverted,
        ];
    }

    public function store(ConvertLeadRequest $request, Lead $lead): Response
    {
        $this->authorize('convert', $lead);

        if ($request->boolean('promote_to_won')) {
            $this->authorize('leads.change_status');
        }

        if ($request->wantsProject()) {
            abort_if($request->projectHandOffUnavailable(), Response::HTTP_NOT_FOUND);
            $this->authorize('projects.create');
        }

        return $this->attempt($request, function () use ($request, $lead): Response {
            $result = $this->conversions->convert($lead, $request->toData());
            $client = $result->client;

            $message = match (true) {
                ! $result->created => 'This lead was already converted; nothing new was created.',
                $result->createdClient && $client !== null => sprintf('Lead %s was converted into client %s.', $lead->lead_no, $client->client_code),
                $client !== null => sprintf('Lead %s was linked to client %s.', $lead->lead_no, $client->client_code),
                default => sprintf('Lead %s was converted.', $lead->lead_no),
            };

            $redirect = $client !== null && $request->user()?->can('view', $client) === true
                ? redirect()->route('admin.clients.show', $client)
                : redirect()->route('admin.leads.show', ['lead' => $lead, 'tab' => 'conversion']);

            return $this->done($request, $message, $redirect, [
                'created' => $result->created,
                'created_client' => $result->createdClient,
                'conversion_id' => (int) $result->conversion->getKey(),
                'client_id' => $client?->getKey(),
                'project_id' => $result->projectId,
            ], $result->created ? 'success' : 'info');
        });
    }

    /**
     * Supersede a conversion (reason mandatory) so a corrected one can be recorded. The row stays; the client stays
     * linked. The conversion's lead must be visible to the actor — otherwise the id is a 404.
     */
    public function supersede(SupersedeConversionRequest $request, LeadConversion $conversion): Response
    {
        $lead = $conversion->resolveLead();

        $this->abortUnlessVisible($lead instanceof Lead && $this->actor($request)->can('view', $lead));
        $this->authorize('supersede', $conversion);

        return $this->attempt($request, function () use ($request, $conversion, $lead): Response {
            $this->conversions->supersede($conversion, $request->reasonText());

            return $this->done(
                $request,
                'The conversion was superseded. A corrected conversion can now be recorded.',
                $lead instanceof Lead ? redirect()->route('admin.leads.show', ['lead' => $lead, 'tab' => 'conversion']) : null,
                ['conversion_id' => (int) $conversion->getKey()],
            );
        });
    }
}
