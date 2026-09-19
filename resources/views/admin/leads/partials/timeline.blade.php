{{--
    Lead detail · Timeline tab (phase-05 §8.3): lead_activities newest first, grouped by day, filterable by type. System
    rows (is_system) are visually distinct and have no edit or delete affordance; a manual note offers them only when
    LeadActivityPolicy allows (the author inside crm.activity_edit_window_minutes, or leads.edit on another's note).

    Included by admin/leads/show: $lead, $activities (Collection, or the controller's paginator — 50 per page under the
    `timeline_page` query key), $activityItems (Collection), $activityType (?string, the ?type= filter the controller
    applied), $allActivityTypeOptions, $canLog, $logActivityEvent. The type filter is a GET on the lead page with
    ?tab=timeline&type=…, so it filters every page, not only the one on screen.
--}}

@php
    use App\Support\Format;

    $timelineUser = auth()->user();
    $typeFilterOptions = (array) ($allActivityTypeOptions ?? (enum_exists(\App\Enums\LeadActivityType::class) ? \App\Enums\LeadActivityType::options() : []));
    $byDay = collect($activityItems)->groupBy(static fn ($activity): string => Format::instantDate($activity->occurred_at ?? $activity->created_at, 'Y-m-d'));
    $todayKey = Format::instantDate(now(), 'Y-m-d');
    $yesterdayKey = Format::instantDate(now()->subDay(), 'Y-m-d');
    $userName = static fn ($relation): ?string => $relation?->name;
@endphp

<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <form method="GET" action="{{ route('admin.leads.show', $lead) }}" class="flex w-full items-center gap-2 sm:max-w-xs">
            <input type="hidden" name="tab" value="timeline">
            <label for="timeline-type-filter" class="sr-only">Filter the timeline by type</label>
            <select id="timeline-type-filter" name="type" x-on:change="$el.form.requestSubmit ? $el.form.requestSubmit() : $el.form.submit()" class="block w-full rounded-lg border-slate-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                <option value="">Every kind of activity</option>
                @foreach ($typeFilterOptions as $typeValue => $typeLabel)
                    <option value="{{ $typeValue }}" @selected((string) ($activityType ?? request('type')) === (string) $typeValue)>{{ $typeLabel }}</option>
                @endforeach
            </select>
            <noscript><x-ui.button type="submit" size="sm" variant="secondary">Filter</x-ui.button></noscript>
        </form>
        @if ($canLog ?? false)
            <x-ui.button icon="plus" size="sm" x-on:click="$dispatch('open-modal', {{ \Illuminate\Support\Js::from($logActivityEvent) }})">Log activity</x-ui.button>
        @endif
    </div>

    @if ($byDay->isEmpty())
        <x-ui.card :padded="false">
            @if (filled($activityType ?? request('type')))
                <x-ui.empty-state icon="funnel" title="No activity of this kind" :compact="true">
                    <x-slot:action>
                        <x-ui.button variant="secondary" :href="route('admin.leads.show', ['lead' => $lead, 'tab' => 'timeline'])">Show every kind</x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state icon="clock" title="Nothing on the timeline yet" message="Calls, notes, status changes and follow-ups appear here as they happen." />
            @endif
        </x-ui.card>
    @else
        @foreach ($byDay as $dayKey => $dayActivities)
            <section aria-labelledby="timeline-day-{{ $dayKey }}">
                <h3 id="timeline-day-{{ $dayKey }}" class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                    {{ $dayKey === $todayKey ? 'Today' : ($dayKey === $yesterdayKey ? 'Yesterday' : app_date($dayKey)) }}
                </h3>

                <ol class="space-y-2">
                    @foreach ($dayActivities as $activity)
                        @php
                            $type = $activity->type;
                            $typeValue = $type instanceof \BackedEnum ? $type->value : (string) $type;
                            $typeLabel = $type instanceof \BackedEnum && method_exists($type, 'label') ? $type->label() : \Illuminate\Support\Str::headline($typeValue);
                            $typeIcon = $type instanceof \BackedEnum && method_exists($type, 'icon') ? $type->icon() : 'chat-bubble-left-ellipsis';
                            $isSystem = (bool) $activity->is_system;
                            $creator = $activity->relationLoaded('creator') ? $activity->creator : null;
                            $canEditActivity = ! $isSystem && (bool) $timelineUser?->can('update', $activity) && \Illuminate\Support\Facades\Route::has('admin.leads.activities.update');
                            $canDeleteActivity = ! $isSystem && (bool) $timelineUser?->can('delete', $activity) && \Illuminate\Support\Facades\Route::has('admin.leads.activities.destroy');
                            $outcome = $activity->outcome;
                            $meta = (array) ($activity->meta ?? []);
                        @endphp
                        <li
                            @class([
                                'flex gap-3 rounded-xl p-3 ring-1',
                                'bg-slate-50 ring-slate-200/70 dark:bg-slate-900/40 dark:ring-slate-800' => $isSystem,
                                'bg-white shadow-sm ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800' => ! $isSystem,
                            ])
                        >
                            <span @class([
                                'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full',
                                'bg-slate-200/70 text-slate-500 dark:bg-slate-800 dark:text-slate-400' => $isSystem,
                                'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400' => ! $isSystem,
                            ])>
                                <x-ui.icon :name="$typeIcon" class="h-4 w-4" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="flex flex-wrap items-center gap-2 text-sm">
                                        <span @class(['font-semibold', 'text-slate-600 dark:text-slate-300' => $isSystem, 'text-slate-900 dark:text-white' => ! $isSystem])>{{ $typeLabel }}</span>
                                        @if ($isSystem)
                                            <x-ui.badge color="slate" size="xs" variant="outline">System</x-ui.badge>
                                        @endif
                                        @if ($outcome)
                                            @include('admin.crm.partials.enum-badge', ['value' => $outcome, 'size' => 'xs', 'dot' => false])
                                        @endif
                                        @if ($activity->duration_minutes)
                                            <span class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ app_number((int) $activity->duration_minutes) }} min</span>
                                        @endif
                                    </p>
                                    <span class="text-xs text-slate-500 dark:text-slate-400" title="{{ app_datetime($activity->occurred_at) }}">
                                        {{ app_time($activity->occurred_at) }} · {{ $creator?->name ?? 'System' }}
                                    </span>
                                </div>

                                @if ($typeValue === 'status_changed')
                                    <p class="mt-1 flex flex-wrap items-center gap-1.5 text-sm text-slate-700 dark:text-slate-200">
                                        @include('admin.crm.partials.enum-badge', ['value' => $activity->from_status, 'size' => 'xs', 'empty' => 'none'])
                                        <x-ui.icon name="arrow-right" class="h-3.5 w-3.5 text-slate-400" />
                                        @include('admin.crm.partials.enum-badge', ['value' => $activity->to_status, 'size' => 'xs'])
                                    </p>
                                @elseif ($typeValue === 'assigned')
                                    <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">
                                        {{ $userName($activity->relationLoaded('fromUser') ? $activity->fromUser : null) ?? 'Unassigned' }}
                                        <x-ui.icon name="arrow-right" class="inline h-3.5 w-3.5 text-slate-400" />
                                        {{ $userName($activity->relationLoaded('toUser') ? $activity->toUser : null) ?? 'Unassigned' }}
                                    </p>
                                @elseif ($typeValue === 'duplicate_linked')
                                    @php $related = $activity->relationLoaded('relatedLead') ? $activity->relatedLead : null; @endphp
                                    @if ($related)
                                        <p class="mt-1 text-sm text-slate-700 dark:text-slate-200">
                                            With <a href="{{ route('admin.leads.show', $related) }}" class="font-medium text-brand-700 hover:underline dark:text-brand-300">{{ $related->name }}</a>
                                            <span class="font-mono text-xs text-slate-500 dark:text-slate-400">{{ $related->lead_no }}</span>
                                        </p>
                                    @endif
                                @endif

                                @if (filled($activity->subject))
                                    <p class="mt-1 text-sm font-medium text-slate-800 dark:text-slate-100">{{ $activity->subject }}</p>
                                @endif
                                @if (filled($activity->body))
                                    <p class="mt-1 whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ $activity->body }}</p>
                                @endif
                                @if (filled($meta['file_name'] ?? null) || filled($meta['row_number'] ?? null))
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                        @if (filled($meta['file_name'] ?? null)) {{ $meta['file_name'] }} @endif
                                        @if (filled($meta['row_number'] ?? null)) · row {{ app_number((int) $meta['row_number']) }} @endif
                                    </p>
                                @endif

                                @if ($canEditActivity || $canDeleteActivity)
                                    <div class="mt-2 flex items-center gap-1">
                                        @if ($canEditActivity)
                                            <x-ui.button
                                                size="sm"
                                                variant="ghost"
                                                icon="pencil"
                                                x-on:click="$dispatch('open-modal', {
                                                    name: 'lead-activity',
                                                    url: {{ \Illuminate\Support\Js::from(route('admin.leads.activities.update', [$lead, $activity])) }},
                                                    label: {{ \Illuminate\Support\Js::from($lead->name) }},
                                                    method: 'PUT',
                                                    activity: {{ \Illuminate\Support\Js::from([
                                                        'type' => $typeValue,
                                                        'subject' => $activity->subject,
                                                        'body' => $activity->body,
                                                        'outcome' => $outcome instanceof \BackedEnum ? $outcome->value : $outcome,
                                                        'duration_minutes' => $activity->duration_minutes,
                                                        'occurred_at' => app_datetime($activity->occurred_at, 'Y-m-d\TH:i'),
                                                    ]) }},
                                                })"
                                            >Edit</x-ui.button>
                                        @endif
                                        @if ($canDeleteActivity)
                                            <x-ui.confirm
                                                :action="route('admin.leads.activities.destroy', [$lead, $activity])"
                                                title="Delete this note?"
                                                message="It disappears from the timeline. The audit log keeps a record of it."
                                                confirm-label="Delete note"
                                            >
                                                <x-slot:trigger>
                                                    <x-ui.button size="sm" variant="ghost" icon="trash" class="text-rose-600 dark:text-rose-400">Delete</x-ui.button>
                                                </x-slot:trigger>
                                            </x-ui.confirm>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endforeach

        @if ($activities instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $activities->hasPages())
            <x-ui.card :compact="true">
                <x-ui.pagination-summary :paginator="$activities->appends(['tab' => 'timeline'])" label="activities" />
            </x-ui.card>
        @endif
    @endif
</div>
