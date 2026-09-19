{{--
    The careers page — site.careers.index (phase-04 §8.11, §2.18, §9.2; requirement §16). A 404 before this view when
    website.careers_enabled is false or the `jobs` module is disabled.

    Controller variables (Site\CareerController@index, through ComposesContentPages::contentPage()):
      $site                the SitePayload (seo for route_key site.careers.index)
      $page                array{title, slug}
      $openings            Collection<App\Models\Cms\JobOpening> — JobOpening::public() only (status open AND deadline
                           null or >= today), featured first then sort_order; hidden salaries nulled on the instances
      $groups              array<string, list<JobOpening>>  department label => openings; the '' key (no department) last
      $contactUrl          ?string   route('site.contact.index')
      $noOpeningsMessage   optional ?string   the "no current openings" message

    Salary shows through money() only when salary_visible; otherwise "Negotiable", and the figures never reach the HTML.
--}}

@extends('site.layouts.public')

@php
    use Illuminate\Support\Facades\Route;

    $jobs = collect($openings ?? ($jobs ?? []));
    $groups = isset($groups) && is_array($groups)
        ? collect($groups)->map(static fn ($list) => collect($list))
        : $jobs->groupBy(static fn ($job): string => trim((string) $job->department))
            ->sortKeysUsing(static fn (string $a, string $b): int => ($a === '') <=> ($b === ''));
    $heading = (string) data_get($page ?? null, 'title', 'Careers');
    $intro = $intro ?? 'Build real products with a team that ships. See where you could fit in.';
    $contactUrl = isset($contactUrl) && is_string($contactUrl)
        ? $contactUrl.(str_contains($contactUrl, '?') ? '&' : '?').'type=general&subject='.rawurlencode('Speculative application')
        : (Route::has('site.contact.index') ? route('site.contact.index', ['type' => 'general', 'subject' => 'Speculative application']) : null);
    $periods = ['monthly' => 'per month', 'yearly' => 'per year', 'hourly' => 'per hour', 'project' => 'per project'];
    $today = app_date(now(), 'Y-m-d');
@endphp

@section('title', $heading)

@section('content')
    @include('site.marketing.partials.page-hero', ['title' => $heading, 'subtitle' => $intro])

    <x-site.section background="surface">
        @if ($jobs->isEmpty())
            <div class="mx-auto max-w-2xl rounded-2xl border border-dashed border-slate-300 px-6 py-14 text-center dark:border-white/10">
                <x-ui.icon name="briefcase" class="mx-auto h-8 w-8 text-slate-400" />
                <h2 class="mt-4 text-xl font-semibold text-slate-900 dark:text-white">No current openings</h2>
                <p class="mt-3 text-pretty text-slate-600 dark:text-slate-400">{{ filled($noOpeningsMessage ?? null) ? $noOpeningsMessage : 'We are not hiring for a specific role right now, but we are always glad to hear from talented people.' }}</p>
                @if ($contactUrl)
                    <div class="mt-6 flex justify-center">
                        <x-site.button label="Send us a speculative application" :url="$contactUrl" style="primary" icon="arrow-right" />
                    </div>
                @endif
            </div>
        @else
            <div class="space-y-14">
                @foreach ($groups as $department => $departmentJobs)
                    <section aria-labelledby="careers-group-{{ $loop->index }}">
                        <div class="flex items-baseline justify-between gap-4 border-b border-slate-200 pb-3 dark:border-white/10">
                            <h2 id="careers-group-{{ $loop->index }}" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $department !== '' ? $department : 'Other roles' }}</h2>
                            <span class="text-sm text-slate-500 dark:text-slate-400">{{ app_number($departmentJobs->count()) }} {{ $departmentJobs->count() === 1 ? 'opening' : 'openings' }}</span>
                        </div>

                        <ul role="list" class="divide-y divide-slate-200 dark:divide-white/10">
                            @foreach ($departmentJobs as $job)
                                @php
                                    $workMode = $job->work_mode instanceof \BackedEnum ? $job->work_mode : null;
                                    $employment = $job->employment_type instanceof \BackedEnum ? $job->employment_type : null;
                                    $deadlineKey = $job->deadline ? app_date($job->deadline, 'Y-m-d') : null;
                                    $showUrl = route('site.careers.show', $job->slug);
                                @endphp
                                <li class="group relative flex flex-col gap-4 py-6 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">
                                                <a href="{{ $showUrl }}" class="after:absolute after:inset-0 focus-visible:outline-none group-hover:text-brand-700 dark:group-hover:text-brand-300">{{ $job->title }}</a>
                                            </h3>
                                            @if ($job->is_featured)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/25"><x-ui.icon name="star" class="h-3 w-3" /> Featured</span>
                                            @endif
                                        </div>
                                        <p class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-600 dark:text-slate-400">
                                            @if (filled($job->location))
                                                <span class="inline-flex items-center gap-1"><x-ui.icon name="building-office" class="h-4 w-4" /> {{ $job->location }}</span>
                                            @endif
                                            @if ($workMode)
                                                <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-white/5 dark:text-slate-300">{{ method_exists($workMode, 'label') ? $workMode->label() : $workMode->value }}</span>
                                            @endif
                                            @if ($employment)
                                                <span>{{ method_exists($employment, 'label') ? $employment->label() : $employment->value }}</span>
                                            @endif
                                            <span class="font-medium text-slate-700 dark:text-slate-300">
                                                @if ($job->salary_visible && ($job->salary_min !== null || $job->salary_max !== null))
                                                    {{ $job->salary_min !== null ? money((string) $job->salary_min) : '' }}{{ $job->salary_min !== null && $job->salary_max !== null ? ' – ' : '' }}{{ $job->salary_max !== null ? money((string) $job->salary_max) : '' }}
                                                    <span class="font-normal text-slate-500 dark:text-slate-400">{{ $periods[(string) $job->salary_period] ?? '' }}</span>
                                                @else
                                                    Negotiable
                                                @endif
                                            </span>
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-4">
                                        @if ($deadlineKey)
                                            <span class="text-sm text-slate-500 dark:text-slate-400">Apply by <time datetime="{{ $deadlineKey }}">{{ app_date($job->deadline) }}</time></span>
                                        @endif
                                        <span class="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition group-hover:bg-brand-700 dark:bg-brand-500 dark:group-hover:bg-brand-400" aria-hidden="true">Apply <x-ui.icon name="arrow-right" class="h-4 w-4" /></span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>

            @if ($contactUrl)
                <p class="mt-14 text-center text-slate-600 dark:text-slate-400">
                    Don’t see your role? <a href="{{ $contactUrl }}" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">Tell us what you do best</a>.
                </p>
            @endif
        @endif
    </x-site.section>
@endsection
