{{--
    Admissions are shut — HTTP 200 with a noindex, never a 404.

    The page exists; the institute is simply not taking admissions today. A 404 would tell somebody
    who was sent this link that it was never real, and they would look elsewhere rather than come
    back next term.
--}}

@extends('layouts.site')

@section('content')
    <section class="mx-auto max-w-2xl px-4 py-20 text-center">
        <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-amber-50 dark:bg-amber-500/10">
            <x-ui.icon name="clock" class="h-8 w-8 text-amber-500" />
        </div>

        <h1 class="text-2xl font-semibold text-slate-800 dark:text-slate-100">Admissions are closed</h1>

        <p class="mt-3 text-slate-600 dark:text-slate-300">{{ $message }}</p>

        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            @if (Route::has('site.courses.index'))
                <x-site.button label="Browse the courses" :url="route('site.courses.index')" style="primary" icon="academic-cap" />
            @endif
            @if (Route::has('site.contact.index'))
                <x-site.button label="Leave your number" :url="route('site.contact.index')" style="outline" icon="phone" />
            @endif
        </div>
    </section>
@endsection
