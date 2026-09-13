@extends($layout)

@section('title', 'Login history')

@section('header')
    @include('account.partials.header', [
        'pageTitle' => 'Login history',
        'pageSubtitle' => 'Your last '.$limit.' sign-ins, sign-outs and failed attempts.',
        'pageIcon' => 'history',
    ])
@endsection

@section('content')
    <div class="space-y-5">
        <x-ui.card
            title="Recent activity"
            :subtitle="$history->count().' of your most recent authentication events'"
            :padded="false"
        >
            <x-ui.table :is-empty="$history->isEmpty()" :flush="true">
                <x-slot:head>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">Result</th>
                    <th class="px-4 py-3">Device</th>
                    <th class="px-4 py-3">IP address</th>
                    <th class="px-4 py-3">Session ended</th>
                </x-slot:head>

                @foreach ($history as $entry)
                    <tr @class(['bg-emerald-50/40 dark:bg-emerald-500/5' => $currentSessionId !== null && $entry->session_id === $currentSessionId])>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
                            <span title="{{ \App\Support\Format::forHumans($entry->created_at) }}">
                                {{ app_datetime($entry->created_at) ?: '—' }}
                            </span>

                            @if ($currentSessionId !== null && $entry->session_id === $currentSessionId && $entry->isSuccessful())
                                <span class="ml-1 text-xs text-emerald-600 dark:text-emerald-400">(this session)</span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <x-ui.badge :color="$entry->status->color()" size="sm" :dot="true">
                                {{ $entry->status->label() }}
                            </x-ui.badge>
                        </td>

                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                            <p>{{ $entry->deviceLabel() }}</p>
                            <p class="mt-0.5 text-xs capitalize text-slate-500 dark:text-slate-400">
                                {{ $entry->device ?: 'unknown device' }}
                            </p>
                        </td>

                        <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">
                            {{ $entry->ip_address ?: '—' }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                            @if ($entry->logged_out_at)
                                {{ app_datetime($entry->logged_out_at) }}
                            @elseif ($entry->isOpen())
                                <span class="text-emerald-600 dark:text-emerald-400">Still open</span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state
                        icon="history"
                        title="Nothing recorded yet"
                        message="Your sign-ins will be listed here as soon as they happen."
                    />
                </x-slot:empty>
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Reading this list" icon="information-circle">
            <ul class="space-y-2.5 text-sm text-slate-600 dark:text-slate-300">
                <li class="flex items-start gap-2.5">
                    <x-ui.badge color="emerald" size="sm">Success</x-ui.badge>
                    <span>A sign-in that worked. "Still open" means that session has not been signed out.</span>
                </li>
                <li class="flex items-start gap-2.5">
                    <x-ui.badge color="rose" size="sm">Failed</x-ui.badge>
                    <span>Someone tried your email address with the wrong password.</span>
                </li>
                <li class="flex items-start gap-2.5">
                    <x-ui.badge color="slate" size="sm">Logout</x-ui.badge>
                    <span>You signed out, or a session was revoked from the sessions screen.</span>
                </li>
                <li class="flex items-start gap-2.5">
                    <x-ui.badge color="amber" size="sm">Blocked</x-ui.badge>
                    <span>Correct password, but the account was not allowed to sign in at that moment.</span>
                </li>
            </ul>

            <p class="mt-5 border-t border-slate-200 pt-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                See something you do not recognise?
                <a
                    href="{{ route('account.password') }}"
                    class="font-medium text-brand-600 underline-offset-4 hover:underline dark:text-brand-400"
                >Change your password</a>
                and tell your administrator.
            </p>
        </x-ui.card>
    </div>
@endsection
