{{--
    Student panel landing page — Student\DashboardController, route student.dashboard.

    Phase 1 has no institute data yet, so this page shows only what it can prove: the
    signed-in student's own identity, account state and login bookkeeping. Everything else is an
    empty state badged with the phase that builds it. No counts, no placeholders-as-numbers.
--}}

@extends('layouts.panel')

@section('title', 'Dashboard')

@section('header')
    <x-ui.page-header
        title="Student dashboard"
        subtitle="Your course, batch, timetable, fees and results will live here."
        icon="academic-cap"
        :badge="$panel->label().' portal'"
        :badge-color="$panel->color()"
    >
        <x-slot:actions>
            @if ($accountLinks['profile'])
                <x-ui.button variant="secondary" icon="user" :href="$accountLinks['profile']">Profile</x-ui.button>
            @endif

            @if ($accountLinks['sessions'])
                <x-ui.button variant="ghost" icon="finger-print" :href="$accountLinks['sessions']">Sessions</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @php
        // The zone app_datetime() renders in (the viewer's own, else localization.timezone), so the
        // "Times shown in" row always names the zone the times on this page are actually in.
        $timezone = \App\Support\Format::displayTimezone();

        // Date and time in the configured localization formats; a missing stamp reads as a dash.
        $formatDate = static fn (mixed $date): string => app_datetime($date) ?: '—';

        // Honest fallbacks: the login history row for this session, otherwise the stamp the
        // login listener wrote on the user row.
        $signedInAt = $currentLogin?->logged_in_at ?? $currentLogin?->created_at ?? $user->last_login_at;
        $signedInIp = $currentLogin?->ip_address ?? $user->last_login_ip;
        $signedInDevice = $currentLogin?->deviceLabel();
        $previousAt = $previousLogin?->logged_in_at ?? $previousLogin?->created_at;

        // What each later phase adds to this panel (DEVELOPMENT_LOG.md §5 phase tracker).
        $roadmap = [
            [
                'icon' => 'academic-cap',
                'title' => 'My course',
                'message' => 'The course you are enrolled in, with its outline: modules, topics and lectures.',
                'phase' => 'Phase 14',
            ],
            [
                'icon' => 'user-group',
                'title' => 'My batch',
                'message' => 'The batch you are placed in, who teaches it and where it meets.',
                'phase' => 'Phase 16',
            ],
            [
                'icon' => 'calendar-days',
                'title' => 'Timetable',
                'message' => 'Class days and times for your batch, plus your attendance and progress.',
                'phase' => 'Phases 16–17',
            ],
            [
                'icon' => 'banknotes',
                'title' => 'Fees',
                'message' => 'Your fee plan, installments, receipts and anything still outstanding.',
                'phase' => 'Phase 18',
            ],
            [
                'icon' => 'trophy',
                'title' => 'Results',
                'message' => 'Assignment grades, exam marks and your certificate once the course completes.',
                'phase' => 'Phases 19–21',
            ],
        ];
    @endphp

    <div class="space-y-6">
        {{-- Who you are --}}
        <x-ui.card>
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-4">
                    <x-ui.avatar
                        :src="$user->avatar_url"
                        :name="$user->name"
                        size="xl"
                        :ring="true"
                        :status="$user->status->color()"
                    />

                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            {{ $greeting }}
                        </p>

                        <h2 class="truncate text-lg font-semibold tracking-tight text-slate-900 sm:text-xl dark:text-white">
                            {{ $user->name }}
                        </h2>

                        <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ $user->email }}</p>

                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <x-ui.badge :color="$user->status->color()" :dot="true" size="sm">
                                {{ $user->status->label() }}
                            </x-ui.badge>

                            @foreach ($roleChips as $chip)
                                <x-ui.badge :color="$panel->color()" variant="outline" size="sm">{{ $chip }}</x-ui.badge>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="shrink-0 rounded-lg bg-slate-50 px-4 py-3 text-sm ring-1 ring-inset ring-slate-200/80 dark:bg-slate-800/50 dark:ring-slate-700/60">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Signed in</p>
                    <p class="mt-1 font-medium text-slate-900 dark:text-white">{{ $formatDate($signedInAt) }}</p>
                    @if (filled($signedInDevice))
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $signedInDevice }}</p>
                    @endif
                </div>
            </div>

            @if (filled($user->status_reason))
                <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                    {{ $user->status_reason }}
                </p>
            @endif
        </x-ui.card>

        {{-- Your account — every value below comes from your own row, nobody else's --}}
        <x-ui.card title="Your account" subtitle="Only your own records are ever shown in this panel." icon="shield-check">
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Status</dt>
                    <dd class="mt-1">
                        <x-ui.badge :color="$user->status->color()" :dot="true" size="sm">{{ $user->status->label() }}</x-ui.badge>
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Previous sign-in</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                        {{ $previousAt ? $formatDate($previousAt) : 'This is your first sign-in' }}
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Last IP address</dt>
                    <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">{{ $signedInIp ?: '—' }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Active sessions</dt>
                    <dd class="mt-1 text-sm tabular-nums text-slate-900 dark:text-white">
                        {{ $sessionCount }} {{ \Illuminate\Support\Str::plural('device', $sessionCount) }}
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Member since</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">
                        {{ app_date($user->created_at) ?: '—' }}
                    </dd>
                </div>

                <div>
                    <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Times shown in</dt>
                    <dd class="mt-1 text-sm text-slate-900 dark:text-white">{{ $timezone }}</dd>
                </div>
            </dl>

            <x-slot:footer>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($accountLinks['profile'])
                        <x-ui.button size="sm" variant="secondary" icon="user" :href="$accountLinks['profile']">Profile</x-ui.button>
                    @endif

                    @if ($accountLinks['password'])
                        <x-ui.button size="sm" variant="ghost" icon="key" :href="$accountLinks['password']">Password</x-ui.button>
                    @endif

                    @if ($accountLinks['sessions'])
                        <x-ui.button size="sm" variant="ghost" icon="finger-print" :href="$accountLinks['sessions']">Sessions</x-ui.button>
                    @endif
                </div>
            </x-slot:footer>
        </x-ui.card>

        {{-- What is still to come. Empty states, never invented numbers. --}}
        <div>
            <x-ui.section-heading
                title="Coming to this panel"
                subtitle="Admission and enrolment are wired up in phase 15; each card below arrives with the phase that builds it."
                icon="sparkles"
                :divider="true"
                class="mb-4"
            />

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($roadmap as $item)
                    <x-ui.card :padded="false" class="h-full">
                        <x-ui.empty-state
                            :icon="$item['icon']"
                            :title="$item['title']"
                            :message="$item['message']"
                            :compact="true"
                        >
                            <x-slot:action>
                                <x-ui.badge color="slate" size="sm" icon="clock">{{ $item['phase'] }}</x-ui.badge>
                            </x-slot:action>
                        </x-ui.empty-state>
                    </x-ui.card>
                @endforeach
            </div>
        </div>
    </div>
@endsection
