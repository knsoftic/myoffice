@extends('layouts.guest')

@section('title', 'Reset your password')

@section('heading')
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
        Forgot your password?
    </h1>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
        Tell us the email address on your account and we will send you a link to choose a new password.
    </p>
@endsection

@section('content')
    @include('auth.partials.status')

    <form
        method="POST"
        action="{{ route('password.email') }}"
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
            help="The link is valid for 60 minutes and can be used once."
            required
            autofocus
        />

        @include('auth.partials.submit', ['label' => 'Email me a reset link', 'busyLabel' => 'Sending…'])
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
