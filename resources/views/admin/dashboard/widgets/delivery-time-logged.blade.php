{{--
    TimeLoggedWidget body.

    The only delivery card that moves with the range selector: hours are a period measure, the other
    three are states. Hours arrive as exact decimal strings and are printed through app_quantity(),
    so '8.00' reads as '8' and nothing here is ever a float.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="clock" title="Hours unavailable"
                      message="The time log could not be read." :compact="true" />
@elseif (($data['entries'] ?? 0) === 0)
    <x-ui.empty-state icon="clock" title="No hours this period"
                      :message="'Nobody logged time in '.($data['range_label'] ?? 'this period').'.'" :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                {{ app_quantity($data['hours'], 2) }}<span class="ml-1 text-lg font-medium text-slate-400 dark:text-slate-500">h</span>
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                {{ $data['range_label'] }} · {{ $data['entries'] }} {{ Str::plural('entry', $data['entries']) }}
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Not linked to a task</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ app_quantity($data['untasked_hours'], 2) }}h</dd>
            </div>
            <div class="flex justify-between gap-3">
                <dt class="text-slate-500 dark:text-slate-400">Entered by hand</dt>
                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ app_quantity($data['manual_hours'], 2) }}h</dd>
            </div>
        </dl>

        @if (($data['live_entries'] ?? 0) > 0)
            <p class="text-xs text-amber-600 dark:text-amber-400">
                {{ $data['live_entries'] }} {{ Str::plural('timer', $data['live_entries']) }}
                {{ $data['live_entries'] === 1 ? 'is' : 'are' }} still on the clock — those hours are not in the total yet.
            </p>
        @endif
    </div>
@endif
