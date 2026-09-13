@extends('layouts.admin')

@section('title', 'Login history')

{{--
    Login history index (route admin.login-history.index).

    Failed and blocked attempts are tinted so a burst of them is visible without reading the
    status column. The summary strip counts the whole filtered range, not just this page.

    Formatting: every visible date goes through app_datetime() and every count through app_number(),
    so localization.date_format / time_format / timezone / separators restyle this screen (D61:
    stored timestamps are UTC, the helpers convert to the display timezone). The only raw calls
    left are toIso8601String() inside <time datetime="…">: that attribute is the machine-readable
    HTML value, carries its own UTC offset, and is never shown to the reader.
--}}

@php
    use App\Enums\LoginStatus;
    use Illuminate\Support\Str;

    $deviceIcon = static fn (?string $device): string => match (strtolower((string) $device)) {
        'mobile' => 'phone',
        'tablet', 'desktop' => 'computer-desktop',
        'bot' => 'server-stack',
        default => 'question-mark-circle',
    };

    $perPageOptions = collect(\App\Http\Requests\Admin\LogFilterRequest::perPageOptions())
        ->mapWithKeys(static fn (int $size): array => [(string) $size => $size.' / page'])
        ->all();

    $successes = $summary['statuses'][LoginStatus::Success->value] ?? 0;
    $failures = $summary['statuses'][LoginStatus::Failed->value] ?? 0;
    $blocked = $summary['statuses'][LoginStatus::Blocked->value] ?? 0;
    $logouts = $summary['statuses'][LoginStatus::Logout->value] ?? 0;
@endphp

@section('header')
    <x-ui.page-header
        title="Login history"
        subtitle="Every successful, failed, blocked and sign-out event, with the IP, device and session length."
        icon="finger-print"
        :badge="app_number($entries->total()).' events'"
        badge-color="slate"
    >
        @if ($canExport && $exportUrl)
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="$exportUrl">
                    Export CSV
                </x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>
@endsection

@section('content')
    {{--
        `navigating` drives the table's skeleton rows while a filter change is in flight: the
        filter bar submits itself, so the rows on screen belong to the previous query from that
        moment on. GET submissions only — see x-ui.table.
    --}}
    <div
        x-data="{ navigating: false }"
        x-on:submit.window="if ($event.target?.method === 'get') navigating = true"
        class="space-y-4"
    >

        {{-- ── Summary strip over the filtered range ─────────────────────────────────── --}}
        <x-ui.card :padded="false">
            <dl class="grid grid-cols-2 divide-slate-100 sm:grid-cols-4 sm:divide-x dark:divide-slate-800">
                <div class="border-b border-slate-100 p-4 sm:border-b-0 dark:border-slate-800">
                    <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <x-ui.icon name="check-circle" class="h-3.5 w-3.5 text-emerald-500" />
                        Successful
                    </dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ app_number($successes) }}
                    </dd>
                </div>

                <div class="border-b border-slate-100 p-4 sm:border-b-0 dark:border-slate-800">
                    <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <x-ui.icon name="exclamation-triangle" class="h-3.5 w-3.5 text-rose-500" />
                        Failed
                    </dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ app_number($failures) }}
                    </dd>
                    @if ($blocked > 0)
                        <dd class="text-xs text-amber-600 dark:text-amber-400">+ {{ app_number($blocked) }} blocked</dd>
                    @endif
                </div>

                <div class="p-4">
                    <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <x-ui.icon name="globe-alt" class="h-3.5 w-3.5 text-sky-500" />
                        Unique IPs
                    </dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ app_number($summary['unique_ips']) }}
                    </dd>
                </div>

                <div class="p-4">
                    <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <x-ui.icon name="calendar-days" class="h-3.5 w-3.5 text-slate-400" />
                        Range
                    </dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ $rangeLabel }}</dd>
                    <dd class="text-xs text-slate-500 dark:text-slate-400">
                        {{ app_number($summary['total']) }} events
                        @if ($logouts > 0)
                            · {{ app_number($logouts) }} sign-outs
                        @endif
                    </dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.filter-bar placeholder="Search name, email, IP, browser…">
            <x-ui.form.select
                name="user_id"
                :options="$userOptions"
                :selected="request('user_id')"
                placeholder="Any user"
                size="sm"
                icon="user"
                aria-label="Filter by user"
            />

            <x-ui.form.select
                name="status"
                :options="$statusOptions"
                :selected="request('status')"
                placeholder="Any status"
                size="sm"
                icon="check-badge"
                aria-label="Filter by status"
            />

            <x-ui.form.input
                name="ip"
                :value="request('ip')"
                placeholder="IP address"
                size="sm"
                icon="globe-alt"
                aria-label="Filter by IP address"
                class="sm:max-w-[12rem]"
            />

            <x-ui.form.input
                type="date"
                name="from"
                :value="request('from')"
                size="sm"
                aria-label="From date"
                class="sm:max-w-[11rem]"
            />

            <x-ui.form.input
                type="date"
                name="to"
                :value="request('to')"
                size="sm"
                aria-label="To date"
                class="sm:max-w-[11rem]"
            />

            <x-ui.form.select
                name="per_page"
                :options="$perPageOptions"
                :selected="(string) $entries->perPage()"
                size="sm"
                aria-label="Rows per page"
                class="sm:max-w-[8rem]"
            />
        </x-ui.filter-bar>

        <x-ui.table :is-empty="$entries->isEmpty()" loading="navigating" :loading-rows="10">
            <x-slot:head>
                <x-ui.th-sortable column="email" :sort="$sort" :direction="$direction">User</x-ui.th-sortable>

                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>

                <x-ui.th-sortable column="ip_address" :sort="$sort" :direction="$direction">IP</x-ui.th-sortable>

                <th class="px-4 py-3">Device</th>

                <x-ui.th-sortable column="logged_in_at" :sort="$sort" :direction="$direction" default="desc">
                    Logged in
                </x-ui.th-sortable>

                <x-ui.th-sortable column="logged_out_at" :sort="$sort" :direction="$direction" default="desc">
                    Logged out
                </x-ui.th-sortable>

                <th class="px-4 py-3 text-right">Duration</th>

                <x-ui.th-sortable column="created_at" :sort="$sort" :direction="$direction" default="desc" align="right">
                    Recorded
                </x-ui.th-sortable>
            </x-slot:head>

            @foreach ($entries as $entry)
                <tr @class([
                    'bg-rose-50/60 dark:bg-rose-500/5' => $entry->status === LoginStatus::Failed,
                    'bg-amber-50/60 dark:bg-amber-500/5' => $entry->status === LoginStatus::Blocked,
                ])>
                    <td class="whitespace-nowrap">
                        @if ($entry->user)
                            <div class="flex items-center gap-2.5">
                                <x-ui.avatar :src="$entry->user->avatar_url" :name="$entry->user->name" size="sm" />
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-900 dark:text-white">
                                        {{ $entry->user->name }}
                                        @if ($entry->user->trashed())
                                            <span class="text-xs font-normal text-slate-400 dark:text-slate-500">(deleted)</span>
                                        @endif
                                    </p>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $entry->user->email }}</p>
                                </div>
                            </div>
                        @else
                            <div class="flex items-center gap-2.5">
                                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500">
                                    <x-ui.icon name="question-mark-circle" class="h-4 w-4" />
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-700 dark:text-slate-300">
                                        {{ $entry->email ?? 'Unknown' }}
                                    </p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">no matching account</p>
                                </div>
                            </div>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        <x-ui.badge :color="$entry->status->color()" size="sm" :dot="true">
                            {{ $entry->status->label() }}
                        </x-ui.badge>
                    </td>

                    <td class="whitespace-nowrap font-mono text-xs tabular-nums text-slate-600 dark:text-slate-300">
                        {{ $entry->ip_address ?? '—' }}
                    </td>

                    <td class="text-xs text-slate-500 dark:text-slate-400">
                        <span class="inline-flex items-center gap-1.5" title="{{ $entry->user_agent }}">
                            <x-ui.icon :name="$deviceIcon($entry->device)" class="h-3.5 w-3.5 shrink-0" />
                            <span class="truncate">{{ $entry->deviceLabel() }}</span>
                        </span>
                        {{-- deviceLabel() already falls back to the device when there is no browser/platform. --}}
                        @if (filled($entry->device) && $entry->deviceLabel() !== $entry->device)
                            <span class="mt-0.5 block text-2xs uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                {{ $entry->device }}
                            </span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-xs text-slate-600 tabular-nums dark:text-slate-300">
                        @if ($entry->logged_in_at)
                            <time
                                datetime="{{ $entry->logged_in_at->toIso8601String() }}"
                                title="{{ $entry->logged_in_at->diffForHumans() }}"
                            >{{ app_datetime($entry->logged_in_at) }}</time>
                        @else
                            <span class="text-slate-400 dark:text-slate-600">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-xs text-slate-600 tabular-nums dark:text-slate-300">
                        @if ($entry->logged_out_at)
                            <time
                                datetime="{{ $entry->logged_out_at->toIso8601String() }}"
                                title="{{ $entry->logged_out_at->diffForHumans() }}"
                            >{{ app_datetime($entry->logged_out_at) }}</time>
                        @elseif ($entry->isOpen())
                            <x-ui.badge color="emerald" size="xs" :dot="true">Open</x-ui.badge>
                        @else
                            <span class="text-slate-400 dark:text-slate-600">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-right text-xs font-medium tabular-nums text-slate-700 dark:text-slate-300">
                        {{ $durations[$entry->getKey()] ?? '—' }}
                    </td>

                    <td class="whitespace-nowrap text-right text-xs text-slate-500 tabular-nums dark:text-slate-400">
                        @if ($entry->created_at)
                            <time
                                datetime="{{ $entry->created_at->toIso8601String() }}"
                                title="{{ $entry->created_at->diffForHumans() }}"
                            >{{ app_datetime($entry->created_at) }}</time>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($hasFilters)
                    <x-ui.empty-state
                        icon="funnel"
                        title="No sign-ins match those filters"
                        message="Widen the date range, clear the status or remove the IP filter."
                    >
                        <x-slot:action>
                            <x-ui.button variant="secondary" icon="x-mark" :href="url()->current()">
                                Clear filters
                            </x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="finger-print"
                        title="No authentication events yet"
                        message="Every sign-in, failed attempt, blocked account and sign-out is recorded here automatically."
                    />
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$entries" label="events" />
            </x-slot:footer>
        </x-ui.table>
    </div>
@endsection
