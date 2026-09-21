@extends('layouts.site')

@section('content')
    <section class="mx-auto max-w-2xl px-4 py-20 text-center">
        <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50 dark:bg-emerald-500/10">
            <x-ui.icon name="check" class="h-8 w-8 text-emerald-500" />
        </div>

        <h1 class="text-2xl font-semibold text-slate-800 dark:text-slate-100">Thank you, {{ $application->name }}</h1>

        <p class="mt-3 text-slate-600 dark:text-slate-300">
            Your application for <strong>{{ $application->course?->name }}</strong> has reached us.
            Somebody will call you on {{ $application->phone }} to take it from here.
        </p>

        <div class="mx-auto mt-8 max-w-sm rounded-xl border border-slate-200 p-4 dark:border-slate-800">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Your reference</div>
            <div class="mt-1 font-mono text-lg text-slate-800 dark:text-slate-100">{{ $application->application_number }}</div>
            <p class="mt-2 text-xs text-slate-500">Quote this if you call or message us about it.</p>
        </div>

        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            @if (Route::has('site.courses.index'))
                <x-site.button label="Browse other courses" :url="route('site.courses.index')" style="outline" icon="academic-cap" />
            @endif
        </div>
    </section>
@endsection
