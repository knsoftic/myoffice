{{--
    Shared lead form — admin/leads/create and admin/leads/edit (phase-05 §8.4). Sections: Contact | Interest | Assignment
    and follow-up | Source and referral | Notes, plus the live duplicate check.

    Expects:
      $lead                     App\Models\Crm\Lead   the row, or a fresh instance on create
      $serviceOptions           array<int, string>    active services (Phase 4 catalogue); [] when the table is absent
      $sourceOptions            array<string, string> InquirySource::options()
      $assigneeOptions          array<int, string>    users who may own a lead (create only)
      $followUpTypeOptions      array<string, string> LeadFollowUpType::options() (create only)
      $followUpDefaultAt        string                'Y-m-d\TH:i' display timezone (create only)
      $defaultSource            ?string               crm.default_lead_source (create only)
      $autoAssignMode           ?string               crm.auto_assign_mode: off | round_robin | least_open | fixed_user
      $duplicateCheckEnabled    bool                  crm.duplicate_detection_enabled
      $duplicateBlockOnExact    bool                  crm.duplicate_block_on_exact
      $referralCode             ?string               a ?ref= code to prefill on create

    Fields posted (StoreLeadRequest / UpdateLeadRequest): name, company, email, phone, whatsapp, country, country_code,
    service_id, interested_service, budget_amount, source, source_detail, notes, confirm_duplicate; on create also
    assigned_to (only offered with leads.assign), referral_code (stored as referral_code_captured),
    add_follow_up + follow_up[type|scheduled_at|notes], and — when "Link as
    duplicate" was used on a match — duplicate_of_lead_id + duplicate_note (the controller calls
    LeadService::linkDuplicate() once the lead exists).
    Never posted: lead_no, status, assigned_to on edit, client_id, converted_at (each has its own method, §6.1).

    Duplicate check JSON (POST admin.leads.duplicate-check {phone, whatsapp, email, country_code, ignore_lead_id}):
      {matches: list<match>, has_exact: bool, block_on_exact: bool}
      match (visible):    {restricted: false, match_type, match_label, match_type_label, is_exact, record_type (lead |
                           client | client_contact), record_type_label, id, lead_id: ?int, reference, name, company,
                           status_label, owner_name, last_activity_at (ISO 8601), last_activity_human?, is_trashed, url}
      match (restricted): {restricted: true, match_type, match_label, record_type, message} — nothing identifying
--}}

@php
    $isEdit = $lead->exists;
    $user = auth()->user();
    $serviceOptions = collect($serviceOptions ?? [])->all();
    $sourceOptions = (array) ($sourceOptions ?? (enum_exists(\App\Enums\InquirySource::class) ? \App\Enums\InquirySource::options() : []));
    $assigneeOptions = collect($assigneeOptions ?? [])->all();
    $followUpTypeOptions = (array) ($followUpTypeOptions ?? (enum_exists(\App\Enums\LeadFollowUpType::class) ? \App\Enums\LeadFollowUpType::options() : []));
    $sourceValue = $lead->source instanceof \BackedEnum ? $lead->source->value : ($lead->source ?: ($defaultSource ?? null));
    $autoAssignMode = (string) ($autoAssignMode ?? 'off');
    $autoAssignHint = match ($autoAssignMode) {
        'round_robin' => 'Leave empty to hand it to the next person in the round robin.',
        'least_open' => 'Leave empty to hand it to whoever has the fewest open leads.',
        'fixed_user' => 'Leave empty to hand it to the default owner set in the CRM settings.',
        default => 'Leave empty to keep it unassigned.',
    };
    $checkUrl = ($duplicateCheckEnabled ?? true) && \Illuminate\Support\Facades\Route::has('admin.leads.duplicate-check') ? route('admin.leads.duplicate-check') : null;
    $linkUrl = $isEdit && \Illuminate\Support\Facades\Route::has('admin.leads.duplicate-link') && (bool) $user?->can('update', $lead) ? route('admin.leads.duplicate-link', $lead) : null;
    $oldLinked = old('duplicate_of_lead_id') ? ['lead_id' => (int) old('duplicate_of_lead_id'), 'name' => null, 'note' => (string) old('duplicate_note')] : null;
    $duplicateConfig = [
        'url' => $checkUrl,
        'linkUrl' => $linkUrl,
        'ignoreLeadId' => $isEdit ? $lead->getKey() : null,
        'blockOnExact' => (bool) ($duplicateBlockOnExact ?? false),
        'confirmed' => (bool) old('confirm_duplicate'),
        'linkedTo' => $oldLinked,
    ];
    $action = $isEdit ? route('admin.leads.update', $lead) : route('admin.leads.store');
@endphp

@include('admin.crm.partials.scripts')

<form method="POST" action="{{ $action }}" class="space-y-6" x-data="crmDuplicateCheck({{ \Illuminate\Support\Js::from($duplicateConfig) }})">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <x-ui.card title="Contact" subtitle="Phone, WhatsApp and email are checked against existing leads and clients as you type." icon="user">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form.input name="name" label="Name" :value="$lead->name" required maxlength="150" autocomplete="off" />
                    <x-ui.form.input name="company" label="Company" :value="$lead->company" optional maxlength="150" autocomplete="off" />
                    <x-ui.form.input name="phone" label="Phone" :value="$lead->phone" icon="phone" optional maxlength="32" inputmode="tel" autocomplete="off" x-on:blur="queue()" />
                    <x-ui.form.input name="whatsapp" label="WhatsApp" :value="$lead->whatsapp" icon="whatsapp" optional maxlength="32" inputmode="tel" autocomplete="off" x-on:blur="queue()" />
                    <x-ui.form.input name="email" type="email" label="Email" :value="$lead->email" icon="envelope" optional maxlength="150" autocomplete="off" x-on:blur="queue()" />
                    <div class="grid grid-cols-3 gap-3">
                        <x-ui.form.input name="country" label="Country" :value="$lead->country" optional maxlength="64" class="col-span-2" />
                        <x-ui.form.input name="country_code" label="Code" :value="$lead->country_code" maxlength="2" placeholder="PK" help="ISO, 2 letters" x-on:blur="queue()" />
                    </div>
                </div>

                {{-- Live duplicate panel --}}
                <div class="mt-5 space-y-3" x-show="loading || matches.length > 0 || linkedTo" x-cloak aria-live="polite">
                    <p x-show="loading" class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                        <x-ui.icon name="arrow-path" class="h-3.5 w-3.5 animate-spin" /> Checking for duplicates…
                    </p>

                    <template x-for="(match, index) in matches" :key="index">
                        <div>
                            <template x-if="match.restricted">
                                <p class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                                    <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" />
                                    <span><span x-text="matchLabel(match)"></span>: matches a <span x-text="recordLabel(match).toLowerCase()"></span> owned by another user.</span>
                                </p>
                            </template>

                            <template x-if="! match.restricted">
                                <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/25">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="font-semibold">
                                                <span x-text="match.name || 'A record'"></span>
                                                <span x-show="match.company" class="font-normal">· <span x-text="match.company"></span></span>
                                                <span x-show="match.reference" class="font-mono text-xs font-normal" x-text="match.reference"></span>
                                            </p>
                                            <p class="mt-0.5 text-xs">
                                                <span x-text="matchLabel(match)"></span> · <span x-text="recordLabel(match).toLowerCase()"></span><span x-show="match.status_label"> · <span x-text="match.status_label"></span></span><span x-show="match.owner_name"> · owned by <span x-text="match.owner_name"></span></span><span x-show="match.last_activity_human"> · last activity <span x-text="match.last_activity_human"></span></span>
                                            </p>
                                            <p x-show="match.is_trashed" class="mt-1 text-xs font-medium text-rose-700 dark:text-rose-300">This lead is in the trash.</p>
                                        </div>
                                        <span x-show="match.is_exact" class="rounded-full bg-amber-200/70 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wider text-amber-900 dark:bg-amber-500/25 dark:text-amber-100">Exact</span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <a x-show="match.url" x-bind:href="match.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800">
                                            <x-ui.icon name="arrow-top-right-on-square" class="h-3.5 w-3.5" /> Open
                                        </a>
                                        <a x-show="match.url && match.record_type === 'lead'" x-bind:href="match.url ? match.url + (match.url.includes('?') ? '&' : '?') + 'tab=timeline&log=1' : '#'" class="inline-flex items-center gap-1 rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800">
                                            <x-ui.icon name="chat-bubble-left-right" class="h-3.5 w-3.5" /> Log activity on the existing lead
                                        </a>
                                        <button type="button" x-show="match.lead_id && match.record_type === 'lead' && ! match.is_trashed" x-on:click="startLink(match)" class="inline-flex items-center gap-1 rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800">
                                            <x-ui.icon name="link" class="h-3.5 w-3.5" /> Link as duplicate
                                        </button>
                                    </div>
                                    <div x-show="linking && linking === match.lead_id" class="mt-2 flex flex-col gap-2 sm:flex-row">
                                        <input type="text" x-model="linkNote" maxlength="255" placeholder="Why these are the same person" aria-label="Duplicate note" class="block w-full rounded-lg border-amber-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500/30 dark:border-amber-500/40 dark:bg-slate-950/40 dark:text-white">
                                        <button type="button" x-on:click="confirmLink(match)" class="inline-flex shrink-0 items-center justify-center rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 dark:bg-amber-500 dark:text-slate-950 dark:hover:bg-amber-400">Confirm link</button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="linkedTo">
                        <p class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/60 dark:text-slate-200 dark:ring-slate-700">
                            <x-ui.icon name="link" class="h-4 w-4 shrink-0" />
                            <span>Will be linked as a duplicate<span x-show="linkedTo?.name"> of <span class="font-semibold" x-text="linkedTo?.name"></span></span> once saved.</span>
                            <button type="button" x-on:click="linkedTo = null" class="ml-auto text-xs font-semibold text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">Undo</button>
                        </p>
                    </template>
                    <input type="hidden" name="duplicate_of_lead_id" x-bind:value="linkedTo ? linkedTo.lead_id : ''">
                    <input type="hidden" name="duplicate_note" x-bind:value="linkedTo ? linkedTo.note : ''">

                    <div x-show="matches.length > 0">
                        <p x-show="blocked" class="mb-2 text-sm font-medium text-rose-700 dark:text-rose-300">
                            An exact match exists and the CRM settings refuse to save an exact duplicate. Open the existing record instead.
                        </p>
                        <label class="flex items-start gap-2.5 text-sm text-slate-700 dark:text-slate-200" x-show="! blocked">
                            <input type="checkbox" name="confirm_duplicate" value="1" x-model="confirmed" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                            <span>I have checked these matches and still want to save this lead.</span>
                        </label>
                    </div>
                </div>
                <x-ui.form.error for="confirm_duplicate" />
            </x-ui.card>

            <x-ui.card title="Interest" subtitle="What they want, and what they said they can spend." icon="briefcase">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form.select name="service_id" label="Interested service" :options="$serviceOptions" :selected="$lead->service_id" placeholder="Not in the catalogue" optional />
                    <x-ui.form.input name="interested_service" label="Or describe it" :value="$lead->interested_service" optional maxlength="150" help="Kept as typed, even if the service is renamed later." />
                    <x-ui.form.input name="budget_amount" label="Budget" :value="$lead->budget_amount" optional inputmode="decimal" pattern="^\d{1,13}(\.\d{1,2})?$" placeholder="0.00" help="The only money figure on a lead; the board sums it per stage." />
                </div>
            </x-ui.card>

            <x-ui.card title="Notes" subtitle="The standing note on the record. Dated notes belong on the timeline." icon="pencil">
                <x-ui.form.textarea name="notes" :value="$lead->notes" :rows="5" maxlength="10000" />
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Assignment and follow-up" icon="user-plus">
                @if ($isEdit)
                    @php
                        $currentAssignee = $lead->relationLoaded('assignee') ? $lead->assignee : null;
                    @endphp
                    <p class="text-xs text-slate-500 dark:text-slate-400">Owner</p>
                    <div class="mt-1 flex items-center justify-between gap-3">
                        @if ($currentAssignee)
                            <span class="flex min-w-0 items-center gap-2">
                                <x-ui.avatar :src="$currentAssignee->avatar_url ?? null" :name="$currentAssignee->name" size="sm" />
                                <span class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $currentAssignee->name }}</span>
                            </span>
                        @else
                            <span class="text-sm text-slate-400 dark:text-slate-500">Unassigned</span>
                        @endif
                    </div>
                    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Owner, status and follow-ups are changed from the lead's page, so every change lands on its timeline.</p>
                @else
                    <div class="space-y-4" x-data="{ addFollowUp: {{ \Illuminate\Support\Js::from((bool) old('add_follow_up')) }} }">
                        <x-ui.form.select name="assigned_to" label="Owner" :options="$assigneeOptions" :selected="old('assigned_to')" placeholder="Nobody yet" :help="$autoAssignHint" />

                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                            <input type="checkbox" name="add_follow_up" value="1" x-model="addFollowUp" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800">
                            Schedule the first follow-up
                        </label>
                        <div x-show="addFollowUp" x-cloak class="space-y-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                            <x-ui.form.select name="follow_up[type]" label="Type" :options="$followUpTypeOptions" :selected="old('follow_up.type')" size="sm" x-bind:disabled="! addFollowUp" />
                            <x-ui.form.input name="follow_up[scheduled_at]" type="datetime-local" label="Due" :value="old('follow_up.scheduled_at', $followUpDefaultAt ?? null)" size="sm" x-bind:disabled="! addFollowUp" />
                            <x-ui.form.input name="follow_up[notes]" label="What to say or send" size="sm" maxlength="255" x-bind:disabled="! addFollowUp" />
                        </div>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="Source and referral" icon="megaphone">
                <div class="space-y-4">
                    <x-ui.form.select name="source" label="Source" :options="$sourceOptions" :selected="$sourceValue" required />
                    <x-ui.form.input name="source_detail" label="Campaign, page or referrer" :value="$lead->source_detail" optional maxlength="255" />
                    @if ($isEdit)
                        <div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Referral code (as captured)</p>
                            <p class="mt-1 font-mono text-sm text-slate-900 dark:text-white">{{ $lead->referral_code_captured ?: '—' }}</p>
                        </div>
                    @else
                        <x-ui.form.input name="referral_code" label="Referral code" :value="old('referral_code', $referralCode ?? null)" optional maxlength="32" autocomplete="off" help="Stored exactly as given. It pays nothing by itself; commission only ever follows a received payment." />
                    @endif
                </div>
            </x-ui.card>
        </div>
    </div>

    <div class="sticky bottom-0 z-10 -mx-4 flex flex-col-reverse gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:flex-row sm:items-center sm:justify-end sm:rounded-xl sm:border sm:shadow-sm dark:border-slate-800 dark:bg-slate-900/95">
        <p x-show="submitDisabled" x-cloak class="text-xs text-amber-700 sm:mr-auto dark:text-amber-300">
            <span x-show="blocked">An exact duplicate cannot be saved.</span>
            <span x-show="! blocked">Tick the confirmation under the matches to save.</span>
        </p>
        <x-ui.button variant="secondary" :href="$isEdit ? route('admin.leads.show', $lead) : route('admin.leads.index')">Cancel</x-ui.button>
        <x-ui.button type="submit" icon="check" x-bind:disabled="submitDisabled" x-bind:class="submitDisabled ? 'cursor-not-allowed opacity-60' : ''">
            {{ $isEdit ? 'Save changes' : 'Create lead' }}
        </x-ui.button>
    </div>
</form>
