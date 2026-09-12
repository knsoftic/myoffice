@extends('layouts.guest')

@section('title', 'Choose a new password')

@section('heading')
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
        Choose a new password
    </h1>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
        Pick something you have not used anywhere else. Every device currently signed in as you will
        be signed out.
    </p>
@endsection

@section('content')
    @include('auth.partials.status')

    <form
        method="POST"
        action="{{ route('password.store') }}"
        x-data="{ busy: false }"
        x-on:submit="busy = true"
        class="space-y-5"
    >
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}" />

        <x-ui.form.input
            name="email"
            type="email"
            label="Email address"
            :value="$request->email"
            icon="mail"
            autocomplete="username"
            inputmode="email"
            autocapitalize="none"
            spellcheck="false"
            required
            autofocus
        />

        <x-ui.form.input
            name="password"
            type="password"
            label="New password"
            placeholder="••••••••••"
            icon="lock-closed"
            autocomplete="new-password"
            :help="$passwordHint ?? null"
            required
        />

        <x-ui.form.input
            name="password_confirmation"
            type="password"
            label="Confirm new password"
            placeholder="••••••••••"
            icon="check-circle"
            autocomplete="new-password"
            required
        />

        @include('auth.partials.submit', ['label' => 'Save new password', 'busyLabel' => 'Saving…'])
    </form>

    <div class="mt-6 border-t border-slate-200 pt-4 text-center dark:border-slate-800">
        <a
            href="{{ route('login') }}"
            class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
        >
            <x-ui.icon name="arrow-left" class="h-3.5 w-3.5" />
            Back to sign in
        </a>
    </div>
@endsection
