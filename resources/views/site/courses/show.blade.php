{{--
    The public course page — site.courses.show (§90, phase-14-17 §8.20).

    **Every Apply and WhatsApp link comes from the payload**, which got them from
    `PublicCourseService::applyUrl()` / `whatsappUrl()`. A hand-written href here would drop the
    referral code a collaborator's link carried in, and the partner would stop being able to see that
    their link worked.

    The trainer and upcoming-batch sections render **nothing at all** until Phase 16 ships `teachers`
    and `batches` — an empty collection, not a placeholder card, because an invented fact on a public
    page is worse than a missing section (D28).

    Controller variables (Site\CourseController@show, through ComposesContentPages::contentPage()):
      $site, $page   the layout's own payload; the SEO comes from Phase 3's pipeline
      $payload       App\DataObjects\Institute\CourseLandingPayload
--}}

@extends('layouts.site')

@php
    $course = $payload->course;
    $total = $payload->totalFee();
    $hours = $payload->outlineHours();
@endphp

@section('content')
    {{-- ------------------------------------------------------------------ hero --}}
    <section class="border-b border-slate-200 bg-slate-50 py-12 dark:border-slate-800 dark:bg-slate-900/40">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <nav class="mb-3 text-sm text-slate-500 dark:text-slate-400">
                    <a href="{{ route('site.courses.index') }}" class="hover:underline">Courses</a>
                    @if ($course->category)
                        <span class="mx-1">/</span>
                        <a href="{{ route('site.courses.category', $course->category->slug) }}" class="hover:underline">
                            {{ $course->category->name }}
                        </a>
                    @endif
                </nav>

                <h1 class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-white sm:text-4xl">
                    {{ $course->name }}
                </h1>

                @if (filled($course->short_description))
                    <p class="mt-3 max-w-2xl text-lg text-slate-600 dark:text-slate-300">{{ $course->short_description }}</p>
                @endif

                <div class="mt-5 flex flex-wrap items-center gap-2 text-sm">
                    <span class="rounded-full bg-white px-3 py-1 text-slate-700 shadow-sm dark:bg-slate-800 dark:text-slate-200">
                        {{ $course->level->label() }}
                    </span>
                    <span class="rounded-full bg-white px-3 py-1 text-slate-700 shadow-sm dark:bg-slate-800 dark:text-slate-200">
                        {{ $course->delivery_mode->label() }}
                    </span>
                    @if ($course->durationLabel())
                        <span class="rounded-full bg-white px-3 py-1 text-slate-700 shadow-sm dark:bg-slate-800 dark:text-slate-200">
                            {{ $course->durationLabel() }}
                        </span>
                    @endif
                    @if ($course->total_classes)
                        <span class="rounded-full bg-white px-3 py-1 text-slate-700 shadow-sm dark:bg-slate-800 dark:text-slate-200">
                            {{ $course->total_classes }} classes
                        </span>
                    @endif
                    @if ($course->certificate_available)
                        <span class="rounded-full bg-sky-100 px-3 py-1 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">
                            Certificate on completion
                        </span>
                    @endif
                </div>

                @if ($payload->referredBy)
                    {{-- Evidence to the visitor that the attribution was recorded. --}}
                    <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                        Referred by <strong class="text-slate-700 dark:text-slate-200">{{ $payload->referredBy }}</strong>
                        @if ($payload->referralCode)
                            <span class="font-mono text-xs">({{ $payload->referralCode }})</span>
                        @endif
                    </p>
                @endif
            </div>

            {{-- The fee block and the one thing the visitor came to do. --}}
            <aside class="lg:col-span-1">
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    @if ($total !== null)
                        <p class="text-3xl font-semibold tabular-nums text-slate-900 dark:text-white">
                            {{ money($course->course_fee) }}
                        </p>

                        <dl class="mt-3 space-y-1 text-sm">
                            @if (bccomp((string) $course->admission_fee, '0.00', 2) === 1)
                                <div class="flex justify-between gap-3">
                                    <dt class="text-slate-500 dark:text-slate-400">Admission fee</dt>
                                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ money($course->admission_fee) }}</dd>
                                </div>
                            @endif
                            @if (bccomp((string) $course->registration_fee, '0.00', 2) === 1)
                                <div class="flex justify-between gap-3">
                                    <dt class="text-slate-500 dark:text-slate-400">Registration fee</dt>
                                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ money($course->registration_fee) }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between gap-3 border-t border-slate-200 pt-1 dark:border-slate-700">
                                <dt class="font-medium text-slate-900 dark:text-white">Total to start</dt>
                                <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($total) }}</dd>
                            </div>
                        </dl>

                        @if ($course->installment_available)
                            <p class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                Payable in up to {{ $course->max_installments }} installments.
                                {{ $course->installment_note }}
                            </p>
                        @endif
                    @else
                        <p class="text-lg font-medium text-slate-900 dark:text-white">Contact us for the fee</p>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                            We will talk you through what this course costs and how it can be paid.
                        </p>
                    @endif

                    <div class="mt-5 space-y-2">
                        @if ($payload->admissionOpen)
                            <a href="{{ $payload->applyUrl }}"
                               class="block rounded-lg bg-slate-900 px-4 py-3 text-center text-sm font-semibold text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">
                                Apply now
                            </a>
                        @else
                            <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-center text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
                                Admissions for this course are closed at the moment — talk to us and we will
                                tell you when the next intake opens.
                            </p>
                        @endif

                        <a href="{{ $payload->whatsappUrl }}" rel="noopener"
                           class="block rounded-lg border border-slate-300 px-4 py-3 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                            Ask on WhatsApp
                        </a>
                    </div>

                    @if ($hours !== null)
                        <p class="mt-4 text-center text-xs text-slate-500 dark:text-slate-400">
                            About {{ $hours }} hours of teaching across
                            {{ $course->modules_count }} {{ \Illuminate\Support\Str::plural('module', $course->modules_count) }}
                        </p>
                    @endif
                </div>
            </aside>
        </div>
    </section>

    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 lg:grid-cols-3">
        <div class="space-y-10 lg:col-span-2">
            @if (filled($course->full_description))
                <section>
                    <h2 class="mb-3 text-xl font-semibold text-slate-900 dark:text-white">About this course</h2>
                    <div class="prose prose-slate max-w-none dark:prose-invert">
                        {!! \App\Support\RichText::sanitize($course->full_description) !!}
                    </div>
                </section>
            @endif

            @if ($payload->outcomes !== [])
                <section>
                    <h2 class="mb-3 text-xl font-semibold text-slate-900 dark:text-white">What you will be able to do</h2>
                    <ul class="grid gap-2 sm:grid-cols-2">
                        @foreach ($payload->outcomes as $outcome)
                            <li class="flex gap-2 text-sm text-slate-700 dark:text-slate-200">
                                <x-ui.icon name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-500" />
                                <span>{{ $outcome }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($payload->requirements !== [])
                <section>
                    <h2 class="mb-3 text-xl font-semibold text-slate-900 dark:text-white">What you need to start</h2>
                    <ul class="space-y-2">
                        @foreach ($payload->requirements as $requirement)
                            <li class="flex gap-2 text-sm text-slate-700 dark:text-slate-200">
                                <x-ui.icon name="check" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />
                                <span>{{ $requirement }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- ------------------------------------------------ the outline --}}
            @if ($payload->hasOutline())
                <section x-data="{ open: 0 }">
                    <h2 class="mb-1 text-xl font-semibold text-slate-900 dark:text-white">What it covers</h2>
                    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
                        {{ $course->modules_count }} {{ \Illuminate\Support\Str::plural('module', $course->modules_count) }} ·
                        {{ $course->topics_count }} {{ \Illuminate\Support\Str::plural('topic', $course->topics_count) }} ·
                        {{ $course->lectures_count }} {{ \Illuminate\Support\Str::plural('lecture', $course->lectures_count) }}
                    </p>

                    <div class="divide-y divide-slate-200 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                        @foreach ($payload->modules as $index => $module)
                            <div>
                                <button type="button"
                                        x-on:click="open = open === {{ $index }} ? null : {{ $index }}"
                                        class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left">
                                    <span class="font-medium text-slate-900 dark:text-white">{{ $module->title }}</span>
                                    <span class="flex shrink-0 items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                                        <span>{{ $module->topics->count() }} {{ \Illuminate\Support\Str::plural('topic', $module->topics->count()) }}</span>
                                        <x-ui.icon name="chevron-down" class="h-4 w-4 transition-transform"
                                                   x-bind:class="open === {{ $index }} && 'rotate-180'" />
                                    </span>
                                </button>

                                <div x-show="open === {{ $index }}" x-cloak class="space-y-3 px-5 pb-5">
                                    @if (filled($module->description))
                                        <p class="text-sm text-slate-600 dark:text-slate-300">{{ $module->description }}</p>
                                    @endif

                                    @foreach ($module->topics as $topic)
                                        <div class="rounded-lg bg-slate-50 p-4 dark:bg-slate-800/60">
                                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $topic->title }}</p>

                                            @if ($topic->lectures->isNotEmpty())
                                                <ul class="mt-2 space-y-1">
                                                    @foreach ($topic->lectures as $lecture)
                                                        <li class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                                                            @if ($lecture->is_preview)
                                                                <x-ui.icon name="play-circle" class="h-4 w-4 shrink-0 text-emerald-500" />
                                                            @else
                                                                <x-ui.icon :name="$lecture->lecture_type->icon()" class="h-4 w-4 shrink-0 text-slate-400" />
                                                            @endif
                                                            <span>{{ $lecture->title }}</span>
                                                            @if ($lecture->is_preview)
                                                                <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">
                                                                    Free preview
                                                                </span>
                                                            @endif
                                                            @if ($lecture->duration_minutes)
                                                                <span class="ml-auto shrink-0 text-xs tabular-nums text-slate-400">
                                                                    {{ $lecture->duration_minutes }} min
                                                                </span>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif

                                            @if ($topic->publicResources->isNotEmpty())
                                                <ul class="mt-2 space-y-1 border-t border-slate-200 pt-2 dark:border-slate-700">
                                                    @foreach ($topic->publicResources as $resource)
                                                        <li class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                                            <x-ui.icon :name="$resource->type->icon()" class="h-3.5 w-3.5 shrink-0" />
                                                            @if ($resource->isLink() && $resource->external_url)
                                                                <a href="{{ $resource->external_url }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ $resource->title }}</a>
                                                            @elseif ($resource->file_path && $resource->is_downloadable)
                                                                <a href="{{ route('site.courses.resource', [$course->slug, $resource->id]) }}" class="hover:underline">{{ $resource->title }}</a>
                                                            @else
                                                                <span>{{ $resource->title }}</span>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($payload->trainers->isNotEmpty())
                <section>
                    <h2 class="mb-3 text-xl font-semibold text-slate-900 dark:text-white">Who teaches it</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($payload->trainers as $trainer)
                            <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $trainer->name ?? '' }}</p>
                                @if (! empty($trainer->public_bio))
                                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $trainer->public_bio }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($payload->upcomingBatches->isNotEmpty())
                <section>
                    <h2 class="mb-3 text-xl font-semibold text-slate-900 dark:text-white">Upcoming batches</h2>
                    <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                <tr>
                                    <th class="px-4 py-3">Starts</th>
                                    <th class="px-4 py-3">Timing</th>
                                    <th class="px-4 py-3">Seats</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($payload->upcomingBatches as $batch)
                                    <tr>
                                        <td class="px-4 py-3 text-slate-700 dark:text-slate-200">
                                            {{ $batch->start_date ? app_date($batch->start_date) : 'To be announced' }}
                                        </td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $batch->timing ?? '—' }}</td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                            {{ isset($batch->student_capacity, $batch->current_students)
                                                ? max(0, $batch->student_capacity - $batch->current_students).' left'
                                                : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            @if ($payload->admissionOpen)
                                                <a href="{{ $payload->applyUrl }}"
                                                   class="text-sm font-semibold text-slate-900 hover:underline dark:text-white">Apply</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            @if ($payload->reviews->isNotEmpty())
                <section>
                    <h2 class="mb-3 text-xl font-semibold text-slate-900 dark:text-white">What students say</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($payload->reviews as $review)
                            <blockquote class="rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                                <p class="text-sm text-slate-700 dark:text-slate-200">{{ $review->review }}</p>
                                <footer class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                                    — {{ $review->student_name }}
                                </footer>
                            </blockquote>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($payload->faqs->isNotEmpty())
                <section x-data="{ faq: null }">
                    <h2 class="mb-3 text-xl font-semibold text-slate-900 dark:text-white">Questions</h2>
                    <div class="divide-y divide-slate-200 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                        @foreach ($payload->faqs as $index => $faq)
                            <div>
                                <button type="button" x-on:click="faq = faq === {{ $index }} ? null : {{ $index }}"
                                        class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left">
                                    <span class="font-medium text-slate-900 dark:text-white">{{ $faq->question }}</span>
                                    <x-ui.icon name="chevron-down" class="h-4 w-4 shrink-0 text-slate-400 transition-transform"
                                               x-bind:class="faq === {{ $index }} && 'rotate-180'" />
                                </button>
                                <div x-show="faq === {{ $index }}" x-cloak class="px-5 pb-5">
                                    <div class="prose prose-sm max-w-none dark:prose-invert">
                                        {!! \App\Support\RichText::sanitize((string) $faq->answer) !!}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        <aside class="space-y-6 lg:col-span-1">
            @if ($payload->publicResources->isNotEmpty())
                <div class="rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        Course material
                    </h3>
                    <ul class="space-y-2">
                        @foreach ($payload->publicResources as $resource)
                            <li class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                <x-ui.icon :name="$resource->type->icon()" class="h-4 w-4 shrink-0 text-slate-400" />
                                @if ($resource->isLink() && $resource->external_url)
                                    <a href="{{ $resource->external_url }}" target="_blank" rel="noopener noreferrer" class="hover:underline">{{ $resource->title }}</a>
                                @elseif ($resource->file_path && $resource->is_downloadable)
                                    <a href="{{ route('site.courses.resource', [$course->slug, $resource->id]) }}" class="hover:underline">{{ $resource->title }}</a>
                                @else
                                    <span>{{ $resource->title }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($course->category)
                <div class="rounded-xl border border-slate-200 p-5 dark:border-slate-800">
                    <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        More like this
                    </h3>
                    <a href="{{ route('site.courses.category', $course->category->slug) }}"
                       class="text-sm font-medium text-slate-900 hover:underline dark:text-white">
                        All {{ $course->category->name }} courses →
                    </a>
                </div>
            @endif
        </aside>
    </div>

    {{-- The mobile footer: the one action, always reachable. --}}
    @if ($payload->admissionOpen)
        <div class="sticky bottom-0 z-10 border-t border-slate-200 bg-white/95 p-3 backdrop-blur lg:hidden dark:border-slate-800 dark:bg-slate-900/95">
            <a href="{{ $payload->applyUrl }}"
               class="block rounded-lg bg-slate-900 px-4 py-3 text-center text-sm font-semibold text-white dark:bg-white dark:text-slate-900">
                Apply for {{ \Illuminate\Support\Str::limit($course->name, 28) }}
            </a>
        </div>
    @endif
@endsection

@push('head')
    {{-- A Course object, built from the row rather than restated by hand. --}}
    @php
        $jsonLd = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Course',
            'name' => $course->name,
            'description' => $course->short_description,
            'url' => route('site.courses.show', $course->slug),
            'provider' => array_filter([
                '@type' => 'Organization',
                'name' => site_setting('company.name'),
                'sameAs' => site_setting('company.website'),
            ]),
            'educationalLevel' => $course->level->label(),
            'timeRequired' => $hours !== null ? 'PT'.$hours.'H' : null,
        ]);

        // The array is built here and not inside @json: Blade's @json directive splits its argument on
        // every top-level comma to find the flags, so an array literal passed straight to it compiles
        // to PHP that does not parse. The HEX flags matter — a course name containing `</script>`
        // would otherwise close the tag.
        $jsonLdFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    @endphp
    <script nonce="{{ csp_nonce() }}" type="application/ld+json">@json($jsonLd, $jsonLdFlags)</script>
@endpush
