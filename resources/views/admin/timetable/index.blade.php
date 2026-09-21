@extends('layouts.admin')

@section('title', 'Timetable')

@section('header')
    <x-ui.page-header title="Timetable"
                      subtitle="The weekly pattern. What actually happened on a given day lives in Classes — that is what lets one Tuesday be cancelled without rewriting the rule."
                      icon="table-cells">
        <x-slot:actions>
            @can('timetable.print')
                <x-ui.button variant="ghost" icon="printer" :href="route('admin.timetable.print', $view)">Print</x-ui.button>
            @endcan
            @can('timetable.export')
                <x-ui.button variant="ghost" icon="arrow-down-tray" :href="route('admin.timetable.export', 'csv')">Export</x-ui.button>
            @endcan
            @can('timetable.create')
                <x-ui.button icon="plus" x-on:click="$dispatch('open-modal', 'add-slot')">Add a slot</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4" :padded="false">
        <div class="flex flex-wrap gap-1 border-b border-slate-200/80 px-3 py-2 dark:border-slate-800">
            @foreach (['weekly' => 'Week', 'daily' => 'By day', 'teacher' => 'By teacher', 'batch' => 'By batch', 'classroom' => 'By room'] as $key => $label)
                <a href="{{ route('admin.timetable.index', array_merge(['view' => $key], request()->only(['batch_id', 'teacher_id', 'classroom_id', 'course_id', 'day']))) }}"
                   class="rounded-lg px-3 py-1.5 text-sm font-medium transition
                          {{ $view === $key
                              ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300'
                              : 'text-slate-500 hover:bg-slate-50 dark:text-slate-400 dark:hover:bg-slate-800' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.select name="batch_id" label="Batch" placeholder="Any batch">
                @foreach ($batches as $batch)
                    <option value="{{ $batch->id }}" @selected((int) request('batch_id') === (int) $batch->id)>{{ $batch->code }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="teacher_id" label="Teacher" placeholder="Anybody">
                @foreach ($teachers as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('teacher_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="classroom_id" label="Room" placeholder="Anywhere">
                @foreach ($classrooms as $room)
                    <option value="{{ $room->id }}" @selected((int) request('classroom_id') === (int) $room->id)>{{ $room->code }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="course_id" label="Course" placeholder="Any course">
                @foreach ($courses as $id => $name)
                    <option value="{{ $id }}" @selected((int) request('course_id') === (int) $id)>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.timetable.index', $view)">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    @if ($grid['entries']->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="table-cells" title="Nothing on the timetable"
                              description="Add a slot, or build one straight from a batch's days on the batch screen." />
        </x-ui.card>
    @elseif ($view === 'weekly')
        <x-ui.card :padded="false">
            <div class="overflow-x-auto">
                <div class="grid min-w-[900px]" style="grid-template-columns: repeat({{ count($grid['days']) }}, minmax(0, 1fr));">
                    @foreach ($grid['days'] as $day)
                        <div class="border-r border-slate-200/70 last:border-r-0 dark:border-slate-800">
                            <div class="border-b border-slate-200/70 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-400">
                                {{ $day->label() }}
                            </div>
                            <div class="space-y-2 p-2">
                                @forelse ($grid['grouped'][$day->value] ?? [] as $entry)
                                    @include('admin.timetable._slot', ['entry' => $entry])
                                @empty
                                    <p class="px-1 py-6 text-center text-xs text-slate-300 dark:text-slate-600">—</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui.card>
    @else
        <div class="space-y-4">
            @foreach ($grid['grouped'] as $heading => $slots)
                <x-ui.card :title="$view === 'daily' ? \Illuminate\Support\Str::title($heading) : $heading"
                           :subtitle="$slots->count().' '.\Illuminate\Support\Str::plural('slot', $slots->count())">
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($slots as $entry)
                            @include('admin.timetable._slot', ['entry' => $entry, 'showDay' => true])
                        @endforeach
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif

    @can('timetable.create')
        <x-ui.modal name="add-slot" title="Add a timetable slot" icon="table-cells" size="lg">
            <form method="POST" action="{{ route('admin.timetable.store') }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    The teacher, the room and the batch are all checked for an overlap before this is
                    written — and every conflict found is shown at once, not one at a time.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.select name="batch_id" label="Batch" required placeholder="Pick a batch">
                        @foreach ($batches as $batch)
                            <option value="{{ $batch->id }}">{{ $batch->code }} — {{ $batch->name }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.select name="day_of_week" label="Day" required>
                        @foreach ($weekdays as $day)
                            @continue($workingDays !== [] && ! in_array($day->value, $workingDays, true))
                            <option value="{{ $day->value }}">{{ $day->label() }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.input name="start_time" label="From" type="time" required :min="$dayStart" :max="$dayEnd" />
                    <x-ui.form.input name="end_time" label="To" type="time" required :min="$dayStart" :max="$dayEnd" />

                    <x-ui.form.select name="teacher_id" label="Teacher" placeholder="Whoever teaches the batch">
                        @foreach ($teachers as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.select name="classroom_id" label="Room" placeholder="No room">
                        @foreach ($classrooms as $room)
                            <option value="{{ $room->id }}">{{ $room->label() }}</option>
                        @endforeach
                    </x-ui.form.select>

                    <x-ui.form.input name="effective_from" label="From date" type="date" :value="now()->toDateString()" />
                    <x-ui.form.input name="effective_to" label="Until" type="date"
                                     help="Empty means until the batch ends." />
                </div>

                <x-ui.form.input name="clash_override_reason" label="If the teacher or room is already booked, why book it anyway?"
                                 help="A batch clash is never accepted — students cannot be in two rooms. A teacher or room clash can be, with this reason on the record." />

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'add-slot')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Add the slot</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endcan
@endsection
