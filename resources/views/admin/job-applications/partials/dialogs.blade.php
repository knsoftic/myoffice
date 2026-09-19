{{--
    The two shared dialogs of the application pipeline (phase-04 §8.9): change stage and assign reviewer.
    Rendered ONCE per page; each opener dispatches the data of its row:

        $dispatch('open-modal', { name: 'application-stage', url, label, current, options: [{value, label}] })
        $dispatch('open-modal', { name: 'application-assign', url, label, current })

    Variables:
      $reviewerOptions   array<int, string>   users who may be assigned (they hold job_applications.view_any)
      $interviewModes    optional array<string, string>  onsite / online / phone

    Posts:
      POST admin.job-applications.status {application}   status (one of allowedNext() of the current stage),
                                                         reason (required for rejected), interview_at
                                                         (datetime-local, display timezone, in the future),
                                                         interview_mode, interview_location (for interview)
      POST admin.job-applications.assign {application}   user_id (empty = unassign)
    ChangeApplicationStatusRequest is the authority: a stage outside allowedNext() is a 422 even when posted by hand.
--}}

@php
    $modes = $interviewModes ?? ['onsite' => 'On site', 'online' => 'Online meeting', 'phone' => 'Phone call'];
    $minInterview = app_datetime(now()->addMinutes(30), 'Y-m-d\TH:i');
    $timezone = \App\Support\Format::displayTimezone();
@endphp

<x-ui.modal name="application-stage" title="Change stage" icon="arrow-path" size="md">
    <form
        id="application-stage-form"
        method="POST"
        x-data="{ url: '', label: '', current: '', options: [], status: '' }"
        x-on:open-modal.window="if ($event.detail?.name === 'application-stage') { url = $event.detail.url; label = $event.detail.label; current = $event.detail.current; options = $event.detail.options || []; status = options.length ? options[0].value : ''; }"
        x-bind:action="url"
        class="space-y-4"
    >
        @csrf
        <p class="text-sm text-slate-600 dark:text-slate-300">
            Move <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span>
            from <span class="font-medium" x-text="current"></span> to:
        </p>

        <div>
            <label for="application-stage-status" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Next stage <span class="text-rose-500">*</span></label>
            <select id="application-stage-status" name="status" x-model="status" required class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                <template x-for="option in options" :key="option.value">
                    <option x-bind:value="option.value" x-text="option.label"></option>
                </template>
            </select>
            <p x-show="options.length === 0" class="mt-1.5 text-xs text-amber-700 dark:text-amber-400">No further stage is possible from here.</p>
            <x-ui.form.error for="status" />
        </div>

        <div x-show="status === 'rejected'" x-cloak>
            <label for="application-stage-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Reason <span class="text-rose-500">*</span></label>
            <textarea id="application-stage-reason" name="reason" rows="3" maxlength="255" x-bind:required="status === 'rejected'" x-bind:disabled="status !== 'rejected'" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">{{ old('reason') }}</textarea>
            <p class="mt-1 text-2xs text-slate-400 dark:text-slate-500">Internal. Stored on the application and in the pipeline history.</p>
            <x-ui.form.error for="reason" />
        </div>

        <div x-show="status === 'interview'" x-cloak class="space-y-3 rounded-lg bg-slate-50 p-3 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700">
            <div>
                <label for="application-interview-at" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Interview at <span class="text-rose-500">*</span> <span class="font-normal text-slate-400">({{ $timezone }})</span></label>
                <input id="application-interview-at" type="datetime-local" name="interview_at" min="{{ $minInterview }}" value="{{ old('interview_at') }}" x-bind:required="status === 'interview'" x-bind:disabled="status !== 'interview'" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                <x-ui.form.error for="interview_at" />
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label for="application-interview-mode" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Mode <span class="text-rose-500">*</span></label>
                    <select id="application-interview-mode" name="interview_mode" x-bind:required="status === 'interview'" x-bind:disabled="status !== 'interview'" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                        @foreach ($modes as $modeValue => $modeLabel)
                            <option value="{{ $modeValue }}" @selected(old('interview_mode') === $modeValue)>{{ $modeLabel }}</option>
                        @endforeach
                    </select>
                    <x-ui.form.error for="interview_mode" />
                </div>
                <div>
                    <label for="application-interview-location" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Location or meeting link</label>
                    <input id="application-interview-location" type="text" name="interview_location" maxlength="255" value="{{ old('interview_location') }}" x-bind:disabled="status !== 'interview'" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                </div>
            </div>
        </div>

        <p class="flex items-start gap-2 rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-800 ring-1 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/25">
            <x-ui.icon name="information-circle" class="mt-px h-4 w-4 shrink-0" />
            The candidate is not emailed automatically. Contact them yourself about this change.
        </p>
    </form>

    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'application-stage')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="application-stage-form" icon="arrow-path">Move candidate</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

<x-ui.modal name="application-assign" title="Assign a reviewer" icon="user-plus" size="sm">
    <form
        id="application-assign-form"
        method="POST"
        x-data="{ url: '', label: '', current: '' }"
        x-on:open-modal.window="if ($event.detail?.name === 'application-assign') { url = $event.detail.url; label = $event.detail.label; current = String($event.detail.current ?? ''); $nextTick(() => { $refs.reviewer.value = current; }); }"
        x-bind:action="url"
        class="space-y-3"
    >
        @csrf
        <p class="text-sm text-slate-600 dark:text-slate-300">Who reviews <span class="font-semibold text-slate-900 dark:text-white" x-text="label"></span>? A reviewer without the full list only sees what is assigned to them.</p>
        <div>
            <label for="application-assign-user" class="block text-xs font-medium text-slate-600 dark:text-slate-300">Reviewer</label>
            <select id="application-assign-user" x-ref="reviewer" name="user_id" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                <option value="">Nobody (unassigned)</option>
                @foreach ((array) ($reviewerOptions ?? []) as $reviewerId => $reviewerName)
                    <option value="{{ $reviewerId }}">{{ $reviewerName }}</option>
                @endforeach
            </select>
            <x-ui.form.error for="user_id" />
        </div>
    </form>

    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'application-assign')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="application-assign-form" icon="check">Assign</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
