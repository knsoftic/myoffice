@extends('layouts.admin')

@section('title', 'Events')

{{--
    Events and announcements — admin.events.index, module:events.

    Controller variables (Admin\Cms\EventController@index):
      $events          LengthAwarePaginator<App\Models\Cms\Event> with the coverImage relation;
                       trashed rows only on the trashed tab
      $tab             string  all (default) | upcoming | past | published | scheduled | draft | archived
                               | featured | trashed
      $counts          array<string, int>   one per tab (trashed only for events.restore)
      $filters         array<string, mixed>
      $sort            string  starts_at (default) | title | ends_at | status | sort_order | updated_at
      $direction       string  asc | desc  (desc by default on the past tab)
      $statusOptions   array<string, string>   ContentStatus::options()
      $timezone        string  the display timezone, shown beside every moment the admin types
      $minScheduleAt   string  Y-m-d\TH:i, the schedule picker's floor
      $can             array{create,edit,delete,changeStatus,restore: bool}
    Query: tab, search (title, summary, location), status, featured (1|0), type (online|onsite),
           has_image (1|0), from, to (starts_at, Y-m-d), sort, direction, page.

    Writes — each one is authorized again on the server, so the buttons below are presentation only:
      POST   admin.events.status {event}    status (+ published_at when scheduling)  can:events.change_status
      POST   admin.events.featured {event}                                            can:events.change_status
      POST   admin.events.restore {event}                                             can:events.restore
      DELETE admin.events.destroy {event}                                             can:events.delete
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();

    // The row rule is the policy's when one is registered; otherwise the module permission. Either way
    // the decision is taken again in the controller — this only decides what is worth drawing.
    $allows = static function (string $ability, string $permission, $event) use ($user): bool {
        return Gate::getPolicyFor($event) !== null
            ? Gate::forUser($user)->allows($ability, $event)
            : (bool) $user?->can($permission);
    };

    $can = array_merge(
        ['create' => false, 'edit' => false, 'delete' => false, 'changeStatus' => false, 'restore' => false],
        (array) ($can ?? [])
    );
    $canRestore = $can['restore'] && Route::has('admin.events.restore');

    $tab = in_array($tab ?? 'all', \App\Http\Controllers\Admin\Cms\EventController::TABS, true) ? ($tab ?? 'all') : 'all';
    $counts = $counts ?? [];
    $sort = $sort ?? 'starts_at';
    $direction = $direction ?? 'asc';
    $isTrash = $tab === 'trashed';
    $timezone = $timezone ?? \App\Support\Format::displayTimezone();
    $minSchedule = $minScheduleAt ?? app_datetime(now()->addMinutes(5), 'Y-m-d\TH:i');
    $filtered = collect(request()->except(['tab', 'page', 'sort', 'direction']))->filter(fn ($v) => filled($v))->isNotEmpty();

    $tabDefs = [
        'all' => ['All', null],
        'upcoming' => ['Upcoming', 'calendar-days'],
        'past' => ['Past', 'clock'],
        'published' => ['Published', 'check-circle'],
        'scheduled' => ['Scheduled', 'clock'],
        'draft' => ['Drafts', 'pencil'],
        'archived' => ['Archived', 'inbox-stack'],
        'featured' => ['Featured', 'star'],
        'trashed' => ['Trashed', 'trash'],
    ];

    $tabs = [];
    foreach ($tabDefs as $key => [$label, $tabIcon]) {
        if ($key === 'trashed' && ! $can['restore']) {
            continue;
        }
        $tabs[] = [
            'label' => $label,
            'url' => route('admin.events.index', $key === 'all' ? [] : ['tab' => $key]),
            'active' => $tab === $key,
            'count' => isset($counts[$key]) ? app_number((int) $counts[$key]) : null,
            'icon' => $tabIcon,
        ];
    }
@endphp

@section('header')
    <x-ui.page-header title="Events" subtitle="Everything with a date: talks, intakes, open days — and announcements, which are events with no end." icon="calendar-days">
        <x-slot:actions>
            @if ($can['create'])
                <x-ui.button icon="plus" :href="route('admin.events.create')">New event</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
        <x-ui.tabs :tabs="$tabs" />

        <x-ui.filter-bar placeholder="Search title, summary or place…" :reset="route('admin.events.index', $tab === 'all' ? [] : ['tab' => $tab])">
            @if ($tab !== 'all')
                <input type="hidden" name="tab" value="{{ $tab }}">
            @endif

            @if (! in_array($tab, ['published', 'scheduled', 'draft', 'archived'], true))
                <x-ui.form.select name="status" :options="$statusOptions ?? ContentStatus::options()" :selected="request('status')" placeholder="Any status" size="sm" aria-label="Filter by status" />
            @endif

            <x-ui.form.select name="type" :options="['online' => 'Online', 'onsite' => 'In person']" :selected="request('type')" placeholder="Online or in person" size="sm" aria-label="Filter by where it happens" />

            @if ($tab !== 'featured')
                <x-ui.form.select name="featured" :options="['1' => 'Featured', '0' => 'Not featured']" :selected="request('featured')" placeholder="Featured or not" size="sm" aria-label="Filter by featured" />
            @endif

            <x-ui.form.select name="has_image" :options="['1' => 'With cover image', '0' => 'Without cover image']" :selected="request('has_image')" placeholder="Cover or not" size="sm" aria-label="Filter by cover image" />

            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">Starting from</span>
                <input type="date" name="from" value="{{ request('from') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
            <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <span class="shrink-0">to</span>
                <input type="date" name="to" value="{{ request('to') }}" class="block w-full rounded-lg border-slate-300 py-1.5 text-xs shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
            </label>
        </x-ui.filter-bar>

        <x-ui.table loading="navigating" :is-empty="$events->isEmpty()" :columns="7">
            <x-slot:head>
                <x-ui.th-sortable column="title" :sort="$sort" :direction="$direction">Event</x-ui.th-sortable>
                <x-ui.th-sortable column="starts_at" :sort="$sort" :direction="$direction">When</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3">Where</th>
                <th scope="col" class="px-4 py-3 text-right">Capacity</th>
                <x-ui.th-sortable column="status" :sort="$sort" :direction="$direction">Status</x-ui.th-sortable>
                <x-ui.th-sortable column="updated_at" :sort="$sort" :direction="$direction" default="desc">Updated</x-ui.th-sortable>
                <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($events as $event)
                @php
                    $statusValue = $event->status instanceof \BackedEnum ? $event->status->value : (string) $event->status;
                    $isPublished = $statusValue === ContentStatus::Published->value;
                    $isScheduled = $statusValue === ContentStatus::Scheduled->value;
                    $isArchived = $statusValue === ContentStatus::Archived->value;

                    $canUpdate = ! $isTrash && $allows('update', 'events.edit', $event);
                    $canChange = ! $isTrash && $allows('changeStatus', 'events.change_status', $event);
                    $canRemove = ! $isTrash && $allows('delete', 'events.delete', $event);

                    $ended = $event->hasEnded();
                    $running = $event->isInProgress();
                @endphp
                <tr>
                    <td class="min-w-[18rem]">
                        <div class="flex items-center gap-3">
                            @include('admin.marketing.partials.thumb', [
                                'model' => $event,
                                'relations' => ['coverImage'],
                                'column' => 'cover_media_id',
                                'alt' => $event->title,
                                'box' => 'h-10 w-16',
                                'icon' => 'calendar-days',
                            ])
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5">
                                    @if ($event->is_featured)
                                        <x-ui.icon name="star" class="h-3.5 w-3.5 shrink-0 text-amber-500 dark:text-amber-300" label="Featured" />
                                    @endif
                                    @if ($canUpdate)
                                        <a href="{{ route('admin.events.edit', $event) }}" class="line-clamp-1 font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $event->title }}</a>
                                    @else
                                        <span class="line-clamp-1 font-medium text-slate-900 dark:text-white">{{ $event->title }}</span>
                                    @endif
                                </div>
                                <span class="block truncate font-mono text-xs text-slate-500 dark:text-slate-400">/events/{{ $event->slug }}</span>
                                @if ($isScheduled && $event->published_at)
                                    <span class="mt-0.5 flex items-center gap-1 text-2xs font-medium text-amber-700 dark:text-amber-400">
                                        <x-ui.icon name="clock" class="h-3 w-3" />
                                        Goes live {{ app_datetime($event->published_at) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        <span class="block text-slate-900 dark:text-white">
                            {{ $event->is_all_day ? app_date($event->starts_at) : app_datetime($event->starts_at) }}
                        </span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            @if ($event->ends_at)
                                until {{ $event->is_all_day ? app_date($event->ends_at) : app_datetime($event->ends_at) }}
                            @else
                                a single moment — an announcement
                            @endif
                        </span>
                        @if ($running)
                            <x-ui.badge color="emerald" size="xs" :pill="false">Happening now</x-ui.badge>
                        @elseif ($ended)
                            <x-ui.badge color="slate" size="xs" :pill="false">Over</x-ui.badge>
                        @endif
                    </td>

                    <td class="max-w-[14rem] text-sm">
                        @if ($event->is_online)
                            <span class="flex items-center gap-1.5 text-slate-900 dark:text-white">
                                <x-ui.icon name="video-camera" class="h-4 w-4 shrink-0 text-sky-600 dark:text-sky-400" />
                                Online
                            </span>
                        @else
                            <span class="flex items-center gap-1.5">
                                <x-ui.icon name="building-office" class="h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500" />
                                <span class="line-clamp-2">{{ $event->location ?: '—' }}</span>
                            </span>
                        @endif
                        @if (filled($event->registration_url))
                            <span class="mt-0.5 flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
                                <x-ui.icon name="link" class="h-3 w-3" />
                                Registration link
                            </span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-right text-sm tabular-nums">
                        {{ $event->capacity === null ? 'No limit' : app_number((int) $event->capacity) }}
                    </td>

                    <td>@include('admin.marketing.partials.enum-badge', ['value' => $event->status])</td>

                    <td class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                        {{ app_datetime($isTrash ? $event->deleted_at : $event->updated_at) }}
                    </td>

                    <td>
                        <div class="flex items-center justify-end gap-1">
                            @if ($isTrash)
                                @if ($canRestore)
                                    <form method="POST" action="{{ route('admin.events.restore', $event) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="secondary" icon="arrow-path">Restore</x-ui.button>
                                    </form>
                                @endif
                            @else
                                @if ($canUpdate)
                                    <x-ui.icon-button icon="pencil" size="sm" label="Edit {{ $event->title }}" :href="route('admin.events.edit', $event)" />
                                @endif

                                @if ($isPublished && Route::has('site.events.show'))
                                    <x-ui.icon-button icon="arrow-top-right-on-square" size="sm" label="View {{ $event->title }} on the website" :href="route('site.events.show', $event->slug)" target="_blank" rel="noopener" />
                                @endif

                                @if ($canChange && Route::has('admin.events.featured'))
                                    <form method="POST" action="{{ route('admin.events.featured', $event) }}" class="inline">
                                        @csrf
                                        <x-ui.icon-button
                                            type="submit"
                                            icon="star"
                                            size="sm"
                                            :label="$event->is_featured ? 'Stop featuring '.$event->title : 'Feature '.$event->title"
                                            @class(['text-amber-500 dark:text-amber-300' => $event->is_featured])
                                        />
                                    </form>
                                @endif

                                @if ($canChange && Route::has('admin.events.status'))
                                    @if (! $isPublished)
                                        <x-ui.confirm
                                            :action="route('admin.events.status', $event)"
                                            method="POST"
                                            :title="'Publish '.$event->title.' now?'"
                                            :message="$isScheduled ? 'The schedule is replaced: the event goes live immediately.' : 'The event appears at /events/'.$event->slug.' for every visitor.'"
                                            confirm-label="Publish now"
                                            variant="warning"
                                            icon="check-circle"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="check-circle" size="sm" label="Publish {{ $event->title }}" class="text-emerald-600 dark:text-emerald-400" />
                                            </x-slot:trigger>
                                            <x-slot:fields>
                                                <input type="hidden" name="status" value="{{ ContentStatus::Published->value }}">
                                            </x-slot:fields>
                                        </x-ui.confirm>

                                        <x-ui.confirm
                                            :action="route('admin.events.status', $event)"
                                            method="POST"
                                            id="schedule-event-{{ $event->getKey() }}"
                                            :title="($isScheduled ? 'Reschedule ' : 'Schedule ').$event->title"
                                            message="The event publishes itself at the chosen moment and stays invisible until then."
                                            :confirm-label="$isScheduled ? 'Reschedule' : 'Schedule'"
                                            variant="warning"
                                            icon="clock"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="clock" size="sm" label="Schedule {{ $event->title }}" />
                                            </x-slot:trigger>
                                            <x-slot:fields>
                                                <input type="hidden" name="status" value="{{ ContentStatus::Scheduled->value }}">
                                            </x-slot:fields>
                                            <div class="mt-4">
                                                <label for="schedule-at-{{ $event->getKey() }}" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                                    Publish at <span class="text-rose-500">*</span>
                                                    <span class="font-normal text-slate-400">({{ $timezone }})</span>
                                                </label>
                                                <input
                                                    id="schedule-at-{{ $event->getKey() }}"
                                                    type="datetime-local"
                                                    name="published_at"
                                                    form="schedule-event-{{ $event->getKey() }}"
                                                    min="{{ $minSchedule }}"
                                                    value="{{ $isScheduled && $event->published_at ? app_datetime($event->published_at, 'Y-m-d\TH:i') : '' }}"
                                                    required
                                                    class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                                                >
                                            </div>
                                        </x-ui.confirm>
                                    @else
                                        <x-ui.confirm
                                            :action="route('admin.events.status', $event)"
                                            method="POST"
                                            :title="'Unpublish '.$event->title.'?'"
                                            message="The event returns to draft and its address returns 404. The date it first went live is kept."
                                            confirm-label="Unpublish"
                                            variant="warning"
                                            icon="eye-slash"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="eye-slash" size="sm" label="Unpublish {{ $event->title }}" />
                                            </x-slot:trigger>
                                            <x-slot:fields>
                                                <input type="hidden" name="status" value="{{ ContentStatus::Draft->value }}">
                                            </x-slot:fields>
                                        </x-ui.confirm>
                                    @endif

                                    @if (! $isArchived)
                                        <x-ui.confirm
                                            :action="route('admin.events.status', $event)"
                                            method="POST"
                                            :title="'Archive '.$event->title.'?'"
                                            message="Archiving retires the event from the website without deleting anything. It can be moved back to draft later."
                                            confirm-label="Archive"
                                            variant="warning"
                                            icon="inbox-stack"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="inbox-stack" size="sm" label="Archive {{ $event->title }}" />
                                            </x-slot:trigger>
                                            <x-slot:fields>
                                                <input type="hidden" name="status" value="{{ ContentStatus::Archived->value }}">
                                            </x-slot:fields>
                                        </x-ui.confirm>
                                    @endif
                                @endif

                                @if ($canRemove)
                                    <x-ui.confirm
                                        :action="route('admin.events.destroy', $event)"
                                        :title="'Delete '.$event->title.'?'"
                                        message="The event moves to the trash and leaves the website. Its address stays reserved so no other event can take it."
                                        confirm-label="Delete event"
                                    >
                                        <x-slot:trigger>
                                            <x-ui.icon-button icon="trash" variant="danger" size="sm" label="Delete {{ $event->title }}" />
                                        </x-slot:trigger>
                                    </x-ui.confirm>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                @if ($filtered)
                    <x-ui.empty-state icon="calendar-days" title="No events match those filters">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="route('admin.events.index', $tab === 'all' ? [] : ['tab' => $tab])">Clear filters</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                @elseif ($tab === 'trashed')
                    <x-ui.empty-state icon="trash" title="The trash is empty" />
                @elseif ($tab === 'upcoming')
                    <x-ui.empty-state icon="calendar-days" title="Nothing is coming up" message="Everything on the board has already happened." :compact="true" />
                @elseif ($tab === 'scheduled')
                    <x-ui.empty-state icon="clock" title="Nothing is scheduled" message="Schedule an event from its row or its editor." :compact="true" />
                @elseif ($tab !== 'all')
                    <x-ui.empty-state icon="calendar-days" :title="'No '.strtolower($tabDefs[$tab][0]).' events'" :compact="true" />
                @else
                    <x-ui.empty-state icon="calendar-days" title="No events yet" message="Add the first talk, intake or open day.">
                        @if ($can['create'])
                            <x-slot:action>
                                <x-ui.button icon="plus" :href="route('admin.events.create')">New event</x-ui.button>
                            </x-slot:action>
                        @endif
                    </x-ui.empty-state>
                @endif
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$events" label="events" />
            </x-slot:footer>
        </x-ui.table>
    </div>
@endsection
