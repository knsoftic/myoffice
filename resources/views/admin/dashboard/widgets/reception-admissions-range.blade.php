{{--
    AdmissionsInRangeWidget body.

    "In progress" is given its own emphasis because it is the only line here somebody can act on:
    a student who has said yes and is not yet in a class.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="user-plus" title="Admissions unavailable"
                      message="Admissions could not be read." :compact="true" />
@elseif (($data['total'] ?? 0) === 0)
    <x-ui.empty-state icon="user-plus" title="None this period"
                      :message="'No admission was taken in '.($data['range_label'] ?? 'this period').'.'" :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ $data['total'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                {{ $data['range_label'] }} · {{ $data['active'] }} now in class
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="{{ ($data['in_progress'] ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400' }}">
                    Still in the pipeline
                </dt>
                <dd class="tabular-nums {{ ($data['in_progress'] ?? 0) > 0 ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-slate-700 dark:text-slate-200' }}">
                    {{ $data['in_progress'] }}
                </dd>
            </div>
            @if (($data['cancelled'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">Cancelled or withdrawn</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $data['cancelled'] }}</dd>
                </div>
            @endif
        </dl>

        @if (($data['in_progress'] ?? 0) > 0)
            <p class="text-xs text-amber-600 dark:text-amber-400">
                {{ $data['in_progress'] }} {{ Str::plural('admission', $data['in_progress']) }}
                said yes but {{ $data['in_progress'] === 1 ? 'is' : 'are' }} not in a class yet.
            </p>
        @endif
    </div>
@endif
