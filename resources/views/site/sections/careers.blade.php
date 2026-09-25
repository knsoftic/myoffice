{{--
    Section type `careers` — the careers teaser, declared by Phase 4 (phase-04 §8.11, §9.2; requirement §16).

    Receives:
      $section   the published snapshot (id, anchor, provider)
      $content   heading, description, view_all_link (optional)
      $section['provider']  App\Support\Cms\Sections\CareersSectionProvider (`is_live`: an expired job leaves the page the day
                 it closes) — ONLY JobOpening::public() rows (open, deadline not passed), featured first:
                   available: bool
                   items: list<array{id: int, title: string, slug: string, url: string, department: ?string, location: ?string,
                          work_mode: ?array{value, label}, employment_type: ?array{value, label}, deadline: ?string ('Y-m-d'),
                          salary: ?array{min: ?string, max: ?string, period: string}  (null when hidden — "Negotiable")}>
                   index_url: ?string   (null, with no items, while careers are switched off)

    Renders nothing when no job is open.
--}}

@php
    $fields = (array) ($content ?? []);
    $provider = data_get($section ?? null, 'provider');
    $jobs = collect(is_array(data_get($provider, 'items')) ? data_get($provider, 'items') : [])
        ->filter(static fn ($job): bool => filled(data_get($job, 'title')))
        ->take(8)
        ->values();

    $heading = trim((string) data_get($fields, 'heading', ''));
    $heading = $heading !== '' ? $heading : 'We are hiring';
    $description = trim((string) data_get($fields, 'description', ''));
    $viewAll = data_get($fields, 'view_all_link');
    $safeUrl = static fn ($url): ?string => is_string($url) && preg_match('~^(https?://|/)~i', trim($url)) === 1 ? trim($url) : null;
    $careersUrl = $safeUrl(data_get($provider, 'index_url'));
    $periods = ['monthly' => 'per month', 'yearly' => 'per year', 'hourly' => 'per hour', 'project' => 'per project'];
@endphp

@if ($jobs->isNotEmpty())
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="surface" :label="$heading">
        <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <x-site.heading data-fx="rise" :title="$heading" :subtitle="$description !== '' ? $description : null" align="left" />
            @if (is_array($viewAll) && filled($viewAll['label'] ?? null) && filled($viewAll['url'] ?? null))
                <x-site.button data-fx="rise" data-fx-delay="1" :link="$viewAll" icon="arrow-right" class="shrink-0" />
            @elseif ($careersUrl)
                <x-site.button data-fx="rise" data-fx-delay="1" label="All openings" :url="$careersUrl" style="outline" icon="arrow-right" class="shrink-0" />
            @endif
        </div>

        <ul role="list" class="mt-10 divide-y divide-slate-200 overflow-hidden rounded-2xl border border-slate-200 bg-white dark:divide-white/10 dark:border-white/10 dark:bg-slate-900">
            @foreach ($jobs as $job)
                @php
                    $jobUrl = $safeUrl(data_get($job, 'url'));
                    $salary = data_get($job, 'salary');
                    $salaryText = is_array($salary) && (filled($salary['min'] ?? null) || filled($salary['max'] ?? null))
                        ? trim((filled($salary['min'] ?? null) ? money((string) $salary['min']) : '').(filled($salary['min'] ?? null) && filled($salary['max'] ?? null) ? ' – ' : '').(filled($salary['max'] ?? null) ? money((string) $salary['max']) : '').' '.($periods[(string) ($salary['period'] ?? '')] ?? ''))
                        : 'Negotiable';
                    $meta = collect([data_get($job, 'location'), data_get($job, 'work_mode.label'), data_get($job, 'department')])->filter()->implode(' · ');
                @endphp
                <li data-fx="rise" data-fx-delay="{{ ($loop->index % 4) + 1 }}" class="group relative flex flex-col gap-2 p-5 transition hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-white/[0.03]">
                    <div class="min-w-0">
                        <h3 class="font-semibold text-slate-900 dark:text-white">
                            @if ($jobUrl)
                                <a href="{{ $jobUrl }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ data_get($job, 'title') }}</a>
                            @else
                                {{ data_get($job, 'title') }}
                            @endif
                        </h3>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500 dark:text-slate-400">
                            @if ($meta !== '')
                                <span>{{ $meta }}</span>
                            @endif
                            @if (filled(data_get($job, 'employment_type.label')))
                                <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-white/5 dark:text-slate-300">{{ data_get($job, 'employment_type.label') }}</span>
                            @endif
                            @if (filled(data_get($job, 'deadline')))
                                <span>Apply by {{ app_date(data_get($job, 'deadline')) }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-4">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ $salaryText }}</span>
                        <x-ui.icon name="arrow-right" class="h-5 w-5 text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-brand-600 dark:group-hover:text-brand-400" />
                    </div>
                </li>
            @endforeach
        </ul>
    </x-site.section>
@endif
