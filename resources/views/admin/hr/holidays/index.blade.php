@extends('layouts.admin')

@section('title', 'Holidays')

@php
    $user = auth()->user();
    $canCreate = (bool) $user?->can('holidays.create');
    $canEdit = (bool) $user?->can('holidays.edit');
    $canDelete = (bool) $user?->can('holidays.delete');
@endphp

@section('header')
    <x-ui.page-header title="Holidays" :subtitle="$year . ' calendar'" icon="calendar-days">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.holidays.calendar', ['year' => $year])" icon="calendar">Calendar view</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <form method="GET" class="flex items-end gap-2">
                <div class="w-40">
                    <x-ui.form.select name="year" label="Year">
                        @foreach ($years as $option)
                            <option value="{{ $option }}" @selected($option === $year)>{{ $option }}</option>
                        @endforeach
                    </x-ui.form.select>
                </div>
                <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
            </form>

            <x-ui.card title="Holidays in {{ $year }}" :subtitle="$holidays->count() . ' day(s)'">
                <x-ui.table :is-empty="$holidays->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Date</th>
                        <th class="px-4 py-3 text-left font-semibold">Holiday</th>
                        <th class="px-4 py-3 text-left font-semibold">Type</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </x-slot:head>

                    @foreach ($holidays as $holiday)
                        <tr>
                            <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                                <span class="block">{{ app_date($holiday->holiday_date) }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ app_date($holiday->holiday_date, 'l') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="block font-medium text-slate-900 dark:text-white">{{ $holiday->title }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $holiday->branch?->name ?? 'All branches' }}
                                    · {{ $holiday->is_paid ? 'paid' : 'unpaid' }}
                                    @if ($holiday->is_recurring_yearly) · recurring @endif
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge :color="$holiday->holiday_type->color()" size="xs">{{ $holiday->holiday_type->label() }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($canEdit)
                                        <x-ui.button variant="ghost" size="sm" icon="pencil"
                                            x-on:click="$dispatch('open-modal', 'holiday-edit-{{ $holiday->id }}')">Edit</x-ui.button>
                                    @endif
                                    @if ($canDelete)
                                        <x-ui.button variant="ghost" size="sm" icon="trash"
                                            x-on:click="$dispatch('open-modal', 'holiday-remove-{{ $holiday->id }}')">Remove</x-ui.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="calendar-days" title="No holidays in {{ $year }}"
                            description="Add them before the month is closed, or the days will be marked absent and have to be recalculated." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            @if ($canCreate)
                <x-ui.card title="Add a holiday" subtitle="A range becomes one row per date.">
                    <form method="POST" action="{{ route('admin.holidays.store') }}" class="space-y-3">
                        @csrf
                        <x-ui.form.input name="title" label="Title" required maxlength="150" :value="old('title')" />
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.form.input type="date" name="holiday_date" label="From" required :value="old('holiday_date')" />
                            <x-ui.form.input type="date" name="to_date" label="To" :value="old('to_date')" help="Leave empty for one day." />
                        </div>
                        <x-ui.form.select name="holiday_type" label="Type" :options="$types" :selected="old('holiday_type', 'public')" required />
                        <x-ui.form.checkbox name="is_paid" label="Paid" :checked="(bool) old('is_paid', true)" />
                        <x-ui.form.checkbox name="is_recurring_yearly" label="Repeats every year" :checked="(bool) old('is_recurring_yearly')" />
                        <x-ui.button type="submit" class="w-full" icon="plus">Add to the calendar</x-ui.button>
                    </form>
                </x-ui.card>

                <x-ui.card title="Copy a year" subtitle="Recurring holidays only; existing dates are skipped.">
                    <form method="POST" action="{{ route('admin.holidays.copy-year') }}" class="space-y-3">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.form.input type="number" name="from_year" label="From" required :value="$year" />
                            <x-ui.form.input type="number" name="to_year" label="To" required :value="$year + 1" />
                        </div>
                        <x-ui.button type="submit" variant="secondary" class="w-full" icon="document-duplicate">Copy</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>

    @foreach ($holidays as $holiday)
        @if ($canEdit)
            <x-ui.modal :name="'holiday-edit-' . $holiday->id" title="Edit {{ $holiday->title }}" icon="pencil">
                <form method="POST" action="{{ route('admin.holidays.update', $holiday) }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    <x-ui.form.input name="title" label="Title" required maxlength="150" :value="$holiday->title" />
                    <x-ui.form.select name="holiday_type" label="Type" :options="$types" :selected="$holiday->holiday_type->value" required />
                    <x-ui.form.checkbox name="is_paid" label="Paid" :checked="$holiday->is_paid" />
                    <x-ui.form.checkbox name="is_recurring_yearly" label="Repeats every year" :checked="$holiday->is_recurring_yearly" />
                    <x-ui.form.checkbox name="is_active" label="Active" :checked="$holiday->is_active" with-hidden />
                    <x-ui.button type="submit" class="w-full">Save holiday</x-ui.button>
                </form>
            </x-ui.modal>
        @endif

        @if ($canDelete)
            <x-ui.modal :name="'holiday-remove-' . $holiday->id" title="Remove {{ $holiday->title }}?" icon="exclamation-triangle">
                <form method="POST" action="{{ route('admin.holidays.destroy', $holiday) }}" class="space-y-3">
                    @csrf
                    @method('DELETE')
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Everybody marked "holiday" on {{ app_date($holiday->holiday_date) }} will be re-judged from their
                        punches, and each change is recorded. A date already locked by payroll is refused.
                    </p>
                    <x-ui.form.textarea name="reason" label="Reason" rows="2" required
                        placeholder="It was announced by mistake." />
                    <x-ui.button type="submit" variant="danger" class="w-full">Remove holiday</x-ui.button>
                </form>
            </x-ui.modal>
        @endif
    @endforeach
@endsection
