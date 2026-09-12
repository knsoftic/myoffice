@extends('layouts.guest')

@section('title', 'Verify your email')

@section('heading')
    <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
        Verify your email
    </h1>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
        We sent a verification link to
        <span class="font-medium text-slate-700 dark:text-slate-200">{{ auth()->user()?->email }}</span>.
        Open it to finish setting up your account.
    </p>
@endsection

@section('content')
    @include('auth.partials.status')

    <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-950/40 dark:text-slate-300 dark:ring-slate-800">
        <p class="flex items-start gap-2.5">
            <x-ui.icon name="information-circle" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400 dark:text-slate-500" />
            <span>
                Did not get it? Check your spam folder, then send yourself a fresh link below.
            </span>
        </p>
    </div>

    <form
        method="POST"
        action="{{ route('verification.send') }}"
        x-data="{ busy: false }"
        x-on:submit="busy = true"
        class="mt-5"
    >
        @csrf

        @include('auth.partials.submit', ['label' => 'Resend verification email', 'busyLabel' => 'Sending…'])
    </form>

    <div class="mt-6 flex items-center justify-between gap-3 border-t border-slate-200 pt-4 dark:border-slate-800">
        @if (\Illuminate\Support\Facades\Route::has('account.profile'))
            <a
                href="{{ route('account.profile') }}"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
            >
                <x-ui.icon name="user" class="h-3.5 w-3.5" />
                Review my profile
            </a>
        @else
            <span></span>
        @endif

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button
                type="submit"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
            >
                <x-ui.icon name="logout" class="h-3.5 w-3.5" />
                Sign out
            </button>
        </form>
    </div>
@endsection
