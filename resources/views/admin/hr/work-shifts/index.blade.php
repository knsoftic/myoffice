@extends('layouts.admin')

@section('title', 'Work shifts')

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('work_shifts.create');
    $canEdit = (bool) $user?->can('work_shifts.edit');
    $canToggle = (bool) $user?->can('work_shifts.change_status');
    $canDelete = (bool) $user?->can('work_shifts.delete');
@endphp

@section('header')
    <x-ui.page-header title="Work shifts" subtitle="The windows late arrivals and early finishes are measured from." icon="clock" />
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <p class="text-sm text-slate-600 dark:text-slate-300">
            <strong class="font-semibold text-slate-900 dark:text-white">Editing a shift changes nothing about the past.</strong>
            Every attendance row keeps the window it was actually measured against, so fixing a typo or moving a
            start time cannot rewrite a late mark from three months ago.
        </p>
    </x-ui.card>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card title="All shifts" :subtitle="$shifts->count() . ' defined'">
                <x-ui.table :is-empty="$shifts->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Shift</th>
                        <th class="px-4 py-3 text-left font-semibold">Window</th>
                        <th class="px-4 py-3 text-left font-semibold">Weekly off</th>
                        <th class="px-4 py-3 text-right font-semibold">People</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </x-slot:head>

                    @foreach ($shifts as $shift)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="block font-medium text-slate-900 dark:text-white">{{ $shift->name }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $shift->code }}
                                    @if ($shift->is_default) · default @endif
                                    @unless ($shift->is_active) · inactive @endunless
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                <span class="block tabular-nums">{{ substr((string) $shift->start_time, 0, 5) }} – {{ substr((string) $shift->end_time, 0, 5) }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $shift->expected_minutes }} min paid
                                    @if ($shift->break_minutes) · {{ $shift->break_minutes }} min break @endif
                                    @if ($shift->crosses_midnight) · crosses midnight @endif
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                                {{ collect($shift->offDays())->map(fn ($day) => ucfirst($day))->join(', ') }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600 dark:text-slate-300">{{ $shift->employees_count }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($canEdit)
                                        <x-ui.button variant="ghost" size="sm" icon="pencil"
                                            x-on:click="$dispatch('open-modal', 'shift-edit-{{ $shift->id }}')">Edit</x-ui.button>
                                    @endif
                                    @if ($canToggle)
                                        <form method="POST" action="{{ route('admin.work-shifts.toggle', $shift) }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="ghost" size="sm">
                                                {{ $shift->is_active ? 'Retire' : 'Restore' }}
                                            </x-ui.button>
                                        </form>
                                    @endif
                                    @if ($canDelete)
                                        <x-ui.confirm
                                            :action="route('admin.work-shifts.destroy', $shift)"
                                            title="Remove {{ $shift->name }}?"
                                            message="Refused while anybody is still on it."
                                            confirm-label="Remove shift"
                                        >
                                            <x-slot:trigger>
                                                <x-ui.icon-button icon="trash" label="Remove shift" variant="danger" />
                                            </x-slot:trigger>
                                        </x-ui.confirm>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="clock" title="No shifts yet"
                            description="Without one, late minutes are meaningless — the day is judged only on the hours worked." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        @if ($canCreate)
            <x-ui.card title="Add a shift" subtitle="Paid minutes are worked out from the times and the break.">
                <form method="POST" action="{{ route('admin.work-shifts.store') }}" class="space-y-3">
                    @csrf
                    <x-ui.form.input name="code" label="Code" required maxlength="32" :value="old('code')" />
                    <x-ui.form.input name="name" label="Name" required maxlength="100" :value="old('name', 'General')" />
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.form.input type="time" name="start_time" label="Starts" required :value="old('start_time', '09:00')" />
                        <x-ui.form.input type="time" name="end_time" label="Ends" required :value="old('end_time', '17:00')" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.form.input type="number" name="break_minutes" label="Break (min)" :value="old('break_minutes', 0)" />
                        <x-ui.form.input type="number" name="grace_in_minutes" label="Late grace (min)" :value="old('grace_in_minutes', 15)" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.form.input type="number" name="min_half_day_minutes" label="Half day from (min)" :value="old('min_half_day_minutes', 240)" />
                        <x-ui.form.input type="number" name="min_full_day_minutes" label="Full day from (min)" :value="old('min_full_day_minutes', 480)" />
                    </div>
                    <fieldset>
                        <legend class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Weekly off</legend>
                        <div class="grid grid-cols-2 gap-1">
                            @foreach ($weekdays as $value => $label)
                                <x-ui.form.checkbox name="weekly_off_days[]" :value="$value" :label="$label"
                                    :checked="in_array($value, (array) old('weekly_off_days', ['sunday']), true)" />
                            @endforeach
                        </div>
                    </fieldset>
                    <x-ui.form.checkbox name="is_default" label="Default for new employees" :checked="(bool) old('is_default')" />
                    <x-ui.button type="submit" class="w-full" icon="plus">Add shift</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>

    @if ($canEdit)
        @foreach ($shifts as $shift)
            <x-ui.modal :name="'shift-edit-' . $shift->id" title="Edit {{ $shift->name }}" icon="pencil" size="lg">
                <form method="POST" action="{{ route('admin.work-shifts.update', $shift) }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <x-ui.form.input name="code" label="Code" required maxlength="32" :value="$shift->code" />
                    <x-ui.form.input name="name" label="Name" required maxlength="100" :value="$shift->name" />
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.form.input type="time" name="start_time" label="Starts" required :value="substr((string) $shift->start_time, 0, 5)" />
                        <x-ui.form.input type="time" name="end_time" label="Ends" required :value="substr((string) $shift->end_time, 0, 5)" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.form.input type="number" name="break_minutes" label="Break (min)" :value="$shift->break_minutes" />
                        <x-ui.form.input type="number" name="grace_in_minutes" label="Late grace (min)" :value="$shift->grace_in_minutes" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.form.input type="number" name="grace_out_minutes" label="Early-leave grace (min)" :value="$shift->grace_out_minutes" />
                        <x-ui.form.input type="number" name="min_half_day_minutes" label="Half day from (min)" :value="$shift->min_half_day_minutes" />
                    </div>
                    <x-ui.form.input type="number" name="min_full_day_minutes" label="Full day from (min)" :value="$shift->min_full_day_minutes" />
                    <fieldset>
                        <legend class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Weekly off</legend>
                        <div class="grid grid-cols-2 gap-1">
                            @foreach ($weekdays as $value => $label)
                                <x-ui.form.checkbox name="weekly_off_days[]" :value="$value" :label="$label"
                                    :checked="in_array($value, (array) ($shift->weekly_off_days ?? []), true)" />
                            @endforeach
                        </div>
                    </fieldset>
                    <x-ui.form.checkbox name="is_default" label="Default for new employees" :checked="$shift->is_default" />
                    <x-ui.form.checkbox name="is_active" label="Active" :checked="$shift->is_active" with-hidden />
                    <x-ui.button type="submit" class="w-full">Save shift</x-ui.button>
                </form>
            </x-ui.modal>
        @endforeach
    @endif
@endsection
