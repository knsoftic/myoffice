@extends('layouts.admin')

@section('title', 'Activity log')

{{--
    Activity log index (route admin.activity-log.index).

    Filters, sort and pagination all live in the query string and are validated by
    App\Http\Requests\Admin\LogFilterRequest before they reach a query.

    Formatting: the visible timestamp goes through app_datetime() and the entry count through
    app_number() (D61: stored timestamps are UTC, the helpers render in the display timezone). The
    only raw call left is toIso8601String() inside <time datetime="…"> — the machine-readable HTML
    value, offset included, never shown to the reader.
--}}

@php
    use Illuminate\Support\Str;

    $deviceIcon = static fn (?string $device): string => match (strtolower((string) $device)) {
        'mobile' => 'phone',
        'tablet', 'desktop' => 'computer-desktop',
        'bot' => 'server-stack',
        default => 'question-mark-circle',
    };

    $moduleLabel = static fn (?string $slug) => $slug === null || $slug === ''
        ? null
        : ($moduleNames[$slug] ?? Str::headline($slug));

    $hasShowRoute = \Illuminate\Support\Facades\Route::has('admin.activity-log.show');

    $perPageOptions = collect(\App\Http\Requests\Admin\LogFilterRequest::perPageOptions())
        ->mapWithKeys(static fn (int $size): array => [(string) $size => $size.' / page'])
        ->all();
@endphp

@section('header')
    <x-ui.page-header
        title="Activity log"
        subtitle="Every create, update and delete recorded across the system, with the actor, the old and new values and the request context."
        icon="clipboard-document-list"
        :badge="app_number($entries->total()).' entries'"
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
        `navigating` drives the table's skeleton rows: the filter bar submits itself on every
        select change and 400ms after the last keystroke, and this log is the slowest list in the
        admin (seven filters over a table that only grows), so the rows on screen must stop
        looking current the moment a filter changes. GET submissions only — see x-ui.table.
    --}}
    <div
        x-data="{ navigating: false }"
        x-on:submit.window="if ($event.target?.method === 'get') navigating = true"
        class="space-y-4"
    >

        <x-ui.filter-bar placeholder="Search descriptions, events, reasons…">
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
                name="module"
                :options="$moduleOptions"
                :selected="request('module')"
                placeholder="Any module"
                size="sm"
                icon="puzzle-piece"
                aria-label="Filter by module"
            />

            <x-ui.form.select
                name="event"
                :options="$eventOptions"
                :selected="request('event')"
                placeholder="Any event"
                size="sm"
                icon="sparkles"
                aria-label="Filter by event"
            />

            <x-ui.form.select
                name="subject_type"
                :options="$subjectOptions"
                :selected="request('subject_type')"
                placeholder="Any record type"
                size="sm"
                icon="rectangle-stack"
                aria-label="Filter by record type"
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
                <th class="px-4 py-3">User</th>

                <x-ui.th-sortable column="description" :sort="$sort" :direction="$direction">
                    What happened
                </x-ui.th-sortable>

                <x-ui.th-sortable column="module" :sort="$sort" :direction="$direction">
                    Module
                </x-ui.th-sortable>

                <th class="px-4 py-3">Record</th>
                <th class="px-4 py-3">IP</th>
                <th class="px-4 py-3">Device</th>

                <x-ui.th-sortable column="created_at" :sort="$sort" :direction="$direction" default="desc" align="right">
                    When
                </x-ui.th-sortable>

                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($entries as $entry)
                <tr>
                    <td class="whitespace-nowrap">
                        @if ($entry->causer instanceof \App\Models\User)
                            <div class="flex items-center gap-2.5">
                                <x-ui.avatar :src="$entry->causer->avatar_url" :name="$entry->causer->name" size="sm" />
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $entry->causer->name }}</p>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $entry->causer->email }}</p>
                                </div>
                            </div>
                        @elseif (filled($entry->causer_type))
                            <span class="inline-flex items-center gap-2 text-slate-500 dark:text-slate-400">
                                <x-ui.icon name="user-circle" class="h-4 w-4" />
                                {{ Str::headline(class_basename((string) $entry->causer_type)) }}
                                @if ($entry->causer_id) #{{ $entry->causer_id }} @endif
                            </span>
                        @else
                            <span class="inline-flex items-center gap-2 text-slate-500 dark:text-slate-400">
                                <x-ui.icon name="cog-6-tooth" class="h-4 w-4" />
                                System
                            </span>
                        @endif
                    </td>

                    <td class="max-w-sm">
                        @if ($hasShowRoute)
                            <a
                                href="{{ route('admin.activity-log.show', $entry->getKey()) }}"
                                class="block truncate font-medium text-slate-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                                title="{{ $entry->description }}"
                            >{{ $entry->description }}</a>
                        @else
                            <span class="block truncate font-medium text-slate-900 dark:text-white" title="{{ $entry->description }}">
                                {{ $entry->description }}
                            </span>
                        @endif

                        <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                            @if (filled($entry->event))
                                <span class="font-medium">{{ Str::headline((string) $entry->event) }}</span>
                            @endif

                            @if (filled($entry->log_name))
                                <span class="text-slate-300 dark:text-slate-700">·</span>
                                <span>{{ $entry->log_name }}</span>
                            @endif

                            @if (filled($entry->reason))
                                <span class="text-slate-300 dark:text-slate-700">·</span>
                                <span class="truncate italic" title="{{ $entry->reason }}">{{ Str::limit((string) $entry->reason, 60) }}</span>
                            @endif
                        </div>
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($label = $moduleLabel($entry->module))
                            <x-ui.badge color="slate" size="sm">{{ $label }}</x-ui.badge>
                        @else
                            <span class="text-slate-400 dark:text-slate-600">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-xs">
                        @if (filled($entry->subject_type))
                            <span class="font-medium text-slate-700 dark:text-slate-300">
                                {{ Str::headline(class_basename((string) $entry->subject_type)) }}
                            </span>
                            @if ($entry->subject_id)
                                <span class="text-slate-400 tabular-nums dark:text-slate-500">#{{ $entry->subject_id }}</span>
                            @endif
                        @else
                            <span class="text-slate-400 dark:text-slate-600">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-xs tabular-nums text-slate-600 dark:text-slate-300">
                        {{ $entry->ip_address ?? '—' }}
                    </td>

                    <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                        @if (filled($entry->device))
                            <span class="inline-flex items-center gap-1.5" title="{{ $entry->user_agent }}">
                                <x-ui.icon :name="$deviceIcon($entry->device)" class="h-3.5 w-3.5" />
                                {{ Str::headline((string) $entry->device) }}
                            </span>
                        @else
                            <span class="text-slate-400 dark:text-slate-600">—</span>
                        @endif
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

                    <td class="whitespace-nowrap text-right">
                        @if ($hasShowRoute)
                            <x-ui.icon-button
                                icon="eye"
                                label="View entry"
                                :href="route('admin.activity-log.show', $entry->getKey())"
                            />
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($hasFilters)
                    <x-ui.empty-state
                        icon="funnel"
                        title="No entries match those filters"
                        message="Widen the date range or clear the filters to see the whole log."
                    >
                        <x-slot:action>
                            <x-ui.button variant="secondary" icon="x-mark" :href="url()->current()">
                                Clear filters
                            </x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="clipboard-document-list"
                        title="Nothing has been logged yet"
                        message="Creates, updates and deletes are recorded automatically, with the actor and the values that changed."
                    />
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$entries" label="entries" />
            </x-slot:footer>
        </x-ui.table>
    </div>
@endsection
