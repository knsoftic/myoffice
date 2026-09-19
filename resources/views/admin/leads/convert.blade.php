@extends('layouts.admin')

@section('title', 'Convert '.$lead->name)

{{--
    Convert a lead — admin.leads.convert.form → POST admin.leads.convert.store (phase-05 §6.4, §8.3 "Convert").
    LeadPolicy::convert has passed (leads.edit AND clients.create). LeadConversionService::preview() wrote nothing.

    Controller variables (Admin\LeadConversionController@create):
      $lead                    App\Models\Crm\Lead with assignee
      $preview                 ConversionPreview, read through data_get():
                                 field_map        list<{client_field: string, label: string, value: ?string}> — the proposed
                                                  client fields, prefilled from the lead
                                 client_matches   list<{restricted: bool, client_id: ?int, name, company_name, client_code,
                                                  match_type (LeadDuplicateMatchType value), match_type_label, url: ?string}>
                                 attribution      {referral_code: ?string, collaborator_name: ?string, recorder_available: bool}
                                 project_available bool   ProjectCreator::isAvailable()
      $canPromote              bool    the lead is not won and the actor holds leads.change_status
      $canCreateProject        bool    project_available AND projects.create — when false the hand-off is not rendered
      $clientTypeOptions       array<string, string>  ClientType::options()
      $accountManagerOptions   array<int, string>     staff who may manage a client (empty without clients.assign)
      $liveConversion          ?LeadConversion  when set the lead is already converted and the form is not offered

    Posts (ConvertLeadRequest): promote_to_won (1, required when the lead is not won), client_mode (create | existing),
    promotion_reason (optional), existing_client_id + matched_by (existing), client[client_type|name|company_name|email|
    phone|whatsapp|country|country_code|account_manager_id] (create), create_project (1) + project[project_name|
    project_description] (only when offered; project_name is required with create_project), notes.
    A double submit is harmless: uq_lc_lead_active returns the existing conversion.
--}}

@php
    $user = auth()->user();
    $statusValue = $lead->status instanceof \BackedEnum ? $lead->status->value : (string) $lead->status;
    $isWon = $statusValue === 'won';
    $fieldMap = collect(data_get($preview ?? null, 'field_map', []));
    $mapValue = static fn (string $field) => data_get($fieldMap->first(static fn ($row): bool => data_get($row, 'client_field') === $field), 'value');
    $clientMatches = collect(data_get($preview ?? null, 'client_matches', []));
    $selectableMatches = $clientMatches->filter(static fn ($match): bool => ! data_get($match, 'restricted') && filled(data_get($match, 'client_id')));
    $attribution = (array) data_get($preview ?? null, 'attribution', []);
    $clientTypeOptions = (array) ($clientTypeOptions ?? (enum_exists(\App\Enums\ClientType::class) ? \App\Enums\ClientType::options() : []));
    $accountManagerOptions = collect($accountManagerOptions ?? [])->all();
    $canPromote = (bool) ($canPromote ?? false);
    $canCreateProject = (bool) ($canCreateProject ?? false);
    $liveConversion = $liveConversion ?? null;
    $blocked = ! $isWon && ! $canPromote;
    $initialMode = old('client_mode', 'create');
@endphp

@section('header')
    <x-ui.page-header :title="'Convert '.$lead->name" subtitle="Create the client record — and hand off to a project — without losing the lead or its history." icon="check-badge" :back="route('admin.leads.show', $lead)">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $lead->lead_no }}</span>
            @include('admin.crm.partials.enum-badge', ['value' => $lead->status])
            @if ($lead->budget_amount !== null)
                <span class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{{ money((string) $lead->budget_amount) }}</span>
            @endif
        </div>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($liveConversion)
        <x-ui.card :padded="false">
            <x-ui.empty-state icon="check-badge" title="This lead is already converted" message="A lead has one live conversion. To record a corrected one, supersede the current conversion first.">
                <x-slot:action>
                    <x-ui.button variant="secondary" :href="route('admin.leads.show', ['lead' => $lead, 'tab' => 'conversion'])">See the conversion</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @elseif ($blocked)
        <x-ui.card :padded="false">
            <x-ui.empty-state icon="lock-closed" title="Only a won lead can be converted" message="Move the lead to Won first, or ask someone who may change lead statuses to convert it.">
                <x-slot:action>
                    <x-ui.button variant="secondary" :href="route('admin.leads.show', $lead)">Back to the lead</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <form
            method="POST"
            action="{{ route('admin.leads.convert.store', $lead) }}"
            class="space-y-6"
            x-data="{ mode: {{ \Illuminate\Support\Js::from($initialMode) }}, promote: {{ \Illuminate\Support\Js::from((bool) old('promote_to_won', $isWon)) }}, project: {{ \Illuminate\Support\Js::from((bool) old('create_project')) }}, submitting: false }"
            x-on:submit="submitting = true"
        >
            @csrf

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div class="space-y-6 xl:col-span-2">
                    @unless ($isWon)
                        <div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/25">
                            <label class="flex items-start gap-3">
                                <input type="checkbox" name="promote_to_won" value="1" x-model="promote" required class="mt-0.5 h-4 w-4 rounded border-amber-400 text-amber-600 focus:ring-amber-500/30 dark:border-amber-500/50 dark:bg-slate-900">
                                <span>
                                    <span class="font-semibold">Mark this lead as won and convert it in one step.</span>
                                    It is {{ strtolower($lead->status instanceof \BackedEnum && method_exists($lead->status, 'label') ? $lead->status->label() : $statusValue) }} today; the status change and the conversion are saved together or not at all.
                                </span>
                            </label>
                            <x-ui.form.error for="promote_to_won" />
                            <div class="mt-3" x-show="promote" x-cloak>
                                <label for="promotion-reason" class="block text-xs font-medium">Note on the status change <span class="font-normal opacity-80">(optional)</span></label>
                                <input id="promotion-reason" type="text" name="promotion_reason" value="{{ old('promotion_reason') }}" maxlength="500" x-bind:disabled="! promote" class="mt-1.5 block w-full rounded-lg border-amber-300 text-sm text-slate-900 shadow-sm focus:border-amber-500 focus:ring-amber-500/30 dark:border-amber-500/40 dark:bg-slate-950/40 dark:text-white">
                            </div>
                        </div>
                    @endunless

                    <x-ui.card title="Client" subtitle="Create a new client, or link one that already exists. Nothing is matched silently." icon="building-office">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Client">
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg p-3 ring-1 transition" x-bind:class="mode === 'create' ? 'bg-brand-50 ring-brand-300 dark:bg-brand-500/10 dark:ring-brand-500/40' : 'ring-slate-200 hover:bg-slate-50 dark:ring-slate-700 dark:hover:bg-slate-800/60'">
                                <input type="radio" name="client_mode" value="create" x-model="mode" class="mt-0.5 h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                                <span class="text-sm">
                                    <span class="block font-semibold text-slate-900 dark:text-white">Create a new client</span>
                                    <span class="text-slate-500 dark:text-slate-400">A client ID is generated automatically.</span>
                                </span>
                            </label>
                            <label @class([
                                'flex items-start gap-3 rounded-lg p-3 ring-1 transition',
                                'cursor-pointer' => $selectableMatches->isNotEmpty(),
                                'cursor-not-allowed opacity-60' => $selectableMatches->isEmpty(),
                            ]) x-bind:class="mode === 'existing' ? 'bg-brand-50 ring-brand-300 dark:bg-brand-500/10 dark:ring-brand-500/40' : 'ring-slate-200 hover:bg-slate-50 dark:ring-slate-700 dark:hover:bg-slate-800/60'">
                                <input type="radio" name="client_mode" value="existing" x-model="mode" @disabled($selectableMatches->isEmpty()) class="mt-0.5 h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                                <span class="text-sm">
                                    <span class="block font-semibold text-slate-900 dark:text-white">Link an existing client</span>
                                    <span class="text-slate-500 dark:text-slate-400">
                                        {{ $selectableMatches->isEmpty() ? 'No existing client shares this lead’s contact details.' : app_number($selectableMatches->count()).' possible '.\Illuminate\Support\Str::plural('match', $selectableMatches->count()).' below.' }}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <x-ui.form.error for="client_mode" />

                        @if ($clientMatches->isNotEmpty())
                            <div class="mt-5 space-y-2" x-show="mode === 'existing'" x-cloak>
                                <p class="text-xs font-medium text-slate-600 dark:text-slate-300">Choose the client to link</p>
                                @foreach ($clientMatches as $match)
                                    @if (data_get($match, 'restricted'))
                                        <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/60 dark:text-slate-300 dark:ring-slate-700">
                                            A client you cannot open matches by {{ strtolower((string) (data_get($match, 'match_type_label') ?? data_get($match, 'match_type'))) }}.
                                        </p>
                                    @elseif (filled(data_get($match, 'client_id')))
                                        @php $matchType = data_get($match, 'match_type'); $matchTypeValue = $matchType instanceof \BackedEnum ? $matchType->value : (string) $matchType; @endphp
                                        <label class="flex cursor-pointer items-start gap-3 rounded-lg p-3 ring-1 ring-slate-200 transition hover:bg-slate-50 has-[:checked]:bg-brand-50 has-[:checked]:ring-brand-300 dark:ring-slate-700 dark:hover:bg-slate-800/60 dark:has-[:checked]:bg-brand-500/10 dark:has-[:checked]:ring-brand-500/40">
                                            <input type="radio" name="existing_client_id" value="{{ data_get($match, 'client_id') }}" data-matched-by="{{ $matchTypeValue }}" @checked((string) old('existing_client_id') === (string) data_get($match, 'client_id')) x-on:change="$refs.matchedBy.value = $el.dataset.matchedBy" x-bind:disabled="mode !== 'existing'" class="mt-0.5 h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                                            <span class="min-w-0 text-sm">
                                                <span class="block font-semibold text-slate-900 dark:text-white">{{ data_get($match, 'company_name') ?: data_get($match, 'name') }}</span>
                                                <span class="block text-slate-500 dark:text-slate-400">
                                                    <span class="font-mono">{{ data_get($match, 'client_code') }}</span>
                                                    @if (filled(data_get($match, 'company_name')) && filled(data_get($match, 'name'))) · {{ data_get($match, 'name') }} @endif
                                                    · matched by {{ strtolower((string) (data_get($match, 'match_type_label') ?? \Illuminate\Support\Str::headline($matchTypeValue))) }}
                                                </span>
                                                @if (filled(data_get($match, 'url')))
                                                    <a href="{{ data_get($match, 'url') }}" target="_blank" rel="noopener" class="text-xs font-semibold text-brand-700 hover:underline dark:text-brand-300">Open the client</a>
                                                @endif
                                            </span>
                                        </label>
                                    @endif
                                @endforeach
                                <input type="hidden" name="matched_by" x-ref="matchedBy" value="{{ old('matched_by') }}" x-bind:disabled="mode !== 'existing'">
                                <x-ui.form.error for="existing_client_id" />
                            </div>
                        @endif

                        <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2" x-show="mode === 'create'">
                            <x-ui.form.select name="client[client_type]" label="Client type" :options="$clientTypeOptions" :selected="old('client.client_type', filled($lead->company) ? 'company' : 'individual')" x-bind:disabled="mode !== 'create'" />
                            <x-ui.form.input name="client[company_name]" label="Company" :value="$mapValue('company_name') ?? $lead->company" optional maxlength="150" x-bind:disabled="mode !== 'create'" />
                            <x-ui.form.input name="client[name]" label="Contact name" :value="$mapValue('name') ?? $lead->name" required maxlength="150" x-bind:disabled="mode !== 'create'" />
                            <x-ui.form.input name="client[email]" type="email" label="Email" :value="$mapValue('email') ?? $lead->email" optional maxlength="150" x-bind:disabled="mode !== 'create'" />
                            <x-ui.form.input name="client[phone]" label="Phone" :value="$mapValue('phone') ?? $lead->phone" optional maxlength="32" x-bind:disabled="mode !== 'create'" />
                            <x-ui.form.input name="client[whatsapp]" label="WhatsApp" :value="$mapValue('whatsapp') ?? $lead->whatsapp" optional maxlength="32" x-bind:disabled="mode !== 'create'" />
                            <x-ui.form.input name="client[country]" label="Country" :value="$mapValue('country') ?? $lead->country" optional maxlength="64" x-bind:disabled="mode !== 'create'" />
                            <x-ui.form.input name="client[country_code]" label="Country code" :value="$mapValue('country_code') ?? $lead->country_code" optional maxlength="2" x-bind:disabled="mode !== 'create'" />
                            @if ($accountManagerOptions !== [])
                                <x-ui.form.select name="client[account_manager_id]" label="Account manager" :options="$accountManagerOptions" :selected="old('client.account_manager_id', $lead->assigned_to)" placeholder="Nobody yet" x-bind:disabled="mode !== 'create'" />
                            @endif
                        </div>
                    </x-ui.card>

                    @if ($canCreateProject)
                        <x-ui.card title="Project hand-off" subtitle="Open the project now, carrying the referral forward to where money will be received." icon="folder">
                            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                <input type="checkbox" name="create_project" value="1" x-model="project" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                                Create a project for this client
                            </label>
                            <div class="mt-4 grid grid-cols-1 gap-4" x-show="project" x-cloak>
                                <x-ui.form.input name="project[project_name]" label="Project name" :value="old('project.project_name', $lead->interested_service ?: ($lead->company ?: $lead->name))" maxlength="150" x-bind:disabled="! project" x-bind:required="project" />
                                <x-ui.form.textarea name="project[project_description]" label="Brief" :rows="3" maxlength="10000" x-bind:disabled="! project" />
                            </div>
                        </x-ui.card>
                    @endif

                    <x-ui.card title="Conversion note" icon="pencil">
                        <x-ui.form.input name="notes" :value="old('notes')" maxlength="255" placeholder="Anything the audit trail should explain" />
                    </x-ui.card>
                </div>

                <div class="space-y-6">
                    <x-ui.card title="What is carried forward" icon="share">
                        <dl class="space-y-3 text-sm">
                            <div>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">Referral code</dt>
                                <dd class="mt-0.5 font-mono text-slate-900 dark:text-white">{{ data_get($attribution, 'referral_code') ?: ($lead->referral_code_captured ?: '—') }}</dd>
                            </div>
                            @if ((bool) data_get($attribution, 'recorder_available'))
                                <div>
                                    <dt class="text-xs text-slate-500 dark:text-slate-400">Collaborator</dt>
                                    <dd class="mt-0.5 text-slate-900 dark:text-white">{{ data_get($attribution, 'collaborator_name') ?: 'None recorded' }}</dd>
                                </div>
                            @elseif (filled($lead->referral_code_captured))
                                <p class="text-xs text-slate-500 dark:text-slate-400">The code is copied to the client and attached automatically once referral tracking is available.</p>
                            @endif
                            <div>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">Source</dt>
                                <dd class="mt-0.5">@include('admin.crm.partials.enum-badge', ['value' => $lead->source, 'dot' => false, 'variant' => 'outline'])</dd>
                            </div>
                        </dl>
                        <x-slot:footer>No commission is created here. Commission only ever follows money actually received.</x-slot:footer>
                    </x-ui.card>

                    <x-ui.card title="What stays" icon="shield-check">
                        <ul class="list-inside list-disc space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                            <li>The lead is kept, with its timeline and follow-ups.</li>
                            <li>A snapshot of the lead is saved with the conversion, so later edits never change the audit trail.</li>
                            <li>A mistaken conversion is superseded with a reason, never deleted.</li>
                        </ul>
                    </x-ui.card>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="secondary" :href="route('admin.leads.show', $lead)">Cancel</x-ui.button>
                <x-ui.button type="submit" icon="check-badge" x-bind:disabled="submitting || (! {{ $isWon ? 'true' : 'false' }} && ! promote)" x-bind:aria-busy="submitting ? 'true' : 'false'">Convert lead</x-ui.button>
            </div>
        </form>
    @endif
@endsection
