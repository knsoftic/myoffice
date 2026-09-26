{{--
    ActiveProjectsWidget body.

    Overdue leads and the total sits under it: "twelve projects" is a fact, "three past their
    deadline" is a reason to open the list. The split is in board order and only shows the statuses
    that hold something.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="folder" title="Projects unavailable"
                      message="The project list could not be read." :compact="true" />
@elseif (($data['live'] ?? 0) === 0)
    <x-ui.empty-state icon="folder" title="Nothing in delivery"
                      message="Every project is completed or cancelled." :compact="true" />
@else
    <div class="flex h-full flex-col justify-between gap-4">
        <div>
            <p class="text-3xl font-semibold tracking-tight tabular-nums {{ ($data['overdue'] ?? 0) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                {{ $data['overdue'] }}
            </p>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                past deadline · {{ $data['live'] }} in delivery
            </p>
        </div>

        <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
            @foreach ($data['split'] ?? [] as $bucket)
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500 dark:text-slate-400">{{ $bucket['label'] }}</dt>
                    <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ $bucket['total'] }}</dd>
                </div>
            @endforeach

            @if (($data['undated'] ?? 0) > 0)
                <div class="flex justify-between gap-3">
                    <dt class="text-amber-600 dark:text-amber-400">No deadline set</dt>
                    <dd class="tabular-nums font-semibold text-amber-600 dark:text-amber-400">{{ $data['undated'] }}</dd>
                </div>
            @endif
        </dl>

        @if (($data['overdue'] ?? 0) > 0)
            <p class="text-xs text-rose-600 dark:text-rose-400">
                {{ $data['overdue'] }} {{ Str::plural('project', $data['overdue']) }}
                {{ $data['overdue'] === 1 ? 'is' : 'are' }} past the date the client was given.
            </p>
        @endif
    </div>
@endif
