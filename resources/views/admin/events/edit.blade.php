@extends('layouts.admin')

@section('title', 'Edit event')

{{--
    Edit event — admin.events.edit, can:events.edit. EventPolicy::update() has already passed.

    Controller variables (Admin\Cms\EventController@edit):
      $event            App\Models\Cms\Event with coverImage and editor loaded
      $reservedSlugs, $mediaLibrary, $maxUploadMb, $statusOptions
      $timezone         string   the display timezone
      $minScheduleAt    string   Y-m-d\TH:i floor for the schedule picker
      $canChangeStatus  bool     events.change_status (the module permission)
      $can              array{delete: bool, changeStatus: bool}   permission AND policy, per record

    Writes:
      PUT    admin.events.update {event}                                        can:events.edit
      POST   admin.events.status {event}   status (+ published_at to schedule)  can:events.change_status
      POST   admin.events.featured {event}                                      can:events.change_status
      DELETE admin.events.destroy {event}                                       can:events.delete

    **The publishing controls are in the header, not in the form, and that is structural.** They post to
    their own endpoints behind their own permission, and a form cannot be nested inside another form —
    so saving this page can never carry a publish with it, whatever is posted.
--}}

@php
    use App\Enums\Cms\ContentStatus;
    use Illuminate\Support\Facades\Route;

    $statusValue = $event->status instanceof \BackedEnum ? $event->status->value : (string) $event->status;
    $isPublished = $statusValue === ContentStatus::Published->value;
    $isScheduled = $statusValue === ContentStatus::Scheduled->value;
    $isArchived = $statusValue === ContentStatus::Archived->value;

    $can = array_merge(['delete' => false, 'changeStatus' => false], (array) ($can ?? []));
    $canChange = $can['changeStatus'] && Route::has('admin.events.status');
    $canFeature = $can['changeStatus'] && Route::has('admin.events.featured');
    $canRemove = $can['delete'];

    $timezone = $timezone ?? \App\Support\Format::displayTimezone();
    $minSchedule = $minScheduleAt ?? app_datetime(now()->addMinutes(5), 'Y-m-d\TH:i');
@endphp

@section('header')
    <x-ui.page-header :title="$event->title" :subtitle="'/events/'.$event->slug" icon="calendar-days" :back="route('admin.events.index')">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.marketing.partials.enum-badge', ['value' => $event->status])

            @if ($event->is_featured)
                <x-ui.badge color="amber" size="sm" icon="star">Featured</x-ui.badge>
            @endif

            @if ($event->is_online)
                <x-ui.badge color="sky" size="sm" icon="video-camera">Online</x-ui.badge>
            @endif

            @if ($event->isInProgress())
                <x-ui.badge color="emerald" size="sm" icon="clock">Happening now</x-ui.badge>
            @elseif ($event->hasEnded())
                <x-ui.badge color="slate" size="sm" icon="clock">Over</x-ui.badge>
            @endif
        </div>

        <x-slot:actions>
            @if ($isPublished && ($publicUrl ?? null))
                <x-ui.button variant="ghost" icon="arrow-top-right-on-square" :href="$publicUrl" target="_blank" rel="noopener">View live</x-ui.button>
            @endif

            @if ($canFeature)
                <form method="POST" action="{{ route('admin.events.featured', $event) }}" class="inline">
                    @csrf
                    <x-ui.button
                        type="submit"
                        variant="secondary"
                        icon="star"
                        @class(['text-amber-600 dark:text-amber-300' => $event->is_featured])
                    >{{ $event->is_featured ? 'Unfeature' : 'Feature' }}</x-ui.button>
                </form>
            @endif

            @if ($canChange)
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
                            <x-ui.button icon="check-circle">Publish</x-ui.button>
                        </x-slot:trigger>
                        <x-slot:fields>
                            <input type="hidden" name="status" value="{{ ContentStatus::Published->value }}">
                        </x-slot:fields>
                    </x-ui.confirm>

                    <x-ui.confirm
                        :action="route('admin.events.status', $event)"
                        method="POST"
                        id="schedule-event"
                        :title="($isScheduled ? 'Reschedule ' : 'Schedule ').$event->title"
                        message="The event publishes itself at the chosen moment and stays invisible until then."
                        :confirm-label="$isScheduled ? 'Reschedule' : 'Schedule'"
                        variant="warning"
                        icon="clock"
                    >
                        <x-slot:trigger>
                            <x-ui.button variant="secondary" icon="clock">{{ $isScheduled ? 'Reschedule' : 'Schedule' }}</x-ui.button>
                        </x-slot:trigger>
                        <x-slot:fields>
                            <input type="hidden" name="status" value="{{ ContentStatus::Scheduled->value }}">
                        </x-slot:fields>
                        <div class="mt-4">
                            <label for="schedule-at" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                Publish at <span class="text-rose-500">*</span>
                                <span class="font-normal text-slate-400">({{ $timezone }})</span>
                            </label>
                            <input
                                id="schedule-at"
                                type="datetime-local"
                                name="published_at"
                                form="schedule-event"
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
                            <x-ui.button variant="secondary" icon="eye-slash">Unpublish</x-ui.button>
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
                            <x-ui.icon-button icon="inbox-stack" variant="secondary" label="Archive event" />
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
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete event" />
                    </x-slot:trigger>
                </x-ui.confirm>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')
    @include('admin.cms.partials.media-library-json', ['library' => $mediaLibrary ?? []])

    <div x-data="cmsDirty()" x-on:submit="submitted()">
        <form id="event-form" method="POST" action="{{ route('admin.events.update', $event) }}" class="space-y-6">
            @csrf
            @method('PUT')

            {{-- `published_at` belongs to the schedule dialog in the header, not to this form. --}}
            @include('admin.marketing.partials.form-errors', ['except' => ['published_at', 'status']])
            @include('admin.events.partials.form', ['event' => $event])
            @include('admin.marketing.partials.save-bar', [
                'form' => 'event-form',
                'cancel' => route('admin.events.index'),
                'submitLabel' => 'Save event',
                'record' => $event,
            ])
        </form>
    </div>
@endsection
