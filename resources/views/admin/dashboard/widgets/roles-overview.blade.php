{{--
    RolesOverviewWidget body.

    A role with no members is the reading worth surfacing — it is either a role waiting to be used
    or one nobody needed — so it gets its own figure rather than being buried in the list.
--}}

@if (! ($data['available'] ?? false) || ($data['total'] ?? 0) === 0)
    <x-ui.empty-state
        icon="shield-check"
        title="No roles defined"
        :message="$widget->emptyMessage"
        :compact="true"
    />
@else
    <div class="space-y-4">
        <div class="grid grid-cols-3 gap-2">
            <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                <p class="text-xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                    {{ app_number($data['total']) }}
                </p>
                <p class="text-2xs uppercase tracking-wider text-slate-500 dark:text-slate-400">roles</p>
            </div>

            <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                <p class="text-xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                    {{ app_number($data['assigned_accounts']) }}
                </p>
                <p class="text-2xs uppercase tracking-wider text-slate-500 dark:text-slate-400">grants</p>
            </div>

            <div class="rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50">
                <p @class([
                    'text-xl font-semibold tracking-tight tabular-nums',
                    'text-amber-600 dark:text-amber-400' => $data['unassigned'] > 0,
                    'text-slate-900 dark:text-white' => $data['unassigned'] === 0,
                ])>
                    {{ app_number($data['unassigned']) }}
                </p>
                <p class="text-2xs uppercase tracking-wider text-slate-500 dark:text-slate-400">unused</p>
            </div>
        </div>

        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($data['roles'] as $role)
                <li class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                    <div class="flex min-w-0 items-center gap-2">
                        @if ($role['href'])
                            <a
                                href="{{ $role['href'] }}"
                                class="truncate text-sm font-medium text-slate-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                            >{{ $role['label'] }}</a>
                        @else
                            <span class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $role['label'] }}</span>
                        @endif

                        @if ($role['is_system'])
                            <x-ui.badge color="slate" size="xs" icon="lock-closed">system</x-ui.badge>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-3 text-xs text-slate-500 dark:text-slate-400">
                        <span class="inline-flex items-center gap-1" title="{{ app_number($role['users_count']) }} accounts hold this role">
                            <x-ui.icon name="users" class="h-3.5 w-3.5" />
                            <span class="font-semibold tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($role['users_count']) }}</span>
                        </span>

                        <span class="inline-flex items-center gap-1" title="{{ app_number($role['permissions_count']) }} permissions granted">
                            <x-ui.icon name="key" class="h-3.5 w-3.5" />
                            <span class="font-semibold tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($role['permissions_count']) }}</span>
                        </span>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
            @if ($data['more'] > 0)
                <span>+{{ app_number($data['more']) }} more</span>
                <span class="text-slate-300 dark:text-slate-700">·</span>
            @endif

            @foreach ($data['panels'] as $panel)
                <span class="inline-flex items-center gap-1">
                    {{ $panel['label'] }}
                    <span class="font-semibold tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($panel['count']) }}</span>
                </span>
            @endforeach
        </div>
    </div>
@endif
