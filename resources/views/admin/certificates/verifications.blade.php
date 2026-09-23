@extends('layouts.admin')

@section('title', 'Scans of '.($certificate->certificate_number ?? 'this certificate'))

@section('header')
    <x-ui.page-header title="Who has checked this certificate"
                      subtitle="Every attempt against this code, valid or not. It is an operational record of the verification endpoint — not part of the student's file, which is why it sits behind its own permission."
                      icon="shield-check">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.certificates.show', $certificate)">Back to the certificate</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <x-ui.card :padded="false">
        <x-ui.table :is-empty="$attempts->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">When</th>
                <th class="px-4 py-3 text-left font-semibold">Result</th>
                <th class="px-4 py-3 text-left font-semibold">Code submitted</th>
                <th class="px-4 py-3 text-left font-semibold">From</th>
            </x-slot:head>

            @foreach ($attempts as $attempt)
                <tr>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ app_datetime($attempt->created_at) }}
                    </td>
                    <td class="px-4 py-3">
                        <x-ui.badge :color="$attempt->result->color()" size="xs">{{ $attempt->result->label() }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400">
                        {{ $attempt->submitted_code !== '' ? $attempt->submitted_code : 'nothing typed' }}
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                        {{ $attempt->ip_address ?? '—' }}
                        @if ($attempt->device)
                            <div class="text-xs text-slate-400">{{ $attempt->device }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="shield-check" title="Nobody has checked it yet"
                                  message="Every scan of the QR code and every code typed into the public page lands here, whether or not it matched." />
            </x-slot:empty>
        </x-ui.table>

        <x-ui.pagination-summary :paginator="$attempts" label="attempts" />
    </x-ui.card>
@endsection
