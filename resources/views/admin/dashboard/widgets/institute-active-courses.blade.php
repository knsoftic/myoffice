{{--
    ActiveCoursesWidget body. Published, not "all": a catalogue of forty of which six are on the site is
    a different institute from one with forty live.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="academic-cap" title="Catalogue unavailable"
                      message="The course list could not be read." :compact="true" />
@elseif (($data['published'] ?? 0) === 0 && ($data['draft'] ?? 0) === 0)
    <x-ui.empty-state icon="academic-cap" title="No courses yet"
                      message="A course starts as a draft and goes live when it has an outline." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ $data['published'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                published · {{ $data['featured'] }} featured
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Taking admissions</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['admitting'] }}</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Draft</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['draft'] }}</dd>
            </div>
            @if (($data['archived'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Retired</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['archived'] }}</dd>
                </div>
            @endif
        </dl>

        @unless ($data['institute_open'] ?? true)
            <p class="text-xs text-amber-600 dark:text-amber-400">
                Admissions are switched off institute-wide, so no course is taking any.
            </p>
        @endunless
    </div>
@endif
