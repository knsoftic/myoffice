@extends('layouts.admin')

@section('title', 'Activity entry #'.$entry->getKey())

{{--
    One activity entry (route admin.activity-log.show).

    The diff table is built in the controller from the entry's `old` / `attributes` property
    bags, so this file never reaches into the JSON itself. Values that look like secrets are
    already redacted there.
--}}

@php
    use Illuminate\Support\Str;

    $deviceIcon = static fn (?string $device): string => match (strtolower((string) $device)) {
        'mobile' => 'phone',
        'tablet', 'desktop' => 'computer-desktop',
        'bot' => 'server-stack',
        default => 'question-mark-circle',
    };

    $changedCount = collect($diff)->where('changed', true)->count();
@endphp

@section('header')
    <x-ui.page-header
        :title="'Activity entry #'.$entry->getKey()"
        :subtitle="$entry->description"
        icon="clipboard-document-list"
        :back="$indexUrl"
        :badge="filled($entry->event) ? Str::headline((string) $entry->event) : null"
        badge-color="brand"
    >
        @if ($indexUrl)
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="arrow-left" :href="$indexUrl">
                    Back to log
                </x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">

        {{-- ── Old vs new ────────────────────────────────────────────────────────────── --}}
        <div class="space-y-6 xl:col-span-2">
            <x-ui.card
                title="Values"
                :subtitle="$changedCount === 0
                    ? 'No attribute changed in this entry'
                    : $changedCount.' of '.count($diff).' attributes changed'"
                icon="table-cells"
                :padded="false"
            >
                <x-ui.table :is-empty="empty($diff)" dense :flush="true">
                    <x-slot:head>
                        <th class="px-4 py-3">Field</th>
                        <th class="px-4 py-3">Before</th>
                        <th class="px-4 py-3">After</th>
                    </x-slot:head>

                    @foreach ($diff as $row)
                        <tr @class([
                            'bg-amber-50/70 dark:bg-amber-500/5' => $row['changed'],
                        ])>
                            <td class="align-top whitespace-nowrap">
                                <span class="inline-flex items-center gap-2 font-medium text-slate-900 dark:text-white">
                                    @if ($row['changed'])
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span>
                                        <span class="sr-only">Changed:</span>
                                    @else
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-slate-200 dark:bg-slate-700" aria-hidden="true"></span>
                                    @endif
                                    {{ $row['label'] }}
                                </span>
                                <span class="mt-0.5 block font-mono text-2xs text-slate-400 dark:text-slate-500">{{ $row['attribute'] }}</span>
                            </td>

                            <td class="align-top">
                                <pre class="max-w-xs whitespace-pre-wrap break-words font-mono text-xs {{ $row['changed'] ? 'text-rose-700 line-through decoration-rose-300/70 dark:text-rose-300' : 'text-slate-500 dark:text-slate-400' }}">{{ $row['old'] }}</pre>
                            </td>

                            <td class="align-top">
                                <pre class="max-w-xs whitespace-pre-wrap break-words font-mono text-xs {{ $row['changed'] ? 'font-semibold text-emerald-700 dark:text-emerald-300' : 'text-slate-500 dark:text-slate-400' }}">{{ $row['new'] }}</pre>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state
                            icon="table-cells"
                            title="No attribute values recorded"
                            message="This entry was written without an old/new value bag — the description and the context below are everything it carries."
                            :compact="true"
                        />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>

            @if (! empty($extraProperties))
                <x-ui.card title="Extra properties" subtitle="Context attached to this entry by the code that logged it" icon="document-text">
                    <dl class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($extraProperties as $label => $value)
                            <div class="grid gap-1 py-2.5 first:pt-0 last:pb-0 sm:grid-cols-3">
                                <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                                <dd class="sm:col-span-2">
                                    <pre class="whitespace-pre-wrap break-words font-mono text-xs text-slate-700 dark:text-slate-300">{{ $value }}</pre>
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </x-ui.card>
            @endif
        </div>

        {{-- ── Context ───────────────────────────────────────────────────────────────── --}}
        <div class="space-y-6 xl:col-span-1">
            <x-ui.card title="Who and what" icon="user-circle">
                <dl class="space-y-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Causer</dt>
                        <dd class="mt-1.5">
                            @if ($entry->causer instanceof \App\Models\User)
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :src="$entry->causer->avatar_url" :name="$entry->causer->name" size="md" />
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $entry->causer->name }}</p>
                                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $entry->causer->email }}</p>
                                    </div>
                                </div>
                            @elseif (filled($entry->causer_type))
                                <p class="text-sm text-slate-700 dark:text-slate-300">
                                    {{ Str::headline(class_basename((string) $entry->causer_type)) }}
                                    @if ($entry->causer_id)
                                        <span class="text-slate-400 tabular-nums dark:text-slate-500">#{{ $entry->causer_id }}</span>
                                    @endif
                                </p>
                            @else
                                <p class="inline-flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                                    <x-ui.icon name="cog-6-tooth" class="h-4 w-4" />
                                    System (console, scheduler or seeder)
                                </p>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Record</dt>
                        <dd class="mt-1.5 text-sm text-slate-700 dark:text-slate-300">
                            @if ($subject['type'])
                                <span class="font-semibold text-slate-900 dark:text-white">{{ $subject['label'] }}</span>
                                @if ($subject['id'])
                                    <span class="text-slate-400 tabular-nums dark:text-slate-500">#{{ $subject['id'] }}</span>
                                @endif
                                @if ($subject['name'])
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $subject['name'] }}</p>
                                @endif
                                <p class="mt-0.5 truncate font-mono text-2xs text-slate-400 dark:text-slate-500" title="{{ $subject['type'] }}">
                                    {{ $subject['type'] }}
                                </p>
                            @else
                                <span class="text-slate-400 dark:text-slate-600">No record attached</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Module</dt>
                        <dd class="mt-1.5">
                            @if ($moduleName)
                                <x-ui.badge color="slate" size="sm">{{ $moduleName }}</x-ui.badge>
                            @else
                                <span class="text-sm text-slate-400 dark:text-slate-600">—</span>
                            @endif

                            @if (filled($entry->log_name))
                                <x-ui.badge color="brand" size="sm" variant="outline" class="ml-1">{{ $entry->log_name }}</x-ui.badge>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Reason</dt>
                        <dd class="mt-1.5 text-sm text-slate-700 dark:text-slate-300">
                            {{ filled($entry->reason) ? $entry->reason : '—' }}
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Request context" icon="finger-print">
                <dl class="space-y-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">When</dt>
                        <dd class="mt-1.5 text-sm text-slate-900 dark:text-white">
                            @if ($entry->created_at)
                                {{--
                                    An audit entry keeps its seconds. The time part is the configured
                                    localization.time_format with seconds added after the minutes, so a
                                    12-hour setting stays 12-hour ('h:i A' -> 'h:i:s A'); Format::instantDate()
                                    and app_time() do the timezone conversion (D61: stored UTC, shown in the
                                    display timezone). toIso8601String() is the machine-readable
                                    datetime attribute only, and the zone named underneath is the one the
                                    helpers actually rendered in.

                                    Phase 2 review low 2: created_at is an instant, so its date half goes
                                    through Format::instantDate(), never app_date() — app_date() reads a
                                    value at 00:00:00 as a calendar date and would show UTC's date beside
                                    the converted time west of UTC.
                                --}}
                                @php
                                    $whenTimeFormat = \App\Support\Format::timeFormat();
                                    $whenTimeFormat = str_contains($whenTimeFormat, 's')
                                        ? $whenTimeFormat
                                        : str_replace('i', 'i:s', $whenTimeFormat);
                                @endphp
                                <time datetime="{{ $entry->created_at->toIso8601String() }}" title="{{ $entry->created_at->diffForHumans() }}">
                                    {{ \App\Support\Format::instantDate($entry->created_at) }} · {{ app_time($entry->created_at, $whenTimeFormat) }}
                                </time>
                                <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $entry->created_at->diffForHumans() }} · {{ \App\Support\Format::displayTimezone() }}
                                </span>
                            @else
                                —
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">IP address</dt>
                        <dd class="mt-1.5 font-mono text-sm tabular-nums text-slate-900 dark:text-white">
                            {{ $entry->ip_address ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Device</dt>
                        <dd class="mt-1.5 text-sm text-slate-900 dark:text-white">
                            @if (filled($entry->device))
                                <span class="inline-flex items-center gap-2">
                                    <x-ui.icon :name="$deviceIcon($entry->device)" class="h-4 w-4 text-slate-400 dark:text-slate-500" />
                                    {{ Str::headline((string) $entry->device) }}
                                </span>
                            @else
                                <span class="text-slate-400 dark:text-slate-600">—</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">User agent</dt>
                        <dd class="mt-1.5">
                            @if (filled($entry->user_agent))
                                <pre class="whitespace-pre-wrap break-words font-mono text-2xs leading-relaxed text-slate-600 dark:text-slate-400">{{ $entry->user_agent }}</pre>
                            @else
                                <span class="text-sm text-slate-400 dark:text-slate-600">—</span>
                            @endif
                        </dd>
                    </div>

                    @if (filled($entry->batch_uuid))
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Batch</dt>
                            <dd class="mt-1.5 truncate font-mono text-2xs text-slate-500 dark:text-slate-400" title="{{ $entry->batch_uuid }}">
                                {{ $entry->batch_uuid }}
                            </dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>
        </div>
    </div>
@endsection
