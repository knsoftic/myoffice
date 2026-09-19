{{--
    Lead detail · Conversion tab (phase-05 §8.3): the lead_conversions rows — the live one first — linking the client and
    the project, showing the snapshot, the actor, the time and the attribution carried forward. Append-only (D19): a
    wrong conversion is superseded with a reason, never edited or deleted (§6.4, Q8).

    Included by admin/leads/show: $lead, $conversions (Collection<LeadConversion> with client, convertedBy), $canConvert.
    Supersede: POST admin.leads.conversions.supersede {conversion} {reason} — LeadConversionPolicy::supersede.
--}}

@php
    $conversionUser = auth()->user();
    $orderedConversions = collect($conversions)->sortBy(static fn ($conversion): int => $conversion->superseded_at === null ? 0 : 1)->values();
    $leadStatusValue = $lead->status instanceof \BackedEnum ? $lead->status->value : (string) $lead->status;
@endphp

@if ($orderedConversions->isEmpty())
    <x-ui.card :padded="false">
        <x-ui.empty-state icon="check-badge" title="Not converted yet" :message="$leadStatusValue === 'won' ? 'This lead is won. Convert it into a client to start the work.' : 'A lead is converted once it is won; the conversion screen can mark it won and convert it in one step.'">
            @if ($canConvert ?? false)
                <x-slot:action>
                    <x-ui.button icon="check-badge" :href="route('admin.leads.convert.form', $lead)">Convert to client</x-ui.button>
                </x-slot:action>
            @endif
        </x-ui.empty-state>
    </x-ui.card>
@else
    <div class="space-y-6">
        @foreach ($orderedConversions as $conversion)
            @php
                $isLive = $conversion->superseded_at === null;
                $conversionClient = $conversion->relationLoaded('client') ? $conversion->client : null;
                $convertedBy = $conversion->relationLoaded('convertedBy') ? $conversion->convertedBy : null;
                $snapshot = (array) ($conversion->lead_snapshot ?? []);
                $fieldMap = (array) ($conversion->field_map ?? []);
                $canSupersede = $isLive && (bool) $conversionUser?->can('supersede', $conversion) && \Illuminate\Support\Facades\Route::has('admin.leads.conversions.supersede');
            @endphp
            <x-ui.card :title="$isLive ? 'Live conversion' : 'Superseded conversion'" :subtitle="app_datetime($conversion->converted_at).($convertedBy ? ' by '.$convertedBy->name : '')" icon="check-badge" :class="$isLive ? '' : 'opacity-80'">
                <x-slot:actions>
                    @include('admin.crm.partials.enum-badge', ['value' => $conversion->conversion_type, 'dot' => false])
                    @if ($canSupersede)
                        <x-ui.confirm
                            :action="route('admin.leads.conversions.supersede', $conversion)"
                            method="POST"
                            title="Supersede this conversion?"
                            message="The conversion stays on record, marked superseded, and a corrected conversion can then be made. The client is not unlinked or deleted."
                            confirm-label="Supersede"
                            variant="warning"
                            icon="arrow-path"
                            id="conversion-supersede-{{ $conversion->getKey() }}"
                        >
                            <x-slot:trigger>
                                <x-ui.button size="sm" variant="secondary" icon="arrow-path">Supersede</x-ui.button>
                            </x-slot:trigger>
                            <div class="mt-3">
                                <label for="conversion-supersede-reason-{{ $conversion->getKey() }}" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Reason <span class="text-rose-500">*</span></label>
                                <input id="conversion-supersede-reason-{{ $conversion->getKey() }}" type="text" name="reason" form="conversion-supersede-{{ $conversion->getKey() }}" required maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                            </div>
                        </x-ui.confirm>
                    @endif
                </x-slot:actions>

                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Client</dt>
                        <dd class="mt-0.5">
                            @if ($conversionClient)
                                @if (\Illuminate\Support\Facades\Route::has('admin.clients.show') && $conversionUser?->can('view', $conversionClient))
                                    <a href="{{ route('admin.clients.show', $conversionClient) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-300">{{ $conversionClient->display_name ?? $conversionClient->name }}</a>
                                @else
                                    <span class="text-slate-900 dark:text-white">{{ $conversionClient->display_name ?? $conversionClient->name }}</span>
                                @endif
                                <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $conversionClient->client_code }}</span>
                            @elseif (filled($conversion->client_id))
                                <span class="text-slate-500 dark:text-slate-400">A client you cannot open</span>
                            @else
                                —
                            @endif
                        </dd>
                        <dd class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            @if ($conversion->created_client)
                                Created by this conversion
                            @elseif (filled($conversion->client_id))
                                Linked to an existing client
                                @if ($conversion->matched_by)
                                    — matched by @include('admin.crm.partials.enum-badge', ['value' => $conversion->matched_by, 'size' => 'xs', 'dot' => false])
                                @endif
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Project</dt>
                        <dd class="mt-0.5 text-slate-900 dark:text-white">
                            @if (filled($conversion->project_id))
                                @if (\Illuminate\Support\Facades\Route::has('admin.projects.show'))
                                    <a href="{{ route('admin.projects.show', $conversion->project_id) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-300">Project #{{ $conversion->project_id }}</a>
                                @else
                                    Project #{{ $conversion->project_id }}
                                @endif
                            @else
                                <span class="text-slate-500 dark:text-slate-400">No project hand-off</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Status when converted</dt>
                        <dd class="mt-0.5">@include('admin.crm.partials.enum-badge', ['value' => $conversion->from_status])</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Budget at conversion</dt>
                        <dd class="mt-0.5 tabular-nums text-slate-900 dark:text-white">{{ $conversion->budget_amount !== null ? money((string) $conversion->budget_amount) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Attribution carried forward</dt>
                        <dd class="mt-0.5 text-slate-900 dark:text-white">
                            @if (filled($conversion->referral_code))
                                <span class="font-mono">{{ $conversion->referral_code }}</span>
                                @if (filled($conversion->collaborator_referral_id))
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">Evidence: referral record #{{ $conversion->collaborator_referral_id }}</span>
                                @else
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">Code copied; attached once referral tracking is available</span>
                                @endif
                            @else
                                <span class="text-slate-500 dark:text-slate-400">No referral</span>
                            @endif
                        </dd>
                    </div>
                    @if (filled($conversion->notes))
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Notes</dt>
                            <dd class="mt-0.5 text-slate-900 dark:text-white">{{ $conversion->notes }}</dd>
                        </div>
                    @endif
                </dl>

                @unless ($isLive)
                    <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                        Superseded {{ app_datetime($conversion->superseded_at) }}@if (filled($conversion->supersede_reason)) — “{{ $conversion->supersede_reason }}”@endif
                    </p>
                @endunless

                @if ($snapshot !== [])
                    <details class="group mt-4 rounded-lg border border-slate-200 dark:border-slate-800">
                        <summary class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm font-medium text-slate-700 dark:text-slate-200">
                            The lead as it was at conversion
                            <x-ui.icon name="chevron-down" class="h-4 w-4 transition group-open:rotate-180" />
                        </summary>
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 border-t border-slate-200 px-3 py-3 text-xs sm:grid-cols-2 dark:border-slate-800">
                            @foreach ($snapshot as $snapshotKey => $snapshotValue)
                                <div class="min-w-0">
                                    <dt class="text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Str::headline((string) $snapshotKey) }}</dt>
                                    <dd class="break-words text-slate-900 dark:text-white">
                                        {{ is_scalar($snapshotValue) ? (is_bool($snapshotValue) ? ($snapshotValue ? 'Yes' : 'No') : ($snapshotValue === '' ? '—' : $snapshotValue)) : ($snapshotValue === null ? '—' : json_encode($snapshotValue, JSON_UNESCAPED_UNICODE)) }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                        @if ($fieldMap !== [])
                            <p class="border-t border-slate-200 px-3 py-2 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                                Copied to the client: {{ collect($fieldMap)->map(static fn ($to, $from): string => \Illuminate\Support\Str::headline((string) $from).' → '.\Illuminate\Support\Str::headline(is_scalar($to) ? (string) $to : (string) data_get($to, 'field', ''))) ->implode(', ') }}
                            </p>
                        @endif
                    </details>
                @endif
            </x-ui.card>
        @endforeach
    </div>
@endif
