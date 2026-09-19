{{--
    Lead detail · Overview tab (phase-05 §8.3): the §18 fields, the duplicate warning panel and the referral block.
    Included by admin/leads/show with its scope ($lead, $duplicateReport, $referral, $canUpdate, $user).
--}}

@php
    $service = $lead->relationLoaded('service') ? $lead->service : null;
    $creator = $lead->relationLoaded('creator') ? $lead->creator : null;
    $client = $lead->relationLoaded('client') ? $lead->client : null;
    $duplicateOf = $lead->relationLoaded('duplicateOf') ? $lead->duplicateOf : null;
    $leadImport = $lead->relationLoaded('leadImport') ? $lead->leadImport : null;
    // DuplicateReport::$matches holds DuplicateMatch objects; their toArray() is the same shape the JSON check returns
    // (a restricted match carries no id, name, contact or link).
    $matches = collect(data_get($duplicateReport ?? null, 'matches', []))
        ->map(static function ($match): array {
            $row = is_object($match) && method_exists($match, 'toArray') ? $match->toArray() : (array) $match;
            $row['match_type_label'] ??= $row['match_label'] ?? null;
            $row['lead_id'] ??= ($row['record_type'] ?? null) === 'lead' && ! ($row['restricted'] ?? true) ? ($row['id'] ?? null) : null;
            $row['record_type_label'] ??= match ($row['record_type'] ?? null) {
                'lead' => 'Lead',
                'client' => 'Client',
                'client_contact' => 'Client contact',
                default => null,
            };

            return $row;
        });
    $referral = (array) ($referral ?? []);
    $canLink = ($canUpdate ?? false) && \Illuminate\Support\Facades\Route::has('admin.leads.duplicate-link');

    $fields = [
        'Name' => $lead->name,
        'Company' => $lead->company,
        'Email' => $lead->email,
        'Phone' => $lead->phone,
        'WhatsApp' => $lead->whatsapp,
        'Country' => collect([$lead->country, $lead->country_code ? strtoupper((string) $lead->country_code) : null])->filter()->implode(' · '),
        'Interested service' => $service?->name ?? $lead->interested_service,
        'Budget' => $lead->budget_amount !== null ? money((string) $lead->budget_amount) : null,
        'Source detail' => $lead->source_detail,
        'Follow-up date' => $lead->follow_up_at ? app_datetime($lead->follow_up_at) : null,
        'Last contacted' => $lead->last_contacted_at ? app_datetime($lead->last_contacted_at) : null,
        'Created' => app_datetime($lead->created_at).($creator ? ' by '.$creator->name : ''),
    ];
@endphp

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        <x-ui.card title="Lead details" icon="identification">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                @foreach ($fields as $fieldLabel => $fieldValue)
                    <div class="min-w-0">
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ $fieldLabel }}</dt>
                        <dd class="mt-0.5 break-words text-slate-900 dark:text-white">{{ filled($fieldValue) ? $fieldValue : '—' }}</dd>
                    </div>
                @endforeach
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Source</dt>
                    <dd class="mt-0.5">@include('admin.crm.partials.enum-badge', ['value' => $lead->source, 'dot' => false, 'variant' => 'outline'])</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Status</dt>
                    <dd class="mt-0.5 flex flex-wrap items-center gap-2">
                        @include('admin.crm.partials.enum-badge', ['value' => $lead->status])
                        @if ($lead->status_changed_at)
                            <span class="text-xs text-slate-500 dark:text-slate-400">since {{ app_datetime($lead->status_changed_at) }}</span>
                        @endif
                    </dd>
                </div>
            </dl>

            @if (filled($lead->lost_reason) || $lead->lost_at || $lead->won_at)
                <div class="mt-5 grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-2 dark:border-slate-800">
                    @if ($lead->won_at)
                        <p class="text-emerald-700 dark:text-emerald-300"><span class="font-semibold">Won</span> {{ app_datetime($lead->won_at) }}</p>
                    @endif
                    @if ($lead->lost_at || filled($lead->lost_reason))
                        <p class="text-rose-700 dark:text-rose-300">
                            <span class="font-semibold">Lost</span>@if ($lead->lost_at) {{ app_datetime($lead->lost_at) }}@endif
                            @if (filled($lead->lost_reason))
                                — {{ $lead->lost_reason }}
                            @endif
                        </p>
                    @endif
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Notes" icon="pencil">
            @if (filled($lead->notes))
                <p class="whitespace-pre-line text-sm leading-relaxed text-slate-700 dark:text-slate-200">{{ $lead->notes }}</p>
            @else
                <p class="text-sm text-slate-400 dark:text-slate-500">No standing note. Dated notes are on the timeline.</p>
            @endif
        </x-ui.card>
    </div>

    <div class="space-y-6">
        <x-ui.card title="Duplicates" icon="link">
            <div class="space-y-3 text-sm">
                @if ($duplicateOf)
                    <div class="rounded-lg bg-amber-50 px-3 py-2 text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/25">
                        <p>Linked as a duplicate of <a href="{{ route('admin.leads.show', $duplicateOf) }}" class="font-semibold underline-offset-2 hover:underline">{{ $duplicateOf->name }}</a> <span class="font-mono text-xs">{{ $duplicateOf->lead_no }}</span>.</p>
                        @if (filled($lead->duplicate_note))
                            <p class="mt-1 text-xs">“{{ $lead->duplicate_note }}”</p>
                        @endif
                        @if ($lead->duplicate_flagged_at)
                            <p class="mt-1 text-xs opacity-80">{{ app_datetime($lead->duplicate_flagged_at) }}</p>
                        @endif
                    </div>
                @elseif (filled($lead->duplicate_of_lead_id))
                    <p class="text-amber-800 dark:text-amber-200">Linked as a duplicate of a lead you cannot open.</p>
                @endif

                @forelse ($matches as $match)
                    @php
                        $restricted = (bool) data_get($match, 'restricted', false);
                        $matchType = data_get($match, 'match_type_label') ?? \Illuminate\Support\Str::headline((string) (data_get($match, 'match_type') instanceof \BackedEnum ? data_get($match, 'match_type')->value : data_get($match, 'match_type', 'contact')));
                        $recordType = data_get($match, 'record_type_label') ?? \Illuminate\Support\Str::headline((string) data_get($match, 'record_type', 'record'));
                        $matchLeadId = data_get($match, 'lead_id');
                        $matchUrl = data_get($match, 'url');
                    @endphp
                    @if ($restricted)
                        <p class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                            <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ $matchType }}: matches a {{ strtolower((string) $recordType) }} owned by another user.</span>
                        </p>
                    @else
                        <div class="rounded-lg bg-amber-50 p-3 text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/25" x-data="{ linking: false }">
                            <p class="font-semibold">
                                @if (filled($matchUrl))
                                    <a href="{{ $matchUrl }}" class="underline-offset-2 hover:underline">{{ data_get($match, 'name') ?: 'A record' }}</a>
                                @else
                                    {{ data_get($match, 'name') ?: 'A record' }}
                                @endif
                                @if (filled(data_get($match, 'company')))
                                    <span class="font-normal">· {{ data_get($match, 'company') }}</span>
                                @endif
                            </p>
                            <p class="mt-0.5 text-xs">
                                {{ $matchType }} · {{ strtolower((string) $recordType) }}
                                @if (filled(data_get($match, 'status_label'))) · {{ data_get($match, 'status_label') }} @endif
                                @if (filled(data_get($match, 'owner_name'))) · {{ data_get($match, 'owner_name') }} @endif
                                @if (data_get($match, 'is_trashed')) · in the trash @endif
                            </p>
                            @if ($canLink && filled($matchLeadId) && data_get($match, 'record_type') === 'lead' && ! data_get($match, 'is_trashed'))
                                <button type="button" x-show="! linking" x-on:click="linking = true" class="mt-2 inline-flex items-center gap-1 rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800">
                                    <x-ui.icon name="link" class="h-3.5 w-3.5" /> Link as a duplicate of this
                                </button>
                                <form x-show="linking" x-cloak method="POST" action="{{ route('admin.leads.duplicate-link', $lead) }}" class="mt-2 space-y-2">
                                    @csrf
                                    <input type="hidden" name="original_lead_id" value="{{ $matchLeadId }}">
                                    <label for="duplicate-note-{{ $loop->index }}" class="sr-only">Why these are the same person</label>
                                    <input id="duplicate-note-{{ $loop->index }}" type="text" name="note" required maxlength="255" placeholder="Why these are the same person" class="block w-full rounded-lg border-amber-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500/30 dark:border-amber-500/40 dark:bg-slate-950/40 dark:text-white">
                                    <div class="flex items-center gap-2">
                                        <x-ui.button type="submit" size="sm" icon="link">Link</x-ui.button>
                                        <x-ui.button size="sm" variant="ghost" x-on:click="linking = false">Cancel</x-ui.button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endif
                @empty
                    @unless ($duplicateOf || filled($lead->duplicate_of_lead_id))
                        <p class="text-slate-500 dark:text-slate-400">No other lead or client shares this phone, WhatsApp or email.</p>
                    @endunless
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.card title="Referral" icon="share">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Code captured</dt>
                    <dd class="mt-0.5 font-mono text-slate-900 dark:text-white">{{ $lead->referral_code_captured ?: '—' }}</dd>
                </div>
                @if ((bool) ($referral['available'] ?? false))
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Collaborator</dt>
                        <dd class="mt-0.5 text-slate-900 dark:text-white">
                            @if (filled($referral['collaborator_name'] ?? null))
                                {{ $referral['collaborator_name'] }}
                                @if (filled($referral['collaborator_code'] ?? null))
                                    <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $referral['collaborator_code'] }}</span>
                                @endif
                            @else
                                Not resolved
                            @endif
                        </dd>
                    </div>
                @endif
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Recorded</dt>
                    <dd class="mt-0.5 text-slate-900 dark:text-white">
                        @if ($lead->referral_recorded_at)
                            {{ app_datetime($lead->referral_recorded_at) }}
                        @elseif (filled($lead->referral_code_captured))
                            Waiting — attached automatically once referral tracking is available
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>
            <x-slot:footer>A referred lead earns nothing by itself: commission only ever follows a payment actually received.</x-slot:footer>
        </x-ui.card>

        <x-ui.card title="Provenance" icon="inbox-stack">
            <dl class="space-y-3 text-sm">
                @if ($client)
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Client</dt>
                        <dd class="mt-0.5">
                            @if (\Illuminate\Support\Facades\Route::has('admin.clients.show') && auth()->user()?->can('view', $client))
                                <a href="{{ route('admin.clients.show', $client) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-300">{{ $client->display_name ?? $client->name }}</a>
                            @else
                                <span class="text-slate-900 dark:text-white">{{ $client->display_name ?? $client->name }}</span>
                            @endif
                            <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $client->client_code }}</span>
                        </dd>
                    </div>
                @endif
                @if (filled($lead->contact_inquiry_id))
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Website inquiry</dt>
                        <dd class="mt-0.5">
                            @if (\Illuminate\Support\Facades\Route::has('admin.contact-inquiries.show') && auth()->user()?->can('contact_inquiries.view'))
                                <a href="{{ route('admin.contact-inquiries.show', $lead->contact_inquiry_id) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-300">Inquiry #{{ $lead->contact_inquiry_id }}</a>
                            @else
                                <span class="text-slate-900 dark:text-white">Inquiry #{{ $lead->contact_inquiry_id }}</span>
                            @endif
                        </dd>
                    </div>
                @endif
                @if (filled($lead->lead_import_id))
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Imported</dt>
                        <dd class="mt-0.5 text-slate-900 dark:text-white">
                            @if (\Illuminate\Support\Facades\Route::has('admin.leads.import.show') && auth()->user()?->can('leads.import'))
                                <a href="{{ route('admin.leads.import.show', $lead->lead_import_id) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-300">{{ $leadImport?->original_filename ?? 'Import #'.$lead->lead_import_id }}</a>
                            @else
                                {{ $leadImport?->original_filename ?? 'Import #'.$lead->lead_import_id }}
                            @endif
                        </dd>
                    </div>
                @endif
                @if (blank($lead->contact_inquiry_id) && blank($lead->lead_import_id) && ! $client)
                    <p class="text-slate-500 dark:text-slate-400">Entered by hand.</p>
                @endif
            </dl>
        </x-ui.card>
    </div>
</div>
