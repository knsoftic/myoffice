{{--
    One slot, as it appears in every one of the five views (§8.13).

    A single partial so a slot cannot read one way on the week grid and another on the teacher list —
    the difference between the views is what they group by, not what a slot is.
--}}

@php($showDay = $showDay ?? false)

<div class="rounded-lg border border-slate-200/70 bg-white p-2.5 text-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <div class="font-medium text-slate-700 dark:text-slate-200">
                {{ \Illuminate\Support\Carbon::parse($entry->start_time)->format('H:i') }}
                – {{ \Illuminate\Support\Carbon::parse($entry->end_time)->format('H:i') }}
            </div>
            @if ($showDay)
                <div class="text-xs text-slate-400">{{ $entry->day_of_week->label() }}</div>
            @endif
        </div>
        <x-ui.badge :color="$entry->delivery_mode->color()" size="xs">{{ $entry->delivery_mode->label() }}</x-ui.badge>
    </div>

    <div class="mt-1.5 space-y-0.5 text-xs text-slate-500 dark:text-slate-400">
        <div class="truncate">
            <a href="{{ route('admin.batches.show', $entry->batch_id) }}" class="hover:underline">{{ $entry->batch?->code ?? '—' }}</a>
        </div>
        <div class="truncate">{{ $entry->teacher?->name ?? 'The batch teacher' }}</div>
        @if ($entry->classroom)
            <div class="truncate">{{ $entry->classroom->code }}</div>
        @endif
    </div>

    @canany(['timetable.change_status', 'timetable.delete'])
        <div class="mt-2 flex gap-1">
            @can('timetable.change_status')
                <button type="button" x-on:click="$dispatch('open-modal', 'end-slot-{{ $entry->id }}')"
                        class="text-xs text-slate-400 hover:text-slate-600 hover:underline dark:hover:text-slate-200">End</button>
            @endcan
            @can('timetable.delete')
                <button type="button" x-on:click="$dispatch('open-modal', 'delete-slot-{{ $entry->id }}')"
                        class="text-xs text-slate-400 hover:text-rose-600 hover:underline">Remove</button>
            @endcan
        </div>
    @endcanany
</div>

@can('timetable.change_status')
    <x-ui.modal name="end-slot-{{ $entry->id }}" title="End this slot" icon="calendar-days">
        <form method="POST" action="{{ route('admin.timetable.end', $entry) }}" class="space-y-4">
            @csrf
            <p class="text-sm text-slate-600 dark:text-slate-300">
                The rule stops applying from this date. Classes it had already produced after that day
                are cancelled with your reason, and the roster is told — classes already held are left
                exactly as they were taught.
            </p>
            <x-ui.form.input name="effective_to" label="Last day it applies" type="date" required :value="now()->toDateString()" />
            <x-ui.form.textarea name="reason" label="Why?" required rows="2" />
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'end-slot-{{ $entry->id }}')">Keep it</x-ui.button>
                <x-ui.button type="submit" variant="secondary">End the slot</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
@endcan

@can('timetable.delete')
    <x-ui.modal name="delete-slot-{{ $entry->id }}" title="Remove this slot?" icon="trash">
        <form method="POST" action="{{ route('admin.timetable.destroy', $entry) }}" class="space-y-4">
            @csrf
            @method('DELETE')
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Classes still to come are cancelled first, so none of them is left on the calendar with
                nothing behind it. Classes already held survive — they happened.
            </p>
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'delete-slot-{{ $entry->id }}')">Keep it</x-ui.button>
                <x-ui.button type="submit" variant="danger">Remove</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
@endcan
