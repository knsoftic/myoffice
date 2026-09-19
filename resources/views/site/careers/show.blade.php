{{--
    One job opening with its inline application form — site.careers.show (phase-04 §8.11, §2.18, §6.8; requirement §16).

    Controller variables (Site\CareerController@show, through ComposesContentPages::contentPage()):
      $site                 the SitePayload, seo = SeoService::for($job)
      $page                 array{title, slug}
      $job                  App\Models\Cms\JobOpening — a draft is a 404; a closed, filled or expired job still renders,
                            read-only. salary_min / salary_max are nulled on this instance when salary_visible is false
      $acceptsApplications  bool   JobOpening::public() holds it (open AND deadline null or >= today)
      $form                 ?array{action, cvMaxKb, cvMaxLabel, cvExtensions, cvAccept, spam{honeypot, token_field, token}}
                            null when applications are closed (see site/careers/partials/apply-form)
      $submitted            bool   the `application_submitted` flash — the confirmation is identical for a real application
                            and for silently discarded spam
      $consentText          optional ?string

    The page carries the form, so its route is not behind the public page cache (C.4); the tokens are printed inline.
--}}

@extends('site.layouts.public')

@php
    use Illuminate\Support\Facades\Route;

    $form = is_array($form ?? null) ? $form : null;
    $accepts = (bool) ($acceptsApplications ?? false) && $form !== null;
    $applied = (bool) ($submitted ?? ($applied ?? false));
    $workMode = $job->work_mode instanceof \BackedEnum ? $job->work_mode : null;
    $employment = $job->employment_type instanceof \BackedEnum ? $job->employment_type : null;
    $periods = ['monthly' => 'per month', 'yearly' => 'per year', 'hourly' => 'per hour', 'project' => 'per project'];
    $skills = collect((array) ($job->skills ?? []))->filter()->values();

    $today = \App\Support\Format::carbon(app_date(now(), 'Y-m-d'));
    $deadline = $job->deadline ? \App\Support\Format::carbon(app_date($job->deadline, 'Y-m-d')) : null;
    $daysLeft = $deadline && $today ? (int) $today->diffInDays($deadline, false) : null;
    $status = $job->status instanceof \BackedEnum ? $job->status->value : (string) $job->status;

    $closedReason = match (true) {
        $status === 'filled' => 'This position has been filled.',
        $daysLeft !== null && $daysLeft < 0 => 'The application deadline has passed.',
        default => 'Applications are closed.',
    };
@endphp

@section('title', $job->title)

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => $job->title,
        'eyebrow' => $job->department,
        'crumbs' => [['label' => 'Careers', 'url' => route('site.careers.index')]],
    ])

    <x-site.section background="surface">
        <div class="lg:grid lg:grid-cols-12 lg:gap-12">
            <article class="lg:col-span-7">
                <dl class="grid grid-cols-2 gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm sm:grid-cols-4 dark:border-white/10 dark:bg-white/[0.03]">
                    @if (filled($job->location))
                        <div><dt class="text-slate-500 dark:text-slate-400">Location</dt><dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ $job->location }}</dd></div>
                    @endif
                    @if ($workMode)
                        <div><dt class="text-slate-500 dark:text-slate-400">Work mode</dt><dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ method_exists($workMode, 'label') ? $workMode->label() : $workMode->value }}</dd></div>
                    @endif
                    @if ($employment)
                        <div><dt class="text-slate-500 dark:text-slate-400">Type</dt><dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ method_exists($employment, 'label') ? $employment->label() : $employment->value }}</dd></div>
                    @endif
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Salary</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">
                            @if ($job->salary_visible && ($job->salary_min !== null || $job->salary_max !== null))
                                {{ $job->salary_min !== null ? money((string) $job->salary_min) : '' }}{{ $job->salary_min !== null && $job->salary_max !== null ? ' – ' : '' }}{{ $job->salary_max !== null ? money((string) $job->salary_max) : '' }}
                                <span class="block text-xs font-normal text-slate-500 dark:text-slate-400">{{ $periods[(string) $job->salary_period] ?? '' }}</span>
                            @else
                                Negotiable
                            @endif
                        </dd>
                    </div>
                    @if ($job->experience_min_years !== null || filled($job->experience_note))
                        <div class="col-span-2">
                            <dt class="text-slate-500 dark:text-slate-400">Experience</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">
                                {{ $job->experience_min_years !== null ? app_number((int) $job->experience_min_years).'+ years' : '' }}{{ $job->experience_min_years !== null && filled($job->experience_note) ? ' · ' : '' }}{{ $job->experience_note }}
                            </dd>
                        </div>
                    @endif
                    @if ((int) $job->openings_count > 1)
                        <div><dt class="text-slate-500 dark:text-slate-400">Openings</dt><dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">{{ app_number((int) $job->openings_count) }}</dd></div>
                    @endif
                    @if ($deadline)
                        <div class="col-span-2">
                            <dt class="text-slate-500 dark:text-slate-400">Deadline</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white">
                                <time datetime="{{ app_date($job->deadline, 'Y-m-d') }}">{{ app_date($job->deadline) }}</time>
                                @if ($daysLeft !== null && $daysLeft >= 0 && $accepts)
                                    <span @class(['ml-1 text-xs font-medium', 'text-rose-600 dark:text-rose-400' => $daysLeft <= 3, 'text-slate-500 dark:text-slate-400' => $daysLeft > 3])>
                                        {{ $daysLeft === 0 ? '· closes today' : '· '.app_number($daysLeft).' '.($daysLeft === 1 ? 'day' : 'days').' left' }}
                                    </span>
                                @endif
                            </dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-10">
                    <x-site.prose :html="$job->description" size="lg" />
                </div>

                @if (filled($job->responsibilities))
                    <section class="mt-10" aria-labelledby="job-responsibilities">
                        <h2 id="job-responsibilities" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Responsibilities</h2>
                        <x-site.prose :html="$job->responsibilities" class="mt-4" />
                    </section>
                @endif

                @if (filled($job->requirements))
                    <section class="mt-10" aria-labelledby="job-requirements">
                        <h2 id="job-requirements" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Requirements</h2>
                        <x-site.prose :html="$job->requirements" class="mt-4" />
                    </section>
                @endif

                @if ($skills->isNotEmpty())
                    <section class="mt-10" aria-labelledby="job-skills">
                        <h2 id="job-skills" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Skills</h2>
                        <ul role="list" class="mt-4 flex flex-wrap gap-2">
                            @foreach ($skills as $skill)
                                <li class="rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700 dark:bg-white/5 dark:text-slate-300">{{ $skill }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </article>

            <aside id="apply" class="mt-12 scroll-mt-28 lg:col-span-5 lg:mt-0" aria-labelledby="apply-heading">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-card sm:p-8 dark:border-white/10 dark:bg-slate-900">
                    <h2 id="apply-heading" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $applied ? 'Application received' : 'Apply for this role' }}</h2>

                    @if ($applied)
                        <div class="mt-5 rounded-xl bg-emerald-50 p-5 text-emerald-900 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-100 dark:ring-emerald-500/25" role="status">
                            <x-ui.icon name="check-circle" class="h-8 w-8 text-emerald-600 dark:text-emerald-400" />
                            <p class="mt-3 font-semibold">Thank you — your application is with our hiring team.</p>
                            <p class="mt-1 text-sm">We read every application and will contact you if your profile matches the role.</p>
                        </div>
                        <p class="mt-5 text-sm"><a href="{{ route('site.careers.index') }}" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">See other openings</a></p>
                    @elseif ($accepts)
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">It takes about five minutes. Fields marked <span class="text-rose-500">*</span> are required.</p>
                        <div class="mt-6">
                            @include('site.careers.partials.apply-form', [
                                'job' => $job,
                                'form' => $form,
                                'consentText' => $consentText ?? null,
                            ])
                        </div>
                    @else
                        <div class="mt-5 rounded-xl bg-slate-100 p-5 text-slate-700 ring-1 ring-slate-200 dark:bg-white/5 dark:text-slate-300 dark:ring-white/10" role="status">
                            <p class="font-semibold text-slate-900 dark:text-white">Applications are closed</p>
                            <p class="mt-1 text-sm">{{ $closedReason }}</p>
                        </div>
                        <p class="mt-5 text-sm"><a href="{{ route('site.careers.index') }}" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">See current openings</a></p>
                    @endif
                </div>
            </aside>
        </div>
    </x-site.section>
@endsection
