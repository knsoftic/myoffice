{{--
    The public catalogue — site.courses.index and site.courses.category (§89, phase-14-17 §8.20).

    The title, description and canonical come from Phase 3's SEO pipeline through the layout, not from
    this view: one SEO store, one place it is decided (D23).

    Controller variables (Site\CourseController, through ComposesContentPages::contentPage()):
      $site, $page   the layout's own payload
      $courses       LengthAwarePaginator<Course> — published only, in a live category
      $categories    the filter rail's categories, each with a live published count
      $query         CatalogueQuery — what the visitor asked for
      $category      ?CourseCategory when this is a category page
--}}

@extends('layouts.site')

@php
    $pageDescription = $category?->seo_description
        ?: ($category?->description ?: 'Every course we run, with what it covers, how long it takes and what it costs.');
@endphp

@section('content')
    <section class="bg-slate-50 py-12 dark:bg-slate-900/40">
        <div class="mx-auto max-w-6xl px-4">
            @if ($category)
                <nav class="mb-3 text-sm text-slate-500 dark:text-slate-400">
                    <a href="{{ route('site.courses.index') }}" class="hover:underline">Courses</a>
                    <span class="mx-1">/</span>
                    <span class="text-slate-700 dark:text-slate-200">{{ $category->name }}</span>
                </nav>
            @endif

            <h1 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">
                {{ $category?->name ?? 'Courses' }}
            </h1>
            <p class="mt-2 max-w-2xl text-slate-600 dark:text-slate-300">{{ $pageDescription }}</p>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-10">
        <div class="grid gap-8 lg:grid-cols-4">
            {{-- The filter rail. Every control is a plain GET so a filtered catalogue is a shareable URL. --}}
            <aside class="lg:col-span-1">
                <form method="GET" class="space-y-5 rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                    <div>
                        <label for="q" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Search</label>
                        <input type="search" id="q" name="q" value="{{ $query->search }}"
                               placeholder="What do you want to learn?"
                               class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                    </div>

                    @unless ($category)
                        <div>
                            <p class="mb-2 text-sm font-medium text-slate-700 dark:text-slate-200">Category</p>
                            <ul class="space-y-1 text-sm">
                                @foreach ($categories as $option)
                                    <li>
                                        <a href="{{ route('site.courses.category', $option->slug) }}"
                                           class="flex items-center justify-between rounded px-2 py-1 text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                                            <span>{{ $option->name }}</span>
                                            <span class="text-xs tabular-nums text-slate-400">{{ $option->published_courses_count }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endunless

                    <div>
                        <label for="level" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Level</label>
                        <select id="level" name="level"
                                class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                            <option value="">Any level</option>
                            @foreach (\App\Enums\CourseLevel::cases() as $level)
                                <option value="{{ $level->value }}" @selected($query->level === $level)>{{ $level->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="mode" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">How it is taught</label>
                        <select id="mode" name="mode"
                                class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                            <option value="">Any way</option>
                            @foreach (\App\Enums\DeliveryMode::cases() as $mode)
                                <option value="{{ $mode->value }}" @selected($query->mode === $mode)>{{ $mode->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                        <input type="checkbox" name="certificate" value="1" @checked($query->certificate === true)
                               class="rounded border-slate-300 dark:border-slate-700 dark:bg-slate-900">
                        Ends with a certificate
                    </label>

                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="fee_from" value="{{ $query->feeFrom }}" placeholder="Fee from" min="0"
                               class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                        <input type="number" name="fee_to" value="{{ $query->feeTo }}" placeholder="to" min="0"
                               class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                    </div>

                    <div class="flex gap-2">
                        <button type="submit"
                                class="flex-1 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900">
                            Filter
                        </button>
                        @if ($query->hasFilters())
                            <a href="{{ $category ? route('site.courses.category', $category->slug) : route('site.courses.index') }}"
                               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                                Clear
                            </a>
                        @endif
                    </div>
                </form>
            </aside>

            <div class="lg:col-span-3">
                @if ($courses->isEmpty())
                    <div class="rounded-xl border border-dashed border-slate-300 p-12 text-center dark:border-slate-700">
                        <h2 class="text-lg font-medium text-slate-900 dark:text-white">
                            {{ $query->hasFilters() ? 'No courses match those filters' : 'No courses listed yet' }}
                        </h2>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                            {{ $query->hasFilters()
                                ? 'Try widening the search, or clear the filters to see everything.'
                                : 'New courses appear here as soon as they are published.' }}
                        </p>
                        @if ($query->hasFilters())
                            <a href="{{ route('site.courses.index') }}"
                               class="mt-4 inline-block rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-slate-900">
                                Clear filters
                            </a>
                        @endif
                    </div>
                @else
                    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
                        {{ $courses->total() }} {{ \Illuminate\Support\Str::plural('course', $courses->total()) }}
                    </p>

                    <div class="grid gap-5 sm:grid-cols-2">
                        @foreach ($courses as $course)
                            <article class="flex flex-col overflow-hidden rounded-xl border border-slate-200 transition-shadow hover:shadow-md dark:border-slate-800">
                                <a href="{{ route('site.courses.show', $course->slug) }}" class="block">
                                    @if (filled($course->thumbnail_path))
                                        <img src="{{ \Illuminate\Support\Facades\Storage::url($course->thumbnail_path) }}"
                                             alt="" loading="lazy"
                                             class="h-40 w-full object-cover">
                                    @else
                                        <div class="flex h-40 w-full items-center justify-center bg-slate-100 dark:bg-slate-800">
                                            <x-ui.icon name="academic-cap" class="h-10 w-10 text-slate-300 dark:text-slate-600" />
                                        </div>
                                    @endif
                                </a>

                                <div class="flex flex-1 flex-col p-5">
                                    <div class="mb-2 flex flex-wrap items-center gap-2 text-xs">
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                            {{ $course->category?->name }}
                                        </span>
                                        @if ($course->is_featured)
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                                Featured
                                            </span>
                                        @endif
                                        @if ($course->certificate_available)
                                            <span class="rounded-full bg-sky-100 px-2 py-0.5 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">
                                                Certificate
                                            </span>
                                        @endif
                                    </div>

                                    <h2 class="text-lg font-semibold leading-snug text-slate-900 dark:text-white">
                                        <a href="{{ route('site.courses.show', $course->slug) }}" class="hover:underline">
                                            {{ $course->name }}
                                        </a>
                                    </h2>

                                    @if (filled($course->short_description))
                                        <p class="mt-2 line-clamp-2 text-sm text-slate-600 dark:text-slate-300">
                                            {{ $course->short_description }}
                                        </p>
                                    @endif

                                    <dl class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                        @if ($course->durationLabel())
                                            <div class="flex items-center gap-1">
                                                <x-ui.icon name="clock" class="h-3.5 w-3.5" />
                                                <dd>{{ $course->durationLabel() }}</dd>
                                            </div>
                                        @endif
                                        <div class="flex items-center gap-1">
                                            <x-ui.icon name="chart-bar" class="h-3.5 w-3.5" />
                                            <dd>{{ $course->level->label() }}</dd>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <x-ui.icon :name="$course->delivery_mode->icon()" class="h-3.5 w-3.5" />
                                            <dd>{{ $course->delivery_mode->label() }}</dd>
                                        </div>
                                    </dl>

                                    <div class="mt-auto flex items-end justify-between gap-3 pt-5">
                                        <div>
                                            {{-- A zero fee is "ask us", never a confident free. --}}
                                            @if (bccomp((string) $course->course_fee, '0.00', 2) === 1)
                                                <p class="text-lg font-semibold tabular-nums text-slate-900 dark:text-white">
                                                    {{ money($course->course_fee) }}
                                                </p>
                                                @if ($course->installment_available)
                                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                                        or up to {{ $course->max_installments }} installments
                                                    </p>
                                                @endif
                                            @else
                                                <p class="text-sm font-medium text-slate-600 dark:text-slate-300">Contact us for the fee</p>
                                            @endif
                                        </div>

                                        <a href="{{ route('site.courses.show', $course->slug) }}"
                                           class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">
                                            Details
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="mt-8">
                        {{ $courses->links() }}
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
