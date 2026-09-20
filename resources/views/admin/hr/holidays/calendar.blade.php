@extends('layouts.admin')

@section('title', 'Holiday calendar')

@section('header')
    <x-ui.page-header title="Holiday calendar" :subtitle="$year" icon="calendar">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.holidays.index', ['year' => $year])" icon="list-bullet">List view</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <form method="GET" class="mb-4 flex items-end gap-2">
        <div class="w-40">
            <x-ui.form.select name="year" label="Year">
                @foreach ($years as $option)
                    <option value="{{ $option }}" @selected($option === $year)>{{ $option }}</option>
                @endforeach
            </x-ui.form.select>
        </div>
        <x-ui.button type="submit" variant="secondary">Show</x-ui.button>
    </form>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @for ($month = 1; $month <= 12; $month++)
            @php $inMonth = $holidays->get($month, collect()); @endphp
            <x-ui.card :title="app_date(\Illuminate\Support\Carbon::create($year, $month, 1), 'F')"
                       :subtitle="$inMonth->count() . ' holiday(s)'">
                @if ($inMonth->isEmpty())
                    <p class="text-sm text-slate-500 dark:text-slate-400">No holidays.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($inMonth as $holiday)
                            <li class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-slate-900 dark:text-white">{{ $holiday->title }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">
                                        {{ app_date($holiday->holiday_date, 'D j M') }} · {{ $holiday->is_paid ? 'paid' : 'unpaid' }}
                                    </span>
                                </div>
                                <x-ui.badge :color="$holiday->holiday_type->color()" size="xs">{{ $holiday->holiday_type->label() }}</x-ui.badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endfor
    </div>
@endsection
