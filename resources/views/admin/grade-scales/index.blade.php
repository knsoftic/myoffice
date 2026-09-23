@extends('layouts.admin')

@section('title', 'Grade scales')

@section('header')
    <x-ui.page-header title="Grade scales"
                      subtitle="The ladder that turns 87% into an A. A scale that has graded anybody is retired rather than deleted — a card that was handed over has to stay explainable."
                      icon="academic-cap">
        <x-slot:actions>
            @if ($canCreate)
                <x-ui.button icon="plus" :href="route('admin.grade-scales.create')">New scale</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name or code" />

            <x-ui.form.select name="status" label="Status" placeholder="Any">
                <option value="active" @selected(request('status') === 'active')>In use</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Retired</option>
            </x-ui.form.select>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.grade-scales.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$scales->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Scale</th>
                <th class="px-4 py-3 text-right font-semibold">Bands</th>
                <th class="px-4 py-3 text-right font-semibold">Pass at</th>
                <th class="px-4 py-3 text-left font-semibold">Status</th>
            </x-slot:head>

            @foreach ($scales as $scale)
                <tr>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.grade-scales.show', $scale) }}"
                           class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-200">{{ $scale->name }}</a>
                        <div class="text-xs text-slate-400">{{ $scale->code }}</div>
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_number($scale->bands_count) }}
                    </td>
                    <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_number($scale->pass_percentage, 2) }}%
                    </td>
                    <td class="px-4 py-3">
                        @if ($scale->is_default)
                            <x-ui.badge color="brand" size="xs">Default</x-ui.badge>
                        @endif
                        <x-ui.badge :color="$scale->is_active ? 'emerald' : 'slate'" size="xs">
                            {{ $scale->is_active ? 'In use' : 'Retired' }}
                        </x-ui.badge>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="academic-cap" title="No grade scales"
                                  description="An institute starts with one seeded scale. Add another when a course is graded on a different ladder." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$scales" label="scales" />
    </x-ui.card>
@endsection
