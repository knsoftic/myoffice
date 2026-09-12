@extends('layouts.guest')

@section('title', 'Sign in')

@section('heading')
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
        Welcome back
    </h1>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
        Sign in with the email address your administrator set up for you.
    </p>
@endsection

@section('content')
    @include('auth.partials.status')

    <form
        method="POST"
        action="{{ route('login') }}"
        x-data="{ busy: false }"
        x-on:submit="busy = true"
        class="space-y-5"
    >
        @csrf

        <x-ui.form.input
            name="email"
            type="email"
            label="Email address"
            placeholder="you@company.com"
            icon="mail"
            autocomplete="username"
            inputmode="email"
            autocapitalize="none"
            spellcheck="false"
            required
            autofocus
        />

        <div>
            <x-ui.form.input
                name="password"
                type="password"
                label="Password"
                placeholder="••••••••"
                icon="lock-closed"
                autocomplete="current-password"
                required
            />

            @if ($canResetPassword ?? \Illuminate\Support\Facades\Route::has('password.request'))
                <div class="mt-2 text-right">
                    <a
                        href="{{ route('password.request') }}"
                        class="text-xs font-medium text-brand-600 underline-offset-4 hover:underline dark:text-brand-400"
                    >
                        Forgot your password?
                    </a>
                </div>
            @endif
        </div>

        <x-ui.form.checkbox
            name="remember"
            label="Keep me signed in"
            description="Only do this on a device you trust."
            :checked="(bool) old('remember')"
        />

        @include('auth.partials.submit', ['label' => 'Sign in', 'busyLabel' => 'Signing in…'])
    </form>
@endsection

@section('below')
    Accounts are created by an administrator — there is no public sign-up.
    <br class="hidden sm:block" />
    Need access? Ask your administrator to invite you.
@endsection
