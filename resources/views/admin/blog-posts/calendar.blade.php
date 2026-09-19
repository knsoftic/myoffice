@extends('layouts.admin')

@section('title', 'Blog calendar')

{{--
    Blog calendar — admin.blog-posts.calendar (phase-04 §8.7 "Calendar", R10). A month grid in Alpine, no library.

    Controller variables (Admin\BlogPostController@calendar) — the query goes through BlogPost::visibleTo($user):
      $month          string 'Y-m' in the display timezone (?month=2026-10; default the current month)
      $posts          Collection<App\Models\Cms\BlogPost> for the visible grid (the month plus the leading and trailing
                      days of its weeks): scheduled and published rows by published_at, drafts by updated_at;
                      author eager-loaded
      $weekStartsOn   optional int (Carbon day of week) — Format::weekStartsOn() when absent

    Writes: POST admin.blog-posts.schedule {post} as JSON {published_at: 'Y-m-d\TH:i'} (display timezone) when a
    scheduled chip is dropped on another day. Drag is offered only where changeStatus is allowed for that post; a
    past day is refused here with a toast and again by ScheduleBlogPostRequest (after:now). The editor's
    Schedule button does the same job without JavaScript.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use App\Support\Format;
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $allowsChange = static fn ($post): bool => Gate::getPolicyFor($post) !== null
        ? Gate::forUser($user)->allows('changeStatus', $post)
        : (bool) $user?->can('blog_posts.change_status');

    $monthKey = is_string($month ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : app_date(now(), 'Y-m');
    $monthStart = Format::carbon($monthKey.'-01');
    $weekStart = (int) ($weekStartsOn ?? Format::weekStartsOn());
    $gridStart = $monthStart->startOfWeek($weekStart);
    $leading = (int) $gridStart->diffInDays($monthStart);
    $weeks = (int) ceil(($leading + $monthStart->daysInMonth) / 7);
    $todayKey = app_date(now(), 'Y-m-d');

    $posts = collect($posts ?? []);
    $byDay = $posts->groupBy(static function ($post): string {
        $status = $post->status instanceof \BackedEnum ? $post->status->value : (string) $post->status;
        $moment = in_array($status, [ContentStatus::Scheduled->value, ContentStatus::Published->value, ContentStatus::Archived->value], true) && $post->published_at
            ? $post->published_at
            : $post->updated_at;

        return Format::instantDate($moment, 'Y-m-d');
    });

    $chipTone = [
        ContentStatus::Scheduled->value => 'bg-amber-50 text-amber-800 ring-amber-200 hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30 dark:hover:bg-amber-500/20',
        ContentStatus::Published->value => 'bg-emerald-50 text-emerald-800 ring-emerald-200 hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/30 dark:hover:bg-emerald-500/20',
        ContentStatus::Draft->value => 'bg-slate-100 text-slate-700 ring-slate-200 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-700',
        ContentStatus::Archived->value => 'bg-rose-50 text-rose-700 ring-rose-200 hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/30 dark:hover:bg-rose-500/20',
    ];
    $canSchedule = Route::has('admin.blog-posts.schedule');
@endphp

@section('header')
    <x-ui.page-header title="Blog calendar" subtitle="Scheduled and published posts on their publish day; drafts on the day they were last edited." icon="calendar-days" :back="route('admin.blog-posts.index')">
        <x-slot:actions>
            @can('blog_posts.create')
                <x-ui.button icon="plus" :href="route('admin.blog-posts.create')">New post</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div
        x-data="{
            today: @js($todayKey),
            dragging: null,
            over: null,
            saving: false,
            toast(type, message) { window.Alpine?.store('toasts')?.push({ type, message }); },
            start(event, url, time) {
                this.dragging = { url, time };
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', url);
            },
            async drop(day) {
                const move = this.dragging;
                this.dragging = null;
                this.over = null;
                if (! move || this.saving) return;
                if (day < this.today) {
                    this.toast('error', 'A post cannot be scheduled on a day that has passed.');
                    return;
                }
                this.saving = true;
                try {
                    const response = await fetch(move.url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ published_at: day + 'T' + move.time }),
                    });
                    const body = await response.json().catch(() => ({}));
                    if (! response.ok) {
                        throw new Error((body.errors && Object.values(body.errors).flat()[0]) || body.message || 'The post was not rescheduled.');
                    }
                    this.toast('success', body.message || 'Post rescheduled.');
                    setTimeout(() => window.location.reload(), 600);
                } catch (error) {
                    this.toast('error', error.message);
                } finally {
                    this.saving = false;
                }
            },
        }"
        class="space-y-4"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2">
                <x-ui.icon-button icon="chevron-left" variant="secondary" label="Previous month" :href="route('admin.blog-posts.calendar', ['month' => app_date($monthStart->subMonth(), 'Y-m')])" />
                <h2 class="min-w-[10rem] text-center text-lg font-semibold tracking-tight text-slate-900 dark:text-white">{{ app_date($monthStart, 'F Y') }}</h2>
                <x-ui.icon-button icon="chevron-right" variant="secondary" label="Next month" :href="route('admin.blog-posts.calendar', ['month' => app_date($monthStart->addMonth(), 'Y-m')])" />
                @if ($monthKey !== app_date(now(), 'Y-m'))
                    <x-ui.button variant="ghost" size="sm" :href="route('admin.blog-posts.calendar')">Today</x-ui.button>
                @endif
            </div>

            <ul class="flex flex-wrap items-center gap-3 text-xs text-slate-600 dark:text-slate-300" aria-label="Legend">
                <li class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-amber-500" aria-hidden="true"></span> Scheduled (drag to move)</li>
                <li class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500" aria-hidden="true"></span> Published</li>
                <li class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-slate-400" aria-hidden="true"></span> Draft (last edited)</li>
                <li class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-rose-500" aria-hidden="true"></span> Archived</li>
            </ul>
        </div>

        <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800">
            <div class="min-w-[48rem]" role="grid" aria-label="{{ app_date($monthStart, 'F Y') }}" x-bind:aria-busy="saving ? 'true' : 'false'">
                <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50/80 dark:border-slate-800 dark:bg-slate-900/60" role="row">
                    @for ($d = 0; $d < 7; $d++)
                        <div class="px-2 py-2 text-center text-2xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400" role="columnheader">{{ app_date($gridStart->addDays($d), 'D') }}</div>
                    @endfor
                </div>

                @for ($w = 0; $w < $weeks; $w++)
                    <div class="grid grid-cols-7" role="row">
                        @for ($d = 0; $d < 7; $d++)
                            @php
                                $day = $gridStart->addDays($w * 7 + $d);
                                $dayKey = app_date($day, 'Y-m-d');
                                $inMonth = $day->month === $monthStart->month;
                                $isToday = $dayKey === $todayKey;
                                $isPast = $dayKey < $todayKey;
                                $dayPosts = $byDay->get($dayKey, collect());
                            @endphp
                            <div
                                role="gridcell"
                                x-on:dragover.prevent="if (dragging) over = @js($dayKey)"
                                x-on:dragleave="if (over === @js($dayKey)) over = null"
                                x-on:drop.prevent="drop(@js($dayKey))"
                                x-bind:class="over === @js($dayKey) ? @js($isPast ? 'ring-2 ring-inset ring-rose-400' : 'ring-2 ring-inset ring-brand-500 bg-brand-50/60 dark:bg-brand-500/10') : ''"
                                @class([
                                    'min-h-[7rem] border-b border-r border-slate-100 p-1.5 align-top dark:border-slate-800',
                                    'bg-white dark:bg-slate-900' => $inMonth,
                                    'bg-slate-50/70 dark:bg-slate-950/40' => ! $inMonth,
                                ])
                            >
                                <div class="mb-1 flex items-center justify-between">
                                    <span @class([
                                        'inline-flex h-6 min-w-[1.5rem] items-center justify-center rounded-full px-1 text-xs tabular-nums',
                                        'bg-brand-600 font-semibold text-white dark:bg-brand-500' => $isToday,
                                        'text-slate-700 dark:text-slate-200' => ! $isToday && $inMonth,
                                        'text-slate-400 dark:text-slate-600' => ! $isToday && ! $inMonth,
                                    ])>{{ $day->day }}</span>
                                    @if ($isToday)
                                        <span class="text-2xs font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-400">Today</span>
                                    @endif
                                </div>

                                <ul class="space-y-1">
                                    @foreach ($dayPosts->take(4) as $post)
                                        @php
                                            $statusValue = $post->status instanceof \BackedEnum ? $post->status->value : (string) $post->status;
                                            $draggable = $canSchedule && $statusValue === ContentStatus::Scheduled->value && $allowsChange($post);
                                            $timeLabel = in_array($statusValue, [ContentStatus::Scheduled->value, ContentStatus::Published->value], true) && $post->published_at ? app_time($post->published_at) : null;
                                        @endphp
                                        <li>
                                            <a
                                                href="{{ route('admin.blog-posts.edit', $post) }}"
                                                @if ($draggable)
                                                    draggable="true"
                                                    x-on:dragstart="start($event, @js(route('admin.blog-posts.schedule', $post)), @js(app_time($post->published_at, 'H:i')))"
                                                    x-on:dragend="dragging = null; over = null"
                                                    title="Drag to another day to reschedule"
                                                @endif
                                                @class([
                                                    'block truncate rounded-md px-1.5 py-1 text-2xs font-medium ring-1 ring-inset transition',
                                                    $chipTone[$statusValue] ?? $chipTone[ContentStatus::Draft->value],
                                                    'cursor-grab active:cursor-grabbing' => $draggable,
                                                ])
                                            >
                                                @if ($timeLabel)
                                                    <span class="tabular-nums opacity-70">{{ $timeLabel }}</span>
                                                @endif
                                                {{ $post->title }}
                                                <span class="sr-only">({{ $statusValue }})</span>
                                            </a>
                                        </li>
                                    @endforeach
                                    @if ($dayPosts->count() > 4)
                                        <li class="px-1.5 text-2xs text-slate-500 dark:text-slate-400">+{{ $dayPosts->count() - 4 }} more</li>
                                    @endif
                                </ul>
                            </div>
                        @endfor
                    </div>
                @endfor
            </div>
        </div>

        @if ($posts->isEmpty())
            <x-ui.empty-state icon="calendar-days" title="Nothing on the calendar this month" message="Schedule a post from its editor and it appears here." :compact="true">
                @can('blog_posts.create')
                    <x-slot:action>
                        <x-ui.button icon="plus" :href="route('admin.blog-posts.create')">New post</x-ui.button>
                    </x-slot:action>
                @endcan
            </x-ui.empty-state>
        @endif

        <p class="text-xs text-slate-500 dark:text-slate-400">
            Times are shown in {{ Format::displayTimezone() }}. Dropping a scheduled post on another day keeps its time of day.
        </p>
    </div>
@endsection
