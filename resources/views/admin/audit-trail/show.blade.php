@extends('layouts.admin')

@section('title', 'Recorded change')

@section('header')
    <x-ui.page-header title="Recorded change"
                      :subtitle="$activity->description"
                      icon="finger-print">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="arrow-left" :href="route('admin.audit-trail.index')">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2">
            <x-ui.section-heading title="What changed" />

            @if ($diff === [])
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                    This entry recorded no field-level change.
                </p>
            @else
                <dl class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($diff as $change)
                        <div class="grid gap-1 py-3 sm:grid-cols-3">
                            <dt class="text-sm font-medium text-slate-600 dark:text-slate-300">{{ $change['label'] }}</dt>

                            <dd class="sm:col-span-2">
                                @if ($change['withheld'])
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                        {{ $change['old'] }}
                                    </span>
                                    @if ($change['permission'])
                                        <span class="ml-2 text-xs text-slate-400 dark:text-slate-500">
                                            needs {{ $change['permission'] }}
                                        </span>
                                    @endif
                                @else
                                    <span class="text-rose-600 line-through dark:text-rose-400">{{ $change['old'] }}</span>
                                    <span class="mx-2 text-slate-400">&rarr;</span>
                                    <span class="text-emerald-700 dark:text-emerald-400">{{ $change['new'] }}</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-ui.card>

        <x-ui.card>
            <x-ui.section-heading title="Context" />

            <dl class="mt-3 space-y-2 text-sm">
                <div><dt class="text-slate-500 dark:text-slate-400">When</dt>
                     <dd class="text-slate-800 dark:text-slate-100">{{ app_datetime($activity->created_at) }}</dd></div>

                <div><dt class="text-slate-500 dark:text-slate-400">Who</dt>
                     <dd class="text-slate-800 dark:text-slate-100">{{ $activity->causer?->name ?? 'System' }}</dd></div>

                <div><dt class="text-slate-500 dark:text-slate-400">Sensitivity</dt>
                     <dd><x-ui.badge :color="$sensitivity->color()">{{ $sensitivity->label() }}</x-ui.badge></dd></div>

                @if ($activity->module)
                    <div><dt class="text-slate-500 dark:text-slate-400">Module</dt>
                         <dd class="text-slate-800 dark:text-slate-100">{{ $activity->module }}</dd></div>
                @endif

                @if ($activity->ip_address)
                    <div><dt class="text-slate-500 dark:text-slate-400">IP address</dt>
                         <dd class="text-slate-800 dark:text-slate-100">{{ $activity->ip_address }}</dd></div>
                @endif

                @if ($activity->reason)
                    <div><dt class="text-slate-500 dark:text-slate-400">Reason given</dt>
                         <dd class="italic text-slate-800 dark:text-slate-100">{{ $activity->reason }}</dd></div>
                @endif
            </dl>
        </x-ui.card>
    </div>
@endsection
