@extends($layout)

@section('title', 'Active sessions')

@section('header')
    @include('account.partials.header', [
        'pageTitle' => 'Sessions',
        'pageSubtitle' => 'Every device currently signed in as you. Revoke anything you do not recognise.',
        'pageIcon' => 'desktop',
    ])
@endsection

@section('content')
    @php
        $others = $sessions->reject(fn ($session) => (string) $session->getKey() === (string) $currentSessionId);
    @endphp

    <div class="space-y-5">
        @unless ($usesDatabaseSessions)
            <div
                class="flex items-start gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/25"
                role="alert"
            >
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div>
                    <p class="font-semibold">Sessions are not being stored in the database</p>
                    <p class="mt-0.5">
                        This list is only complete while the database session driver is active. Ask your
                        administrator to set <code class="font-mono text-xs">SESSION_DRIVER=database</code>.
                    </p>
                </div>
            </div>
        @endunless

        <x-ui.card
            title="Active sessions"
            :subtitle="$sessions->count().' '.\Illuminate\Support\Str::plural('session', $sessions->count()).' stored for your account'"
            :padded="false"
        >
            <x-slot:actions>
                @if ($others->isNotEmpty())
                    <x-ui.confirm
                        :action="route('account.sessions.destroy-others')"
                        method="DELETE"
                        title="Sign out every other device?"
                        message="You stay signed in here. Everywhere else will have to sign in again."
                        confirm-label="Sign out others"
                    >
                        <x-slot:trigger>
                            <x-ui.button variant="secondary" size="sm" icon="logout" type="button">
                                Sign out other devices
                            </x-ui.button>
                        </x-slot:trigger>
                    </x-ui.confirm>
                @endif
            </x-slot:actions>

            <x-ui.table :is-empty="$sessions->isEmpty()" :flush="true">
                <x-slot:head>
                    <th class="px-4 py-3">Device</th>
                    <th class="px-4 py-3">IP address</th>
                    <th class="px-4 py-3">Last active</th>
                    <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @foreach ($sessions as $session)
                    @php $isCurrent = (string) $session->getKey() === (string) $currentSessionId; @endphp

                    <tr>
                        <td class="px-4 py-3">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                    <x-ui.icon
                                        :name="in_array($session->device(), ['mobile', 'tablet'], true) ? 'phone' : 'desktop'"
                                        class="h-4 w-4"
                                    />
                                </span>

                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-900 dark:text-white">
                                        {{ $session->deviceLabel() }}

                                        @if ($isCurrent)
                                            <x-ui.badge color="emerald" size="sm" :dot="true">This device</x-ui.badge>
                                        @endif
                                    </p>
                                    <p class="mt-0.5 truncate text-xs capitalize text-slate-500 dark:text-slate-400">
                                        {{ $session->device() }}
                                    </p>
                                </div>
                            </div>
                        </td>

                        <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">
                            {{ $session->ip_address ?: '—' }}
                        </td>

                        <td class="px-4 py-3 text-sm text-slate-600 dark:text-slate-300">
                            <span title="{{ app_datetime($session->lastActiveAt()) }}">
                                {{ $session->lastActiveAt()->diffForHumans() }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-right">
                            @if ($isCurrent)
                                <span class="text-xs text-slate-400 dark:text-slate-500">Current session</span>
                            @else
                                <x-ui.confirm
                                    :action="route('account.sessions.destroy', $session->getKey())"
                                    method="DELETE"
                                    title="Sign this device out?"
                                    :message="$session->deviceLabel().' will have to sign in again.'"
                                    confirm-label="Sign out device"
                                >
                                    <x-slot:trigger>
                                        <x-ui.button variant="ghost" size="sm" icon="logout" type="button">
                                            Sign out
                                        </x-ui.button>
                                    </x-slot:trigger>
                                </x-ui.confirm>
                            @endif
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state
                        icon="desktop"
                        title="No stored sessions"
                        message="Your current session has not been written to the sessions table yet, or sessions are not stored in the database."
                    />
                </x-slot:empty>
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Signed out everywhere?" icon="shield-check">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                If you think someone else has been using your account,
                <a
                    href="{{ route('account.password') }}"
                    class="font-medium text-brand-600 underline-offset-4 hover:underline dark:text-brand-400"
                >change your password</a> —
                that revokes every other session and every "keep me signed in" cookie in one step.
                Then check your
                <a
                    href="{{ route('account.login-history') }}"
                    class="font-medium text-brand-600 underline-offset-4 hover:underline dark:text-brand-400"
                >login history</a>
                for attempts you do not recognise.
            </p>
        </x-ui.card>
    </div>
@endsection
