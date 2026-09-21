{{--
    CollaboratorLeaderboardWidget body. The bars are relative to the leader, so they mean something
    without an axis. Partners who have since left are kept: they still earned what they earned.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state icon="trophy" title="Leaderboard unavailable"
                      message="The commission ledger could not be read." :compact="true" />
@elseif ($data['leaders'] === [])
    <x-ui.empty-state icon="trophy" title="Nobody earned yet" :message="$widget->emptyMessage" :compact="true" />
@else
    <ol class="space-y-3">
        @foreach ($data['leaders'] as $leader)
            <li>
                <a href="{{ $leader['href'] }}" class="group block rounded px-1 py-0.5 hover:bg-slate-50 dark:hover:bg-slate-800/60">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="flex min-w-0 items-baseline gap-2">
                            <span class="w-4 shrink-0 text-xs font-semibold tabular-nums text-slate-400 dark:text-slate-500">
                                {{ $leader['position'] }}
                            </span>
                            <span class="truncate text-sm text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300">
                                {{ $leader['name'] }}
                            </span>
                            @if ($leader['trashed'])
                                <span class="shrink-0 text-xs text-slate-400 dark:text-slate-500">· left</span>
                            @endif
                        </span>
                        <span class="shrink-0 text-sm font-semibold tabular-nums text-slate-900 dark:text-white">
                            {{ money($leader['total']) }}
                        </span>
                    </div>
                    <div class="mt-1 ml-6 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div class="h-full rounded-full bg-brand-500" style="width: {{ $leader['share'] }}%"></div>
                    </div>
                </a>
            </li>
        @endforeach
    </ol>

    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ $data['range_label'] }}</p>
@endif
