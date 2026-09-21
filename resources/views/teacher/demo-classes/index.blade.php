@extends('layouts.panel')

@section('title', 'My demo classes')

@section('header')
    <x-ui.page-header title="My demo classes"
                      subtitle="Trial classes you are booked to take. Whether somebody turned up is yours to say — nobody else knows."
                      icon="video-camera" />
@endsection

@section('content')
    @if ($unmarked > 0)
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex items-start gap-3">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    {{ app_number($unmarked) }} {{ \Illuminate\Support\Str::plural('demo', $unmarked) }}
                    finished without being marked. Mark them attended or missed below.
                </p>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>
            <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('teacher.demo-classes.index')">Clear</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$demos->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">When</th>
                <th class="px-4 py-3 text-left font-semibold">Who</th>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($demos as $demo)
                <tr>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-700 dark:text-slate-200">{{ app_date($demo->scheduled_on) }}</div>
                        <div class="text-xs text-slate-400">{{ app_time($demo->startsAt()) }} – {{ app_time($demo->endsAt()) }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $demo->attendee_name }}
                        @if ($demo->classroom)
                            <div class="text-xs text-slate-400">{{ $demo->classroom->code }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $demo->course?->name ?? '—' }}</td>
                    <td class="px-4 py-3"><x-ui.badge :color="$demo->status->color()">{{ $demo->status->label() }}</x-ui.badge></td>
                    <td class="px-4 py-3 text-right">
                        @if ($demo->status->value === 'scheduled')
                            <x-ui.button variant="ghost" size="sm" icon="check"
                                         x-on:click="$dispatch('open-modal', 'mark-demo-{{ $demo->id }}')">Mark</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="video-camera" title="No demo classes"
                                  description="Trial classes booked with you appear here." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$demos" label="demos" />
    </x-ui.card>

    @foreach ($demos as $demo)
        @if ($demo->status->value === 'scheduled')
            <x-ui.modal name="mark-demo-{{ $demo->id }}" :title="'Did '.$demo->attendee_name.' turn up?'" icon="check">
                <form method="POST" action="{{ route('teacher.demo-classes.status', $demo) }}" class="space-y-4">
                    @csrf
                    <x-ui.form.select name="status" label="What happened?" required>
                        <option value="attended">They attended</option>
                        <option value="missed">They did not turn up</option>
                    </x-ui.form.select>

                    <x-ui.form.textarea name="remarks" label="Anything worth noting?" rows="2"
                                        help="The counsellor following this up reads it." />

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost"
                                     x-on:click="$dispatch('close-modal', 'mark-demo-{{ $demo->id }}')">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endif
    @endforeach
@endsection
