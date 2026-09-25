{{--
    OpenCourseInquiriesWidget body.

    Overdue and never-contacted are shown apart from the total on purpose: one is a workload, the
    other two are a lost admission in progress.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="phone-arrow-up-right" title="Inquiries unavailable"
                      message="The inquiry queue could not be read." :compact="true" />
@elseif (($data['open'] ?? 0) === 0)
    <x-ui.empty-state icon="check-circle" title="Nobody waiting"
                      message="Every inquiry has been won, lost or answered." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ $data['open'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                open · {{ $data['due_today'] }} due today
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="{{ ($data['overdue'] ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400' }}">
                    Follow-up overdue
                </dt>
                <dd class="tabular-nums {{ ($data['overdue'] ?? 0) > 0 ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-slate-700 dark:text-slate-200' }}">
                    {{ $data['overdue'] }}
                </dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="{{ ($data['untouched'] ?? 0) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400' }}">
                    Never contacted
                </dt>
                <dd class="tabular-nums {{ ($data['untouched'] ?? 0) > 0 ? 'font-semibold text-rose-600 dark:text-rose-400' : 'text-slate-700 dark:text-slate-200' }}">
                    {{ $data['untouched'] }}
                </dd>
            </div>
        </dl>

        @if (($data['untouched'] ?? 0) > 0)
            <p class="text-xs text-rose-600 dark:text-rose-400">
                {{ $data['untouched'] }} {{ Str::plural('person', $data['untouched']) }} asked and nobody has called yet.
            </p>
        @endif
    </div>
@endif
