{{--
    The public fee structure — site.fees.index. A 404 before this view when
    website.fee_structure_page_enabled is false.

    Controller variables (Site\FeeStructureController@index, through ComposesContentPages::contentPage()):
      $site      the SitePayload (seo for route_key site.fees.index)
      $page      array{title, slug}
      $courses   Collection<App\Models\Institute\Course> — Course::published() inside a live category,
                 in catalogue order; only the columns the price list needs are selected
      $groups    array<string, list<Course>>  category name => courses; the '' key (no category) is last
      $heading   optional string (default "Fee structure")
      $intro     optional string

    **No arithmetic here.** Each of the four fee columns is a price the institute set, rendered on its
    own through money(); nothing on this page adds two of them together. A zero is an em dash rather
    than "Rs 0.00" — a fee the institute does not charge is not a fee of nothing — and a course whose
    own course_fee is zero says "on request" instead of quoting a confident free.

    The tables scroll inside their own container, so seven columns never push a phone sideways. A table
    ROW carries no data-fx: the wrapper card tilts once, the rows do not.
--}}

@extends('site.layouts.public')

@php
    use App\Support\Money;
    use Illuminate\Support\Facades\Route;

    $courses = collect($courses ?? []);
    $groups = isset($groups) && is_array($groups)
        ? collect($groups)->map(static fn ($list) => collect($list))
        : $courses->groupBy(static fn ($course): string => trim((string) ($course->category?->name ?? '')))
            ->sortKeysUsing(static fn (string $a, string $b): int => ($a === '') <=> ($b === ''));

    $heading = $heading ?? 'Fee structure';
    $intro = $intro ?? 'What every course we run costs, with the one-off charges and the monthly instalment shown separately.';

    // A comparison, never a sum: bcmath through Money, and the column is printed exactly as stored.
    $charged = static fn ($amount): bool => Money::isPositive((string) ($amount ?? '0'));
    $courseUrl = static fn ($course): ?string => Route::has('site.courses.show') && filled($course->slug)
        ? route('site.courses.show', $course->slug)
        : null;
    $admissionUrl = Route::has('site.admission.create') ? route('site.admission.create') : null;
@endphp

@section('title', $heading)

@section('content')
    @include('site.marketing.partials.page-hero', ['title' => $heading, 'subtitle' => $intro])

    <x-site.section background="surface">
        @if ($courses->isEmpty())
            <div class="mx-auto max-w-xl rounded-2xl border border-dashed border-slate-300 dark:border-white/10">
                <x-ui.empty-state
                    icon="banknotes"
                    title="Fees are not published yet"
                    message="The course fee list is being prepared. Get in touch and we will quote you directly."
                />
            </div>
        @else
            <div class="space-y-14">
                @foreach ($groups as $category => $categoryCourses)
                    <section aria-labelledby="fees-group-{{ $loop->index }}">
                        <h2 data-fx="rise" id="fees-group-{{ $loop->index }}" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                            {{ $category !== '' ? $category : ($groups->count() > 1 ? 'Other courses' : 'Our courses') }}
                        </h2>
                        <p data-fx="rise" data-fx-delay="1" class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                            {{ $categoryCourses->count() }} {{ \Illuminate\Support\Str::plural('course', $categoryCourses->count()) }}. All amounts are in {{ Money::currencyCode() }}.
                        </p>

                        {{-- The wrapper tilts once; the rows inside it never do. --}}
                        <div data-fx="tilt" data-fx-delay="{{ ($loop->index % 4) + 1 }}" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card dark:border-white/10 dark:bg-slate-900">
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[46rem] divide-y divide-slate-200 text-left text-sm dark:divide-white/10">
                                    <caption class="sr-only">Fees for {{ $category !== '' ? $category : 'our courses' }}</caption>
                                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-white/[0.03] dark:text-slate-400">
                                        <tr>
                                            <th scope="col" class="px-4 py-3 font-semibold">Course</th>
                                            <th scope="col" class="px-4 py-3 font-semibold">Duration</th>
                                            <th scope="col" class="px-4 py-3 text-right font-semibold">Course fee</th>
                                            <th scope="col" class="px-4 py-3 text-right font-semibold">Admission</th>
                                            <th scope="col" class="px-4 py-3 text-right font-semibold">Registration</th>
                                            <th scope="col" class="px-4 py-3 text-right font-semibold">Monthly</th>
                                            <th scope="col" class="px-4 py-3 font-semibold">Instalments</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                                        @foreach ($categoryCourses as $course)
                                            @php($url = $courseUrl($course))
                                            <tr class="align-top transition-colors hover:bg-slate-50 dark:hover:bg-white/[0.03]">
                                                <th scope="row" class="px-4 py-4 font-normal">
                                                    <span class="block font-semibold text-slate-900 dark:text-white">
                                                        @if ($url)
                                                            <a href="{{ $url }}" class="rounded hover:text-brand-600 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:hover:text-brand-400">{{ $course->name }}</a>
                                                        @else
                                                            {{ $course->name }}
                                                        @endif
                                                    </span>
                                                    <span class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                                        @if (filled($course->code))
                                                            <span class="font-mono">{{ $course->code }}</span>
                                                        @endif
                                                        @if ($course->certificate_available)
                                                            <span class="rounded-full bg-sky-100 px-2 py-0.5 font-medium text-sky-800 dark:bg-sky-500/15 dark:text-sky-300">Certificate</span>
                                                        @endif
                                                        @if ($course->admission_open)
                                                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">Admissions open</span>
                                                        @endif
                                                    </span>
                                                </th>
                                                <td class="whitespace-nowrap px-4 py-4 text-slate-600 dark:text-slate-300">
                                                    {{ $course->durationLabel() ?? '—' }}
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-4 text-right font-semibold tabular-nums text-slate-900 dark:text-white">
                                                    @if ($charged($course->course_fee))
                                                        {{ money($course->course_fee) }}
                                                    @else
                                                        <span class="font-medium text-slate-500 dark:text-slate-400">On request</span>
                                                    @endif
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-4 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                                    {{ $charged($course->admission_fee) ? money($course->admission_fee) : '—' }}
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-4 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                                    {{ $charged($course->registration_fee) ? money($course->registration_fee) : '—' }}
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-4 text-right tabular-nums text-slate-600 dark:text-slate-300">
                                                    {{ $charged($course->monthly_fee) ? money($course->monthly_fee) : '—' }}
                                                </td>
                                                <td class="px-4 py-4 text-slate-600 dark:text-slate-300">
                                                    @if ($course->installment_available)
                                                        <span class="block whitespace-nowrap">
                                                            @if ((int) $course->max_installments > 0)
                                                                Up to {{ $course->max_installments }} {{ \Illuminate\Support\Str::plural('instalment', (int) $course->max_installments) }}
                                                            @else
                                                                Available
                                                            @endif
                                                        </span>
                                                        @if (filled($course->installment_note))
                                                            <span class="mt-1 block max-w-[18rem] text-xs text-slate-500 dark:text-slate-400">{{ $course->installment_note }}</span>
                                                        @endif
                                                    @else
                                                        <span class="text-slate-400 dark:text-slate-500">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                @endforeach

                <aside data-fx="deck" class="rounded-2xl border border-slate-200 bg-slate-50 p-6 sm:p-8 dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">How to read this table</h2>
                    <ul role="list" class="mt-4 grid gap-3 text-sm text-slate-600 sm:grid-cols-2 dark:text-slate-300">
                        <li class="flex gap-2">
                            <x-ui.icon name="banknotes" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400" />
                            <span>Each charge is listed on its own line. The course fee, the admission fee and the registration fee are one-off; the monthly amount recurs for the length of the course.</span>
                        </li>
                        <li class="flex gap-2">
                            <x-ui.icon name="information-circle" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400" />
                            <span>An em dash means that charge does not apply to that course. "On request" means the fee depends on the batch and we will quote it for you.</span>
                        </li>
                        <li class="flex gap-2">
                            <x-ui.icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400" />
                            <span>Where instalments are available, the number shown is the maximum we split the fee into.</span>
                        </li>
                        <li class="flex gap-2">
                            <x-ui.icon name="academic-cap" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400" />
                            <span>Open a course to see what it covers, how it is delivered and when the next batch starts.</span>
                        </li>
                    </ul>

                    @if ($admissionUrl)
                        <div class="mt-6">
                            <a href="{{ $admissionUrl }}" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100">
                                Apply for admission
                                <x-ui.icon name="arrow-right" class="h-4 w-4" />
                            </a>
                        </div>
                    @endif
                </aside>
            </div>
        @endif
    </x-site.section>
@endsection
