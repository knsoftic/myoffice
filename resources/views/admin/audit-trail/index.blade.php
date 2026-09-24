@extends('layouts.admin')

@section('title', 'Audit trail')

{{--
    The 107 trail - admin.audit-trail.index (phase-19-23 7.8).

    **Every qualifying row is listed for anybody who may open this screen.** A financial row whose
    figures this reader may not see shows the change, the person and the time, with the VALUES
    replaced by a locked marker naming the permission that would open them. The row is never
    dropped: an audit trail with holes in it is not an audit trail, because somebody looking at a gap
    cannot tell whether nothing happened or whether they were not allowed to see what did.
--}}

@section('header')
    <x-ui.page-header title="Audit trail"
                      subtitle="What changed, from what to what, who changed it and why. Read-only, for everybody."
                      icon="finger-print">
        <x-slot:actions>
            @can('audit_trail.export')
                <x-ui.button variant="secondary" icon="arrow-down-tray"
                             :href="route('admin.audit-trail.export', ['format' => 'csv', ...request()->query()])">
                    Export
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.form.select name="preset" label="Period" placeholder="Any time">
                @foreach (\App\Support\DateRange::presets() as $value => $label)
                    <option value="{{ $value }}" @selected(request('preset') === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.input type="date" name="from" label="From" :value="request('from')" />
            <x-ui.form.input type="date" name="to" label="To" :value="request('to')" />

            <x-ui.form.select name="modules[]" label="Module" multiple>
                @foreach ($modules as $slug => $name)
                    <option value="{{ $slug }}" @selected(in_array($slug, (array) request('modules', []), true))>{{ $name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.input name="search" label="Text contains" :value="request('search')" />

            <div class="flex items-end gap-2 sm:col-span-2 xl:col-span-5">
                <x-ui.button type="submit" icon="funnel">Apply</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.audit-trail.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        @if ($entries->isEmpty())
            <x-ui.empty-state icon="finger-print"
                              title="No recorded changes"
                              description="Nothing in these modules was changed inside this period. Only edits appear here — a record being created is not a change." />
        @else
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($entries as $entry)
                    @php($rows = $diffs[$entry->getKey()] ?? [])

                    <li class="py-4">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-medium text-slate-800 dark:text-slate-100">
                                {{ $entry->causer?->name ?? 'System' }}
                            </span>
                            <span class="text-slate-500 dark:text-slate-400">{{ $entry->description }}</span>

                            @if ($entry->module)
                                <x-ui.badge color="slate">{{ $modules[$entry->module] ?? $entry->module }}</x-ui.badge>
                            @endif

                            @if ($entry->subject_type)
                                <span class="text-xs text-slate-400 dark:text-slate-500">
                                    {{ class_basename($entry->subject_type) }} #{{ $entry->subject_id }}
                                </span>
                            @endif

                            <span class="ml-auto text-xs text-slate-400 dark:text-slate-500">
                                {{ app_datetime($entry->created_at) }}
                            </span>
                        </div>

                        @if ($entry->reason)
                            <p class="mt-1 text-sm italic text-slate-600 dark:text-slate-300">
                                &ldquo;{{ $entry->reason }}&rdquo;
                            </p>
                        @endif

                        @if ($rows !== [])
                            <dl class="mt-2 space-y-1">
                                @foreach ($rows as $change)
                                    <div class="flex flex-wrap items-baseline gap-2 text-sm">
                                        <dt class="font-medium text-slate-600 dark:text-slate-300">{{ $change['label'] }}</dt>

                                        <dd class="flex items-center gap-2">
                                            @if ($change['withheld'])
                                                {{-- Withheld, not blanked, and it says what would open it. --}}
                                                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                                                      @if ($change['permission']) title="Needs {{ $change['permission'] }}" @endif>
                                                    {{ $change['old'] }}
                                                </span>
                                            @else
                                                <span class="text-rose-600 line-through dark:text-rose-400">{{ $change['old'] }}</span>
                                                <span class="text-slate-400">&rarr;</span>
                                                <span class="text-emerald-700 dark:text-emerald-400">{{ $change['new'] }}</span>
                                            @endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </li>
                @endforeach
            </ul>

            <x-ui.pagination-summary :paginator="$entries" class="mt-4" />
        @endif
    </x-ui.card>
@endsection
