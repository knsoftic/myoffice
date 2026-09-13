@extends($layout)

@section('title', 'My profile')

@section('header')
    @include('account.partials.header', [
        'pageTitle' => 'My profile',
        'pageSubtitle' => 'Your name, contact details and how the system formats things for you.',
        'pageIcon' => 'user-circle',
    ])
@endsection

@section('content')
    @php
        $roleLabels = $user->roles
            ->map(fn ($role) => filled($role->label ?? null) ? $role->label : $role->name)
            ->filter()
            ->values();

        // Both columns have a database default, so a row read back always has one; a model instance
        // created in the same request may not — fall back rather than blow up the page.
        $currentTheme = $user->theme instanceof \App\Enums\ThemePreference
            ? $user->theme
            : \App\Enums\ThemePreference::System;

        $currentStatus = $user->status instanceof \App\Enums\UserStatus
            ? $user->status
            : \App\Enums\UserStatus::Active;
    @endphp

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-1">
        {{-- Photo --}}
        <x-ui.card title="Profile photo" subtitle="Shown in the topbar, on records you create and in lists.">
            <div class="flex flex-col items-center gap-4 text-center">
                <x-ui.avatar :src="$user->avatar_url" :name="$user->name" size="2xl" :ring="true" />

                <div>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $user->name }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                </div>
            </div>

            <form
                method="POST"
                action="{{ route('account.avatar.store') }}"
                enctype="multipart/form-data"
                x-data="{ busy: false }"
                x-on:submit="busy = true"
                class="mt-5 space-y-4"
            >
                @csrf

                <x-ui.form.file
                    name="avatar"
                    label="Replace photo"
                    :accept="$avatarAccept"
                    :hint="$avatarHint"
                />

                <div class="flex flex-col gap-2 sm:flex-row">
                    <x-ui.button
                        type="submit"
                        icon="arrow-up-tray"
                        class="flex-1"
                        x-bind:disabled="busy"
                        x-bind:aria-busy="busy"
                    >
                        <span x-text="busy ? 'Uploading…' : 'Upload photo'">Upload photo</span>
                    </x-ui.button>

                    @if ($hasAvatar)
                        <x-ui.confirm
                            :action="route('account.avatar.destroy')"
                            method="DELETE"
                            title="Remove your photo?"
                            message="Your initials will be used instead. You can upload a new photo at any time."
                            confirm-label="Remove photo"
                            icon="trash"
                        >
                            <x-slot:trigger>
                                <x-ui.button variant="secondary" icon="trash" type="button">Remove</x-ui.button>
                            </x-slot:trigger>
                        </x-ui.confirm>
                    @endif
                </div>
            </form>
        </x-ui.card>

        {{--
            Theme (phase-01 §7). Saved the moment it is clicked: the Alpine store paints the new
            theme, mirrors it to localStorage and PUTs it to account.theme.update — the same path
            the topbar switcher uses, so the two can never disagree.
        --}}
        <x-ui.card title="Appearance" subtitle="Applies on this device immediately and is remembered on your account.">
            <div class="grid grid-cols-3 gap-2" role="group" aria-label="Colour theme">
                @foreach (\App\Enums\ThemePreference::cases() as $themeCase)
                    @php
                        $themeIcon = match ($themeCase) {
                            \App\Enums\ThemePreference::Light => 'sun',
                            \App\Enums\ThemePreference::Dark => 'moon',
                            \App\Enums\ThemePreference::System => 'monitor',
                        };
                    @endphp

                    <button
                        type="button"
                        x-on:click="$store.theme.set('{{ $themeCase->value }}')"
                        x-bind:aria-pressed="$store.theme.is('{{ $themeCase->value }}').toString()"
                        x-bind:class="$store.theme.is('{{ $themeCase->value }}')
                            ? 'border-brand-500 bg-brand-50 text-brand-700 dark:border-brand-500 dark:bg-brand-500/10 dark:text-brand-300'
                            : 'border-slate-300 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'"
                        class="flex flex-col items-center gap-1.5 rounded-lg border px-2 py-3 text-xs font-medium transition-colors"
                    >
                        <x-ui.icon :name="$themeIcon" class="h-4 w-4" />
                        {{ $themeCase->label() }}
                    </button>
                @endforeach
            </div>

            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                Saved on your account as
                <span class="font-medium text-slate-700 dark:text-slate-200">{{ $currentTheme->label() }}</span>.
                “System” follows your device's light or dark setting.
            </p>
        </x-ui.card>
        </div>

        {{-- Details --}}
        <div class="space-y-5 lg:col-span-2">
            <x-ui.card title="Personal details" subtitle="Only you and an administrator can change these.">
                <form
                    method="POST"
                    action="{{ route('account.profile.update') }}"
                    x-data="{ busy: false }"
                    x-on:submit="busy = true"
                    class="space-y-5"
                >
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-ui.form.input
                            name="name"
                            label="Full name"
                            :value="$user->name"
                            icon="user"
                            autocomplete="name"
                            required
                            class="sm:col-span-2"
                        />

                        <x-ui.form.input
                            type="email"
                            label="Email address"
                            :value="$user->email"
                            icon="mail"
                            readonly
                            disabled
                            help="Ask an administrator to change your sign-in address."
                            class="sm:col-span-2"
                        />

                        <x-ui.form.input
                            name="phone"
                            label="Phone"
                            :value="$user->phone"
                            icon="phone"
                            placeholder="+92 300 1234567"
                            autocomplete="tel"
                            inputmode="tel"
                            optional
                        />

                        <x-ui.form.input
                            name="whatsapp"
                            label="WhatsApp"
                            :value="$user->whatsapp"
                            icon="whatsapp"
                            placeholder="+92 300 1234567"
                            inputmode="tel"
                            optional
                        />
                    </div>

                    <x-ui.section-heading
                        title="Preferences"
                        subtitle="How dates, times and the interface are presented to you."
                        :divider="true"
                    />

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-ui.form.select
                            name="locale"
                            label="Language"
                            :options="$locales"
                            :selected="$user->locale"
                            icon="globe"
                            required
                        />

                        {{-- The display default is localization.timezone (D61), never app.timezone: that is the UTC storage zone. --}}
                        <x-ui.form.select
                            name="timezone"
                            label="Timezone"
                            :options="$timezones"
                            :selected="$user->timezone"
                            :placeholder="'System default ('.\App\Support\Format::timezone().')'"
                            icon="clock"
                            help="Used for every date and time you see."
                        />
                    </div>

                    <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end dark:border-slate-800">
                        <x-ui.button variant="secondary" :href="route('account.profile')" type="button">Cancel</x-ui.button>

                        <x-ui.button
                            type="submit"
                            icon="check"
                            x-bind:disabled="busy"
                            x-bind:aria-busy="busy"
                        >
                            <span x-text="busy ? 'Saving…' : 'Save changes'">Save changes</span>
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <x-ui.card title="Account" subtitle="Set by your administrator — shown here so you know where you stand.">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Status</dt>
                        <dd class="mt-1">
                            <x-ui.badge :color="$currentStatus->color()" :dot="true">{{ $currentStatus->label() }}</x-ui.badge>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Roles</dt>
                        <dd class="mt-1 flex flex-wrap gap-1.5">
                            @forelse ($roleLabels as $roleLabel)
                                <x-ui.badge color="brand" size="sm">{{ $roleLabel }}</x-ui.badge>
                            @empty
                                <span class="text-sm text-slate-500 dark:text-slate-400">No role assigned yet</span>
                            @endforelse
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Last sign-in</dt>
                        <dd class="mt-1 text-sm text-slate-700 dark:text-slate-300">
                            @if ($user->last_login_at)
                                {{ app_datetime($user->last_login_at) }}
                                <span class="text-slate-400 dark:text-slate-500">
                                    ({{ $user->last_login_ip ?: 'IP unknown' }})
                                </span>
                            @else
                                <span class="text-slate-500 dark:text-slate-400">This is your first session</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Password changed</dt>
                        <dd class="mt-1 text-sm text-slate-700 dark:text-slate-300">
                            @if ($user->password_changed_at)
                                {{ $user->password_changed_at->timezone($user->effectiveTimezone())->diffForHumans() }}
                            @else
                                <span class="text-slate-500 dark:text-slate-400">Never changed</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>
    </div>
@endsection
