@extends('layouts.guest')

@section('title', 'Confirm your password')

@section('heading')
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
        Confirm your password
    </h1>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
        This is a protected area. Please re-enter your password to continue.
    </p>
@endsection

@section('content')
    @include('auth.partials.status')

    <form
        method="POST"
        action="{{ route('password.confirm') }}"
        x-data="{ busy: false }"
        x-on:submit="busy = true"
        class="space-y-5"
    >
        @csrf

        <x-ui.form.input
            name="password"
            type="password"
            label="Password"
            placeholder="••••••••"
            icon="lock-closed"
            autocomplete="current-password"
            required
            autofocus
        />

        @include('auth.partials.submit', ['label' => 'Confirm', 'busyLabel' => 'Checking…'])
    </form>

    <div class="mt-6 border-t border-slate-200 pt-4 text-center dark:border-slate-800">
        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button
                type="submit"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
            >
                <x-ui.icon name="logout" class="h-3.5 w-3.5" />
                Sign out instead
            </button>
        </form>
    </div>
@endsection
