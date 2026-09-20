@extends('layouts.admin')

@section('title', 'Value — ' . $project->code)

{{--
    Value and commission — admin.projects.value.index (phase-06 §8.8, requirement §107).

    Every row of the history is append-only evidence (INV-P3): there is no edit and no delete here, and
    there never will be. A wrong value is corrected by adding the next revision.
--}}

@section('header')
    <x-ui.page-header :title="'Value — ' . $project->name" :subtitle="$project->code" icon="banknotes">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.projects.show', $project)">Back to project</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.stat-card label="Contract value" :value="money((string) $project->project_value)" icon="banknotes" />
                <x-ui.stat-card label="Discount" :value="money((string) $project->discount_amount)" icon="receipt-percent" />
                <x-ui.stat-card label="Net value" :value="money((string) $project->net_value)" icon="calculator" delta-label="Derived — never written by hand" />
            </div>

            <x-ui.card title="Revision history" subtitle="Append-only. A wrong value is corrected by the next revision, never by editing this one.">
                @if ($revisions->isEmpty())
                    <p class="text-sm text-slate-500 dark:text-slate-400">No revisions recorded.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <tr>
                                    <th class="py-2 pr-4">#</th>
                                    <th class="py-2 pr-4">Effective</th>
                                    <th class="py-2 pr-4 text-right">Was</th>
                                    <th class="py-2 pr-4 text-right">Now</th>
                                    <th class="py-2 pr-4 text-right">Change</th>
                                    <th class="py-2 pr-4">Reason</th>
                                    <th class="py-2">By</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                @foreach ($revisions as $revision)
                                    @php $delta = (string) $revision->delta_amount; @endphp
                                    <tr>
                                        <td class="py-2 pr-4 tabular-nums text-slate-500">{{ $revision->revision_no }}</td>
                                        <td class="py-2 pr-4 whitespace-nowrap">{{ app_date($revision->effective_on) }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ money($revision->old_net_value) }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ money($revision->new_net_value) }}</td>
                                        <td @class(['py-2 pr-4 text-right tabular-nums font-medium', 'text-emerald-600 dark:text-emerald-400' => str_starts_with($delta, '-') === false, 'text-rose-600 dark:text-rose-400' => str_starts_with($delta, '-')])>{{ money($delta) }}</td>
                                        <td class="py-2 pr-4 max-w-[18rem] truncate" title="{{ $revision->reason }}">{{ $revision->reason }}</td>
                                        <td class="py-2 whitespace-nowrap text-slate-500">{{ $revision->changed_by_name }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        </div>

        @if ($canRevise)
            <x-ui.card title="Record a revision" subtitle="The reason and the date it takes effect are both required.">
                <form method="POST" action="{{ route('admin.projects.value.store', $project) }}" class="space-y-3">
                    @csrf
                    <x-ui.form.input type="number" step="0.01" min="0" name="project_value" label="Contract value" :value="old('project_value', (string) $project->project_value)" required />
                    <x-ui.form.input type="number" step="0.01" min="0" name="discount_amount" label="Discount" :value="old('discount_amount', (string) $project->discount_amount)" required />
                    <x-ui.form.input type="date" name="effective_on" label="Effective from" :value="old('effective_on', now()->toDateString())" required help="The business date the new value applies from." />
                    <x-ui.form.textarea name="reason" label="Reason" rows="3" required maxlength="255" :value="old('reason')" />
                    <x-ui.button type="submit" class="w-full" icon="check">Record revision</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
@endsection
