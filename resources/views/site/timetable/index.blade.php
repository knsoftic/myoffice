{{--
    The public class timetable — site.timetable.index. A 404 before this view when
    website.timetable_page_enabled is false.

    Controller variables (Site\TimetableController@index, through ComposesContentPages::contentPage()):
      $site       the SitePayload (seo for route_key site.timetable.index)
      $page       array{title, slug}
      $days       list<array{day: App\Enums\Weekday, slots: list<array>}> — the configured working days in the
                  configured week order, plus any day that actually has a class
      $slotCount  int, the total number of slots across every day
      $from, $to  Carbon, the window the slots were read for (today .. today + 6)

    A slot is a FLAT ARRAY and carries exactly these ten keys, every one of them already public:
      id, course_name, course_url, batch_name, starts_at, ends_at, mode, mode_label, mode_color, where

    There is no model here on purpose. No student, no roster, no enrolment or capacity count, no teacher,
    no meeting URL and no fee is loaded by the controller, so none of them can be printed by accident.

    Layout: one column per day. On a phone that is a stack of day blocks; from lg it is a real week grid
    that scrolls INSIDE its own card (overflow-x-auto + a min width on the grid) rather than pushing the
    page sideways. data-fx sits on the section heading, its paragraph and the wrapper card only — never
    on a row or a cell, because a tilting row looks broken.
--}}

@extends('site.layouts.public')

@php
    use App\Enums\Weekday;

    $days = collect($days ?? [])
        ->filter(static fn ($day): bool => ($day['day'] ?? null) instanceof Weekday)
        ->values();

    $slotCount = (int) ($slotCount ?? 0);

    $heading = $heading ?? 'Class timetable';
    $intro = $intro ?? 'Every class running this week, by day. Times are the institute’s local clock.';

    // A literal per count, so Tailwind's scanner sees each class in the source.
    $columnClass = [
        1 => 'lg:grid-cols-1',
        2 => 'lg:grid-cols-2',
        3 => 'lg:grid-cols-3',
        4 => 'lg:grid-cols-4',
        5 => 'lg:grid-cols-5',
        6 => 'lg:grid-cols-6',
        7 => 'lg:grid-cols-7',
    ][$days->count()] ?? 'lg:grid-cols-7';

    $minWidthClass = $days->count() > 4 ? 'lg:min-w-[62rem]' : 'lg:min-w-0';

    $modeClasses = [
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/20',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/20',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20',
    ];

    // Only names x-ui.icon registers. DeliveryMode::icon() names `arrows-right-left` for hybrid, which
    // the component does not carry and would render as its missing-icon placeholder.
    $modeIcons = [
        'physical' => 'building-office-2',
        'online' => 'video-camera',
        'hybrid' => 'computer-desktop',
    ];
@endphp

@section('title', $heading)

@section('content')
    @include('site.marketing.partials.page-hero', ['title' => $heading, 'subtitle' => $intro])

    <x-site.section background="surface" :label="$heading">
        <h2 data-fx="rise" class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
            This week at a glance
        </h2>
        <p data-fx="rise" data-fx-delay="1" class="mt-3 max-w-2xl text-base leading-relaxed text-slate-600 dark:text-slate-400">
            Classes run to the schedule below. Slots occasionally move, so please confirm with the office before
            travelling in for a class you have not attended before.
        </p>

        @if ($slotCount === 0 || $days->isEmpty())
            <div data-fx="rise" class="mt-10 rounded-2xl border border-dashed border-slate-300 bg-white dark:border-white/10 dark:bg-slate-900">
                <x-ui.empty-state
                    icon="calendar-days"
                    level="h3"
                    title="No classes are scheduled for this week"
                    message="The timetable for the coming week has not been published yet. Please check back shortly, or contact the office for the next start date."
                />
            </div>
        @else
            <div data-fx="rise" class="mt-10 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card dark:border-white/10 dark:bg-slate-900">
                <div class="overflow-x-auto">
                    <div class="grid grid-cols-1 gap-px bg-slate-200 {{ $columnClass }} {{ $minWidthClass }} dark:bg-white/10">
                        @foreach ($days as $column)
                            @php
                                $day = $column['day'];
                                $slots = collect($column['slots'] ?? []);
                            @endphp

                            <section class="flex min-w-0 flex-col bg-white dark:bg-slate-900" aria-labelledby="timetable-day-{{ $day->value }}">
                                <h3
                                    id="timetable-day-{{ $day->value }}"
                                    class="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold tracking-tight text-slate-900 dark:border-white/10 dark:bg-slate-800/60 dark:text-white"
                                >
                                    {{-- One spelling at every width, so the section's accessible name does
                                         not change with the viewport. --}}
                                    {{ $day->label() }}
                                    @if ($slots->isNotEmpty())
                                        <span class="ml-1 font-normal text-slate-500 dark:text-slate-400">({{ app_number($slots->count()) }})</span>
                                    @endif
                                </h3>

                                <ul role="list" class="flex-1 space-y-3 p-3">
                                    @forelse ($slots as $slot)
                                        <li class="rounded-xl border border-slate-200 bg-slate-50/60 p-3 dark:border-white/10 dark:bg-slate-800/40">
                                            <p class="flex items-center gap-1.5 text-xs font-semibold tabular-nums text-brand-700 dark:text-brand-300">
                                                <x-ui.icon name="clock" class="h-3.5 w-3.5 shrink-0" />
                                                <span>{{ app_clock($slot['starts_at']) }} – {{ app_clock($slot['ends_at']) }}</span>
                                            </p>

                                            <p class="mt-2 text-sm font-semibold leading-snug text-slate-900 dark:text-white">
                                                @if (filled($slot['course_url'] ?? null))
                                                    <a
                                                        href="{{ $slot['course_url'] }}"
                                                        class="rounded transition hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:hover:text-brand-300"
                                                    >{{ $slot['course_name'] }}</a>
                                                @else
                                                    {{ $slot['course_name'] }}
                                                @endif
                                            </p>

                                            @if (filled($slot['batch_name'] ?? null))
                                                <p class="mt-1 text-xs leading-snug text-slate-600 dark:text-slate-400">{{ $slot['batch_name'] }}</p>
                                            @endif

                                            <p class="mt-2.5 flex flex-wrap items-center gap-1.5">
                                                <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-medium ring-1 ring-inset {{ $modeClasses[$slot['mode_color'] ?? 'sky'] ?? $modeClasses['sky'] }}">
                                                    <x-ui.icon :name="$modeIcons[$slot['mode'] ?? 'physical'] ?? 'building-office-2'" class="h-3 w-3 shrink-0" />
                                                    {{ $slot['mode_label'] }}
                                                </span>

                                                @if (filled($slot['where'] ?? null))
                                                    <span class="inline-flex items-center gap-1 text-[0.6875rem] text-slate-500 dark:text-slate-400">
                                                        <x-ui.icon name="building-office" class="h-3 w-3 shrink-0" />
                                                        {{ $slot['where'] }}
                                                    </span>
                                                @endif
                                            </p>
                                        </li>
                                    @empty
                                        <li class="rounded-xl border border-dashed border-slate-200 px-3 py-6 text-center text-xs text-slate-400 dark:border-white/10 dark:text-slate-500">
                                            No classes
                                        </li>
                                    @endforelse
                                </ul>
                            </section>
                        @endforeach
                    </div>
                </div>
            </div>

            <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                Showing {{ app_number($slotCount) }} {{ $slotCount === 1 ? 'class' : 'classes' }} scheduled between
                {{ app_date($from ?? null) }} and {{ app_date($to ?? null) }}.
            </p>
        @endif
    </x-site.section>
@endsection
