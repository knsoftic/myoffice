@extends('layouts.admin')

@section('title', $scale->name)

@section('header')
    <x-ui.page-header :title="$scale->name" :subtitle="$scale->code" icon="academic-cap">
        <x-slot:actions>
            @if ($canEdit)
                <x-ui.button variant="secondary" icon="pencil" :href="route('admin.grade-scales.edit', $scale)">Edit</x-ui.button>
            @endif

            @if ($canChangeStatus && ! $scale->is_default && $scale->is_active)
                <form method="POST" action="{{ route('admin.grade-scales.default', $scale) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="star">Make it the default</x-ui.button>
                </form>
            @endif

            @if ($canDelete)
                <x-ui.confirm :action="route('admin.grade-scales.destroy', $scale)"
                              title="Remove this scale?"
                              message="Nothing has been graded with it, so it can go. A scale that has graded anybody is retired instead."
                              confirm-label="Remove scale">
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" label="Remove scale" variant="danger" />
                    </x-slot:trigger>
                </x-ui.confirm>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card :padded="false">
                <x-ui.table :is-empty="$scale->bands->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Grade</th>
                        <th class="px-4 py-3 text-left font-semibold">Title</th>
                        <th class="px-4 py-3 text-right font-semibold">Range</th>
                        <th class="px-4 py-3 text-right font-semibold">Points</th>
                        <th class="px-4 py-3 text-left font-semibold">Outcome</th>
                    </x-slot:head>

                    @foreach ($scale->bands->sortByDesc('sort_order') as $band)
                        <tr>
                            <td class="px-4 py-3">
                                <x-ui.badge :color="$band->color ?? 'slate'" size="xs">{{ $band->grade }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">{{ $band->title ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                                {{ app_number($band->min_percentage, 2) }} – {{ app_number($band->max_percentage, 2) }}%
                            </td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300">
                                {{ $band->grade_point === null ? '—' : app_number($band->grade_point, 2) }}
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge :color="$band->is_pass ? 'emerald' : 'rose'" size="xs">
                                    {{ $band->is_pass ? 'Pass' : 'Fail' }}
                                </x-ui.badge>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="academic-cap" title="No bands"
                                          description="A scale with no bands grades nobody." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="grid gap-6">
            <x-ui.card>
                <x-ui.section-heading title="Where it stands" />

                <dl class="grid gap-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Status</dt>
                        <dd class="mt-1 flex flex-wrap gap-1">
                            @if ($scale->is_default)
                                <x-ui.badge color="brand" size="xs">Institute default</x-ui.badge>
                            @endif
                            <x-ui.badge :color="$scale->is_active ? 'emerald' : 'slate'" size="xs">
                                {{ $scale->is_active ? 'In use' : 'Retired' }}
                            </x-ui.badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Pass line</dt>
                        <dd class="mt-1 tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($scale->pass_percentage, 2) }}%</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Exams using it</dt>
                        <dd class="mt-1 tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($examCount) }}</dd>
                    </div>
                </dl>

                @if ($scale->description)
                    <p class="mt-4 border-t border-slate-100 pt-4 text-sm text-slate-600 dark:border-slate-700/60 dark:text-slate-300">
                        {{ $scale->description }}
                    </p>
                @endif
            </x-ui.card>

            @if ($canChangeStatus && $scale->is_active)
                <x-ui.card>
                    <x-ui.section-heading title="Retire it"
                                          description="Exams already graded against it keep their grades, and the bands stay reachable so a printed card can still be explained." />

                    <form method="POST" action="{{ route('admin.grade-scales.deactivate', $scale) }}" class="grid gap-3">
                        @csrf
                        <x-ui.form.textarea name="reason" label="Why" rows="2" required />
                        <x-ui.button type="submit" variant="secondary" icon="archive-box" class="w-full">Retire this scale</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>
    </div>
@endsection
