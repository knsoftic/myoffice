@extends($layout)

@section('title', 'Change password')

@section('header')
    @include('account.partials.header', [
        'pageTitle' => 'Password',
        'pageSubtitle' => 'Change the password you sign in with.',
        'pageIcon' => 'key',
    ])
@endsection

@section('content')
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            @if ($forced)
                <div
                    class="mb-5 flex items-start gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/25"
                    role="alert"
                >
                    <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                    <div>
                        <p class="font-semibold">A password change is required</p>
                        <p class="mt-0.5">
                            Your administrator asked you to set your own password. Until you do, the rest of the
                            system stays closed.
                        </p>
                    </div>
                </div>
            @endif

            <x-ui.card title="Change password" subtitle="You will stay signed in on this device; everywhere else is signed out.">
                <form
                    method="POST"
                    action="{{ route('account.password.update') }}"
                    x-data="{ busy: false }"
                    x-on:submit="busy = true"
                    class="space-y-5"
                >
                    @csrf
                    @method('PUT')

                    <x-ui.form.input
                        name="current_password"
                        type="password"
                        label="Current password"
                        icon="lock-closed"
                        autocomplete="current-password"
                        required
                        autofocus
                    />

                    <x-ui.form.input
                        name="password"
                        type="password"
                        label="New password"
                        icon="key"
                        autocomplete="new-password"
                        :help="$passwordHint"
                        required
                    />

                    <x-ui.form.input
                        name="password_confirmation"
                        type="password"
                        label="Confirm new password"
                        icon="check-circle"
                        autocomplete="new-password"
                        required
                    />

                    <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end dark:border-slate-800">
                        <x-ui.button
                            type="submit"
                            icon="shield-check"
                            x-bind:disabled="busy"
                            x-bind:aria-busy="busy"
                        >
                            <span x-text="busy ? 'Saving…' : 'Change password'">Change password</span>
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>

        <x-ui.card title="What happens next" icon="information-circle" class="lg:col-span-1">
            <ul class="space-y-3 text-sm text-slate-600 dark:text-slate-300">
                @foreach ([
                    ['check-circle', 'Every other device signed in as you is signed out immediately.'],
                    ['clock', 'The change is stamped on your account and written to the audit trail.'],
                    ['shield-check', 'New passwords are checked against known breached password lists.'],
                    ['eye-slash', 'Nobody — not even an administrator — can read your password.'],
                ] as [$icon, $line])
                    <li class="flex items-start gap-2.5">
                        <x-ui.icon :name="$icon" class="mt-0.5 h-4 w-4 shrink-0 text-brand-500 dark:text-brand-400" />
                        <span>{{ $line }}</span>
                    </li>
                @endforeach
            </ul>

            @if ($user->password_changed_at)
                <p class="mt-5 border-t border-slate-200 pt-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    Last changed
                    {{ $user->password_changed_at->timezone($user->effectiveTimezone())->diffForHumans() }}.
                </p>
            @endif
        </x-ui.card>
    </div>
@endsection
