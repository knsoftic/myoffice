@extends('layouts.admin')

@section('title', 'Dashboard')

{{--
    Admin dashboard (route admin.dashboard).

    Every figure is a live query from App\Http\Controllers\Admin\DashboardController; every block
    is already permission-filtered there, so this file only decides how things look. Phase 2
    replaces the fixed grid with the widget framework.
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
@endphp

@section('header')
    <x-ui.page-header
        title="Dashboard"
        :subtitle="$today->format('l, j F Y').' · times shown in '.$timezone"
        icon="home"
    >
        <x-slot:actions>
            @if ($activityIndexUrl && $permissions['activity'])
                <x-ui.button variant="secondary" size="sm" icon="clipboard-document-list" :href="$activityIndexUrl">
                    Activity log
                </x-ui.button>
            @endif

            @if ($loginIndexUrl && $permissions['logins'])
                <x-ui.button variant="secondary" size="sm" icon="finger-print" :href="$loginIndexUrl">
                    Login history
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="space-y-6">

        {{-- ── KPIs ──────────────────────────────────────────────────────────────────── --}}
        @if (! empty($stats))
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($stats as $card)
                    <x-ui.stat-card
                        :label="$card['label']"
                        :value="$card['value']"
                        :icon="$card['icon']"
                        :color="$card['color']"
                        :href="$card['href']"
                        :delta-label="$card['deltaLabel']"
                    />
                @endforeach
            </div>
        @elseif (! $hasAnyBlock)
            <x-ui.card>
                <x-ui.empty-state
                    icon="lock-closed"
                    title="Nothing to show yet"
                    message="Your role does not include any of the system permissions this dashboard reports on. Ask an administrator if you expected figures here."
                />
            </x-ui.card>
        @endif

        {{-- ── Recent events + system health ─────────────────────────────────────────── --}}
        @if ($permissions['activity'] || $permissions['logins'] || ! empty($health))
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">

                <div class="space-y-6 xl:col-span-2">

                    @if ($permissions['activity'] && $recentActivity !== null)
                        <x-ui.card
                            title="Recent activity"
                            :subtitle="$recentActivity->count().' most recent entries of the audit trail'"
                            icon="clipboard-document-list"
                            :padded="false"
                        >
                            @if ($activityIndexUrl)
                                <x-slot:actions>
                                    <x-ui.button variant="ghost" size="sm" icon-trailing="arrow-right" :href="$activityIndexUrl">
                                        View all
                                    </x-ui.button>
                                </x-slot:actions>
                            @endif

                            <x-ui.table :is-empty="$recentActivity->isEmpty()" dense :flush="true">
                                <x-slot:head>
                                    <th class="px-4 py-3">User</th>
                                    <th class="px-4 py-3">What happened</th>
                                    <th class="px-4 py-3">Module</th>
                                    <th class="px-4 py-3 text-right">When</th>
                                </x-slot:head>

                                @foreach ($recentActivity as $entry)
                                    <tr>
                                        <td class="whitespace-nowrap">
                                            @if ($entry->causer instanceof \App\Models\User)
                                                <div class="flex items-center gap-2.5">
                                                    <x-ui.avatar :src="$entry->causer->avatar_url" :name="$entry->causer->name" size="sm" />
                                                    <span class="truncate font-medium text-slate-900 dark:text-white">{{ $entry->causer->name }}</span>
                                                </div>
                                            @else
                                                <span class="inline-flex items-center gap-2 text-slate-500 dark:text-slate-400">
                                                    <x-ui.icon name="cog-6-tooth" class="h-4 w-4" />
                                                    System
                                                </span>
                                            @endif
                                        </td>

                                        <td class="max-w-xs">
                                            @php
                                                $showUrl = \Illuminate\Support\Facades\Route::has('admin.activity-log.show')
                                                    ? route('admin.activity-log.show', $entry->getKey())
                                                    : null;
                                            @endphp

                                            @if ($showUrl)
                                                <a
                                                    href="{{ $showUrl }}"
                                                    class="block truncate font-medium text-slate-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                                                    title="{{ $entry->description }}"
                                                >{{ $entry->description }}</a>
                                            @else
                                                <span class="block truncate font-medium text-slate-900 dark:text-white" title="{{ $entry->description }}">
                                                    {{ $entry->description }}
                                                </span>
                                            @endif

                                            @if (filled($entry->event))
                                                <span class="text-xs text-slate-500 dark:text-slate-400">{{ Str::headline((string) $entry->event) }}</span>
                                            @endif
                                        </td>

                                        <td class="whitespace-nowrap">
                                            @if ($label = $moduleLabel($entry->module))
                                                <x-ui.badge color="slate" size="sm">{{ $label }}</x-ui.badge>
                                            @else
                                                <span class="text-slate-400 dark:text-slate-600">—</span>
                                            @endif
                                        </td>

                                        <td class="whitespace-nowrap text-right text-xs text-slate-500 tabular-nums dark:text-slate-400">
                                            @if ($entry->created_at)
                                                <time
                                                    datetime="{{ $entry->created_at->toIso8601String() }}"
                                                    title="{{ $entry->created_at->diffForHumans() }}"
                                                >{{ $entry->created_at->timezone($timezone)->format('d M H:i') }}</time>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach

                                <x-slot:empty>
                                    <x-ui.empty-state
                                        icon="clipboard-document-list"
                                        title="No activity recorded yet"
                                        message="Entries appear here as soon as someone creates, edits or deletes a record."
                                        :compact="true"
                                    />
                                </x-slot:empty>
                            </x-ui.table>
                        </x-ui.card>
                    @endif

                    @if ($permissions['logins'] && $recentLogins !== null)
                        <x-ui.card
                            title="Recent logins"
                            :subtitle="$recentLogins->count().' most recent authentication events'"
                            icon="finger-print"
                            :padded="false"
                        >
                            @if ($loginIndexUrl)
                                <x-slot:actions>
                                    <x-ui.button variant="ghost" size="sm" icon-trailing="arrow-right" :href="$loginIndexUrl">
                                        View all
                                    </x-ui.button>
                                </x-slot:actions>
                            @endif

                            <x-ui.table :is-empty="$recentLogins->isEmpty()" dense :flush="true">
                                <x-slot:head>
                                    <th class="px-4 py-3">User</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">IP · device</th>
                                    <th class="px-4 py-3 text-right">When</th>
                                </x-slot:head>

                                @foreach ($recentLogins as $entry)
                                    <tr @class([
                                        'bg-rose-50/60 dark:bg-rose-500/5' => $entry->status === \App\Enums\LoginStatus::Failed,
                                        'bg-amber-50/60 dark:bg-amber-500/5' => $entry->status === \App\Enums\LoginStatus::Blocked,
                                    ])>
                                        <td class="whitespace-nowrap">
                                            @if ($entry->user)
                                                <div class="flex items-center gap-2.5">
                                                    <x-ui.avatar :src="$entry->user->avatar_url" :name="$entry->user->name" size="sm" />
                                                    <span class="truncate font-medium text-slate-900 dark:text-white">{{ $entry->user->name }}</span>
                                                </div>
                                            @else
                                                <span class="truncate text-slate-500 dark:text-slate-400">{{ $entry->email ?? 'Unknown' }}</span>
                                            @endif
                                        </td>

                                        <td class="whitespace-nowrap">
                                            <x-ui.badge :color="$entry->status->color()" size="sm" :dot="true">
                                                {{ $entry->status->label() }}
                                            </x-ui.badge>
                                        </td>

                                        <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                                            <span class="font-medium tabular-nums text-slate-700 dark:text-slate-300">{{ $entry->ip_address ?? '—' }}</span>
                                            <span class="mx-1 text-slate-300 dark:text-slate-700">·</span>
                                            <span class="inline-flex items-center gap-1">
                                                <x-ui.icon :name="$deviceIcon($entry->device)" class="h-3.5 w-3.5" />
                                                {{ $entry->deviceLabel() }}
                                            </span>
                                        </td>

                                        <td class="whitespace-nowrap text-right text-xs text-slate-500 tabular-nums dark:text-slate-400">
                                            @if ($entry->created_at)
                                                <time
                                                    datetime="{{ $entry->created_at->toIso8601String() }}"
                                                    title="{{ $entry->created_at->diffForHumans() }}"
                                                >{{ $entry->created_at->timezone($timezone)->format('d M H:i') }}</time>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach

                                <x-slot:empty>
                                    <x-ui.empty-state
                                        icon="finger-print"
                                        title="No sign-ins recorded yet"
                                        message="Successful, failed and blocked attempts are all recorded here."
                                        :compact="true"
                                    />
                                </x-slot:empty>
                            </x-ui.table>
                        </x-ui.card>
                    @endif
                </div>

                @if (! empty($health))
                    <div class="xl:col-span-1">
                        <x-ui.card title="System health" subtitle="This installation, right now" icon="server-stack">
                            <dl class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($health as $row)
                                    <div class="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0">
                                        <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                            <x-ui.icon :name="$row['icon']" class="h-4 w-4" />
                                        </span>

                                        <div class="min-w-0 flex-1">
                                            <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                                {{ $row['label'] }}
                                            </dt>
                                            <dd class="truncate text-sm font-semibold text-slate-900 dark:text-white" title="{{ $row['value'] }}">
                                                {{ $row['value'] }}
                                            </dd>
                                            @if (filled($row['meta']))
                                                <dd class="truncate text-xs text-slate-400 dark:text-slate-500" title="{{ $row['meta'] }}">
                                                    {{ $row['meta'] }}
                                                </dd>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </dl>
                        </x-ui.card>
                    </div>
                @endif
            </div>
        @endif

        {{-- ── What lands in later phases ────────────────────────────────────────────── --}}
        <div class="space-y-4">
            <x-ui.section-heading
                title="Dashboards arriving in later phases"
                subtitle="Placeholders, not charts: these panels stay empty until the phase that owns the data is built."
                icon="sparkles"
                :divider="true"
            />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($upcoming as $card)
                    <x-ui.card class="h-full">
                        <x-ui.empty-state
                            :icon="$card['icon']"
                            :title="$card['title']"
                            :message="$card['message']"
                            :compact="true"
                        >
                            @if (filled($card['badge']))
                                <x-slot:action>
                                    <x-ui.badge :color="$card['color']" size="sm">{{ $card['badge'] }}</x-ui.badge>
                                </x-slot:action>
                            @endif
                        </x-ui.empty-state>
                    </x-ui.card>
                @endforeach
            </div>
        </div>
    </div>
@endsection
