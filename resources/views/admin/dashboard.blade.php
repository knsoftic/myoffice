@extends('layouts.admin')

@section('title', 'Dashboard')

{{--
    Admin dashboard (route admin.dashboard) — the widget grid.

    This file renders whatever `DashboardRegistry` hands the controller and knows nothing about any
    individual card. A later phase adds a dashboard card by shipping a widget class and its body
    view; **nothing in this file changes.** If you find yourself wanting to name a widget key here,
    the widget needs another declarative hook instead (see App\Dashboard\Widget).

    Everything interactive is one Alpine component, `adminDashboard`, registered in
    admin/dashboard/partials/scripts.blade.php. The toolbar lives inside that component's scope
    rather than in @section('header'), because the layout renders the header region as a sibling of
    the content region — an Alpine scope cannot span the two.
--}}

@php
    $widgetCount = $visible->count();
    $hiddenCount = $hidden->count();

    // Dates render through the Format helpers (D61: storage is UTC, localization.timezone is display
    // only), so the date follows localization.date_format and the timezone named beside it is the one
    // app_date()/app_datetime() actually render in. The weekday name is the only fixed token: no
    // localization setting governs it, and app_date() still moves it into the display timezone.
    $headerDate = app_date($today, 'l').', '.app_date($today);
    $displayTimezone = \App\Support\Format::displayTimezone();
@endphp

@section('header')
    <x-ui.page-header
        title="Dashboard"
        :subtitle="$headerDate.' · times shown in '.$displayTimezone"
        icon="home"
        :badge="$range->label()"
        badge-color="brand"
    />
@endsection

@section('content')
    <div
        x-data="adminDashboard({
            widgetUrl: @js($widgetUrlTemplate),
            layoutUrl: @js($layoutUrl),
            order: @js($available->keys()->values()->all()),
            defaultOrder: @js($defaultOrder),
            hidden: @js($layout->hidden),
        })"
        class="space-y-6 pb-28"
    >
        {{-- ── Toolbar: the global range selector + customise mode ───────────────────────── --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            @include('admin.dashboard.partials.range')

            <div class="flex shrink-0 items-center gap-2">
                @if ($widgetUrlTemplate)
                    <x-ui.button
                        variant="ghost"
                        size="sm"
                        icon="arrow-path"
                        data-requires-js
                        x-on:click="refreshAll()"
                        x-bind:disabled="busy"
                        title="Re-run every card's query"
                    >
                        Refresh
                    </x-ui.button>
                @endif

                @if ($canCustomise)
                    <x-ui.button
                        variant="secondary"
                        size="sm"
                        icon="adjustments-horizontal"
                        data-requires-js
                        x-on:click="toggleCustomising()"
                        x-bind:aria-pressed="customising ? 'true' : 'false'"
                        x-bind:class="customising ? 'ring-2 ring-brand-500/40' : ''"
                    >
                        <span x-text="customising ? 'Done' : 'Customise'">Customise</span>
                    </x-ui.button>
                @endif
            </div>
        </div>

        @if ($visible->isEmpty() && $hidden->isEmpty())
            {{-- No permission for any registered widget: say so plainly rather than show chrome. --}}
            <x-ui.card>
                <x-ui.empty-state
                    icon="lock-closed"
                    title="Nothing to show yet"
                    message="Your role does not include any of the permissions this dashboard reports on. Ask an administrator if you expected figures here."
                />
            </x-ui.card>
        @else
            @include('admin.dashboard.partials.customise-bar')

            @include('admin.dashboard.partials.grid')

            <p class="text-xs text-slate-400 dark:text-slate-500">
                <span x-text="visibleCount()">{{ $widgetCount }}</span>
                of {{ app_number($available->count()) }} {{ \Illuminate\Support\Str::plural('card', $available->count()) }} shown ·
                every figure is a live query for {{ $range->label() }} ·
                your arrangement is saved to your account only
            </p>
        @endif

        @include('admin.dashboard.partials.scripts')
    </div>
@endsection
