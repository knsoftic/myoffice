@extends('layouts.admin')

@section('title', 'Application')

{{--
    One job application — admin.job-applications.show (phase-04 §8.9 "Detail screen", §6.8, §9.1.3, D21).
    JobApplicationPolicy::view() has passed.

    Controller variables (Admin\JobApplicationController@show):
      $application       App\Models\Cms\JobApplication with jobOpening, assignee, statusChanger
      $timeline          Collection<App\Models\Activity> for this application, newest first, with causer — the stage
                         changes (properties.old.status → properties.attributes.status, reason, interview slot) and the
                         CV downloads
      $reviewerOptions   array<int, string>   for the assign dialog
      $canUpdate         bool   JobApplicationPolicy::update()   — internal notes and rating are rendered only then
      $canDownload       bool   JobApplicationPolicy::download() — the Download CV button is disabled otherwise
      $canDelete         bool   JobApplicationPolicy::delete()

    Writes: PUT admin.job-applications.update {application} internal_notes + rating (autosaved on blur);
    POST .status / .assign (dialogs); DELETE .destroy; DELETE .force-destroy (removes the CV file too);
    GET .cv (the only path to the private file, Content-Disposition: attachment).
--}}

@php
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $job = $application->relationLoaded('jobOpening') ? $application->jobOpening : null;
    $assignee = null;
    foreach (['assignee', 'assignedTo', 'reviewer'] as $relationName) {
        if ($application->relationLoaded($relationName)) {
            $assignee = $application->getRelation($relationName);
            break;
        }
    }

    $canUpdate = (bool) ($canUpdate ?? false) && Route::has('admin.job-applications.update');
    $canDownload = (bool) ($canDownload ?? false) && Route::has('admin.job-applications.cv');
    $canRemove = (bool) ($canDelete ?? false);
    $canStage = (bool) ($canChangeStatus ?? $user?->can('job_applications.change_status')) && Route::has('admin.job-applications.status');
    $canAssign = (bool) ($canAssign ?? $user?->can('job_applications.assign')) && Route::has('admin.job-applications.assign');

    $statusEnum = $application->status;
    $statusValue = $statusEnum instanceof \BackedEnum ? $statusEnum->value : (string) $statusEnum;
    $currentLabel = $statusEnum instanceof \BackedEnum && method_exists($statusEnum, 'label') ? $statusEnum->label() : \Illuminate\Support\Str::headline($statusValue);
    $nextOptions = $statusEnum instanceof \BackedEnum && method_exists($statusEnum, 'allowedNext')
        ? collect($statusEnum->allowedNext())->map(fn ($case) => ['value' => $case->value, 'label' => method_exists($case, 'label') ? $case->label() : $case->value])->values()->all()
        : [];

    $safeUrl = static fn (?string $url): ?string => is_string($url) && preg_match('~^https?://~i', $url) === 1 ? $url : null;
    $digits = static fn (?string $phone): string => preg_replace('/[^\d+]/', '', (string) $phone);
    $cvTypes = ['application/pdf' => 'PDF', 'application/msword' => 'Word (.doc)', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word (.docx)'];
    $cvSize = (int) ($application->cv_size ?? 0);
    $cvSizeLabel = $cvSize >= 1048576 ? app_number($cvSize / 1048576, 1).' MB' : app_number(max(1, (int) round($cvSize / 1024))).' KB';
    $timeline = collect($timeline ?? []);
@endphp

@section('header')
    <x-ui.page-header :title="$application->applicant_name" :subtitle="$job ? 'Applied for '.$job->title : 'Job application'" icon="document-text" :back="route('admin.job-applications.index', $job ? ['job' => $job->getKey()] : [])">
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @include('admin.marketing.partials.enum-badge', ['value' => $application->status])
            @include('admin.marketing.partials.enum-badge', ['value' => $application->source, 'dot' => false, 'variant' => 'outline'])
            <span class="text-xs text-slate-500 dark:text-slate-400">Applied {{ app_datetime($application->created_at) }}</span>
        </div>

        <x-slot:actions>
            @if ($canStage && $nextOptions !== [])
                <x-ui.button
                    icon="arrow-path"
                    x-on:click="$dispatch('open-modal', { name: 'application-stage', url: @js(route('admin.job-applications.status', $application)), label: @js($application->applicant_name), current: @js($currentLabel), options: @js($nextOptions) })"
                >Change stage</x-ui.button>
            @endif

            @if ($canRemove)
                <x-ui.confirm
                    :action="route('admin.job-applications.destroy', $application)"
                    :title="'Delete the application of '.$application->applicant_name.'?'"
                    message="It moves to the trash. The CV file is kept so a restore loses nothing."
                    confirm-label="Delete application"
                >
                    <x-slot:trigger>
                        <x-ui.icon-button icon="trash" variant="danger" label="Delete application" />
                    </x-slot:trigger>
                </x-ui.confirm>

                @if (Route::has('admin.job-applications.force-destroy'))
                    <x-ui.confirm
                        :action="route('admin.job-applications.force-destroy', $application)"
                        :title="'Permanently delete the application of '.$application->applicant_name.'?'"
                        message="The row and the CV file are removed for good, and this address may apply to the opening again. This cannot be undone."
                        confirm-label="Delete permanently"
                        :require-text="$application->email"
                    >
                        <x-slot:trigger>
                            <x-ui.button variant="ghost" size="sm" class="text-rose-600 dark:text-rose-400">Delete permanently</x-ui.button>
                        </x-slot:trigger>
                    </x-ui.confirm>
                @endif
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
        {{-- Left: the applicant --}}
        <div class="space-y-6 xl:col-span-3">
            <x-ui.card title="Applicant" icon="user">
                <div class="flex items-start gap-4">
                    <x-ui.avatar :name="$application->applicant_name" size="xl" />
                    <dl class="grid min-w-0 flex-1 grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Email</dt>
                            <dd><a href="mailto:{{ $application->email }}" class="break-all font-medium text-brand-700 hover:underline dark:text-brand-300">{{ $application->email }}</a></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Phone</dt>
                            <dd><a href="tel:{{ $digits($application->phone) }}" class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $application->phone }}</a></dd>
                        </div>
                        @if (filled($application->whatsapp))
                            <div>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">WhatsApp</dt>
                                <dd>
                                    <a href="https://wa.me/{{ ltrim($digits($application->whatsapp), '+') }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 font-medium text-emerald-700 hover:underline dark:text-emerald-400">
                                        <x-ui.icon name="whatsapp" class="h-4 w-4" /> {{ $application->whatsapp }}
                                    </a>
                                </dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">City</dt>
                            <dd class="text-slate-900 dark:text-white">{{ $application->city ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Experience</dt>
                            <dd class="text-slate-900 dark:text-white">{{ $application->experience_years !== null ? app_number((int) $application->experience_years).' years' : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Expected salary</dt>
                            <dd class="tabular-nums text-slate-900 dark:text-white">{{ $application->expected_salary !== null ? money((string) $application->expected_salary) : '—' }}</dd>
                        </div>
                        @if ($safeUrl($application->portfolio_url))
                            <div>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">Portfolio</dt>
                                <dd><a href="{{ $safeUrl($application->portfolio_url) }}" target="_blank" rel="nofollow noopener noreferrer" class="inline-flex items-center gap-1 break-all font-medium text-brand-700 hover:underline dark:text-brand-300">{{ \Illuminate\Support\Str::limit($application->portfolio_url, 48) }} <x-ui.icon name="arrow-top-right-on-square" class="h-3.5 w-3.5" /></a></dd>
                            </div>
                        @endif
                        @if ($safeUrl($application->linkedin_url))
                            <div>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">LinkedIn</dt>
                                <dd><a href="{{ $safeUrl($application->linkedin_url) }}" target="_blank" rel="nofollow noopener noreferrer" class="inline-flex items-center gap-1 break-all font-medium text-brand-700 hover:underline dark:text-brand-300">{{ \Illuminate\Support\Str::limit($application->linkedin_url, 48) }} <x-ui.icon name="arrow-top-right-on-square" class="h-3.5 w-3.5" /></a></dd>
                            </div>
                        @endif
                    </dl>
                </div>

                <div class="mt-5">
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Cover letter</p>
                    @if (filled($application->cover_letter))
                        <div class="whitespace-pre-line rounded-lg border border-slate-200 bg-slate-50/60 p-4 text-sm leading-relaxed text-slate-700 dark:border-slate-800 dark:bg-slate-950/40 dark:text-slate-200">{{ $application->cover_letter }}</div>
                    @else
                        <p class="text-sm text-slate-400 dark:text-slate-500">No cover letter was sent.</p>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card title="CV" icon="document">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-xs font-bold text-rose-600 ring-1 ring-inset ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20">
                            {{ \Illuminate\Support\Str::upper(pathinfo((string) $application->cv_original_name, PATHINFO_EXTENSION) ?: 'CV') }}
                        </span>
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-900 dark:text-white" title="{{ $application->cv_original_name }}">{{ $application->cv_original_name }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $cvTypes[(string) $application->cv_mime] ?? $application->cv_mime }} · {{ $cvSizeLabel }}</p>
                        </div>
                    </div>
                    @if ($canDownload)
                        <x-ui.button icon="arrow-down-tray" :href="route('admin.job-applications.cv', $application)">Download CV</x-ui.button>
                    @else
                        <span title="Downloading CVs needs the job applications download permission.">
                            <x-ui.button icon="arrow-down-tray" :disabled="true">Download CV</x-ui.button>
                        </span>
                    @endif
                </div>
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Stored on the private disk only. Every download is recorded in the audit trail.</p>
            </x-ui.card>
        </div>

        {{-- Right: pipeline, notes, rating, reviewer --}}
        <div class="space-y-6 xl:col-span-2">
            <x-ui.card title="Review" icon="clipboard-document-check">
                <div class="space-y-5">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Reviewer</p>
                            @if ($assignee)
                                <div class="mt-1 flex items-center gap-2">
                                    <x-ui.avatar :src="$assignee->avatar_url ?? null" :name="$assignee->name" size="sm" />
                                    <span class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $assignee->name }}</span>
                                </div>
                            @else
                                <p class="mt-1 text-sm text-slate-400">Unassigned</p>
                            @endif
                        </div>
                        @if ($canAssign)
                            <x-ui.button
                                variant="secondary"
                                size="sm"
                                icon="user-plus"
                                x-on:click="$dispatch('open-modal', { name: 'application-assign', url: @js(route('admin.job-applications.assign', $application)), label: @js($application->applicant_name), current: @js($application->assigned_to) })"
                            >{{ $assignee ? 'Reassign' : 'Assign' }}</x-ui.button>
                        @endif
                    </div>

                    @if ($statusValue === 'interview' && $application->interview_at)
                        <div class="rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                            <p class="font-semibold">Interview {{ app_datetime($application->interview_at) }}</p>
                            <p class="text-xs">{{ \Illuminate\Support\Str::headline((string) $application->interview_mode) }}@if (filled($application->interview_location)) · {{ $application->interview_location }}@endif</p>
                        </div>
                    @endif

                    @if ($statusValue === 'rejected' && filled($application->rejection_reason))
                        <p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25">Rejected: {{ $application->rejection_reason }}</p>
                    @endif

                    @if ($canUpdate)
                        <form
                            id="application-notes-form"
                            method="POST"
                            action="{{ route('admin.job-applications.update', $application) }}"
                            x-data="{
                                saved: @js((string) ($application->internal_notes ?? '')),
                                state: '',
                                async save(form) {
                                    const notes = form.querySelector('[name=internal_notes]').value;
                                    if (notes === this.saved) return;
                                    this.state = 'saving';
                                    try {
                                        const response = await fetch(form.action, {
                                            method: 'POST',
                                            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '', 'X-Requested-With': 'XMLHttpRequest' },
                                            credentials: 'same-origin',
                                            body: new FormData(form),
                                        });
                                        if (! response.ok) throw new Error();
                                        this.saved = notes;
                                        this.state = 'saved';
                                    } catch (error) {
                                        this.state = 'error';
                                    }
                                },
                            }"
                            class="space-y-4"
                        >
                            @csrf
                            @method('PUT')

                            <div>
                                <p class="mb-1 text-xs text-slate-500 dark:text-slate-400">Screening rating</p>
                                <div class="flex items-center gap-1" role="radiogroup" aria-label="Screening rating">
                                    @for ($star = 1; $star <= 5; $star++)
                                        <label class="cursor-pointer" title="{{ $star }} of 5">
                                            <input type="radio" name="rating" value="{{ $star }}" class="peer sr-only" @checked((int) old('rating', $application->rating) === $star) x-on:change="$el.form.requestSubmit ? $el.form.requestSubmit() : $el.form.submit()">
                                            <svg class="h-6 w-6 {{ (int) $application->rating >= $star ? 'text-amber-400 dark:text-amber-300' : 'text-slate-200 dark:text-slate-700' }} peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40 rounded" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401Z" /></svg>
                                            <span class="sr-only">{{ $star }} out of 5</span>
                                        </label>
                                    @endfor
                                </div>
                                <x-ui.form.error for="rating" />
                            </div>

                            <div>
                                <div class="mb-1 flex items-center justify-between">
                                    <label for="field-internal_notes" class="text-xs text-slate-500 dark:text-slate-400">Internal notes</label>
                                    <span class="text-2xs" aria-live="polite">
                                        <span x-show="state === 'saving'" x-cloak class="text-slate-400">Saving…</span>
                                        <span x-show="state === 'saved'" x-cloak class="text-emerald-600 dark:text-emerald-400">Saved</span>
                                        <span x-show="state === 'error'" x-cloak class="text-rose-600 dark:text-rose-400">Not saved — use the button</span>
                                    </span>
                                </div>
                                <textarea
                                    id="field-internal_notes"
                                    name="internal_notes"
                                    rows="6"
                                    maxlength="5000"
                                    x-on:blur="save($el.form)"
                                    placeholder="Visible to reviewers who can edit applications. Never shown to the candidate."
                                    class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                                >{{ old('internal_notes', $application->internal_notes) }}</textarea>
                                <x-ui.form.error for="internal_notes" />
                            </div>

                            <div class="flex justify-end">
                                <x-ui.button type="submit" size="sm" variant="secondary" icon="check">Save notes</x-ui.button>
                            </div>
                        </form>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card title="Pipeline history" icon="clock">
                @if ($timeline->isEmpty())
                    <x-ui.empty-state icon="clock" title="No stage changes yet" message="Every move along the pipeline is recorded here: who, when and why." :compact="true" />
                @else
                    <ol class="relative space-y-5 border-l border-slate-200 pl-5 dark:border-slate-800">
                        @foreach ($timeline as $entry)
                            @php
                                $properties = $entry->properties instanceof \Illuminate\Support\Collection ? $entry->properties->toArray() : (array) ($entry->properties ?? []);
                                $from = data_get($properties, 'old.status');
                                $to = data_get($properties, 'attributes.status');
                                $reason = $entry->reason ?? data_get($properties, 'reason');
                                $interviewAt = data_get($properties, 'attributes.interview_at');
                                $causer = $entry->relationLoaded('causer') ? $entry->causer : null;
                            @endphp
                            <li class="relative">
                                <span class="absolute -left-[1.6rem] top-1 flex h-2.5 w-2.5 rounded-full bg-brand-500 ring-4 ring-white dark:bg-brand-400 dark:ring-slate-900" aria-hidden="true"></span>
                                <p class="text-sm text-slate-900 dark:text-white">
                                    @if ($from || $to)
                                        {{ \Illuminate\Support\Str::headline((string) $from ?: 'New') }} → <span class="font-semibold">{{ \Illuminate\Support\Str::headline((string) $to) }}</span>
                                    @else
                                        {{ \Illuminate\Support\Str::ucfirst((string) $entry->description) }}
                                    @endif
                                </p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ $causer?->name ?? 'System' }} · {{ app_datetime($entry->created_at) }}
                                </p>
                                @if (filled($interviewAt))
                                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">Interview {{ app_datetime($interviewAt) }}</p>
                                @endif
                                @if (filled($reason))
                                    <p class="mt-1 text-xs italic text-slate-600 dark:text-slate-300">“{{ $reason }}”</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>
    </div>

    @include('admin.job-applications.partials.dialogs', ['reviewerOptions' => $reviewerOptions ?? [], 'interviewModes' => $interviewModes ?? null])
@endsection
