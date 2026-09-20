@extends('layouts.admin')

@section('title', 'Activity · ' . $collaborator->displayName())

@section('header')
    <x-ui.page-header title="Activity" :subtitle="$collaborator->displayName() . ' · ' . $collaborator->collaborator_code" icon="clock">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('admin.collaborators.show', $collaborator)">Back to the record</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card :title="$entries->total() . ' ' . \Illuminate\Support\Str::plural('entry', $entries->total())"
               subtitle="Everything this collaborator did, and everything the system did to them — one audit store, filtered.">
        <x-ui.table :is-empty="$entries->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">When</th>
                <th class="px-4 py-3 text-left font-semibold">What</th>
                <th class="px-4 py-3 text-left font-semibold">Who</th>
                <th class="px-4 py-3 text-left font-semibold">Why</th>
            </x-slot:head>

            @foreach ($entries as $entry)
                @php
                    $event = \App\Enums\CollaboratorActivityEvent::tryFrom((string) $entry->event);
                @endphp
                <tr>
                    <td class="px-4 py-3 tabular-nums text-slate-600 dark:text-slate-300">
                        {{ app_datetime($entry->created_at) }}
                    </td>
                    <td class="px-4 py-3">
                        <span class="block font-medium text-slate-900 dark:text-white">
                            {{ $event?->label() ?? $entry->description }}
                        </span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $entry->module }}</span>
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                        {{ $entry->causer?->name ?? 'the system' }}
                        @if ($entry->ip_address)
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $entry->ip_address }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $entry->reason ?? '—' }}</td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="clock" title="Nothing recorded yet"
                    description="Sign-ins, referrals, commission and payouts all appear here once they happen." />
            </x-slot:empty>
        </x-ui.table>

        @if ($entries->hasPages())
            <div class="mt-4">{{ $entries->links() }}</div>
        @endif
    </x-ui.card>
@endsection
