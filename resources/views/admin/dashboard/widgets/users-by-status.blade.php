{{--
    UsersByStatusWidget body.

    The standing breakdown and the range-scoped "new accounts" figure, side by side and clearly
    labelled as the two different things they are.
--}}

@if (! ($data['available'] ?? false) || ($data['total'] ?? 0) === 0)
    <x-ui.empty-state
        icon="users"
        title="No accounts yet"
        :message="$widget->emptyMessage"
        :compact="true"
    />
@else
    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                    {{ app_number($data['total']) }}
                </p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">accounts in total</p>
            </div>

            <div class="text-right">
                <p class="text-sm font-semibold text-slate-900 tabular-nums dark:text-white">
                    {{ app_number($data['new_in_range']) }} new
                </p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">in {{ $data['range_label'] }}</p>
            </div>
        </div>

        @include('admin.dashboard.partials.meter', [
            'segments' => collect($data['statuses'])
                ->map(fn (array $status): array => [
                    'color' => $status['color'],
                    'share' => $status['share'],
                    'label' => $status['label'],
                ])
                ->all(),
            'height' => 'h-2.5',
        ])

        <dl class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($data['statuses'] as $status)
                <div class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                    <dt class="flex min-w-0 items-center gap-2">
                        <x-ui.badge :color="$status['color']" size="sm" :dot="true">{{ $status['label'] }}</x-ui.badge>

                        @unless ($status['can_login'])
                            <span class="truncate text-2xs text-slate-400 dark:text-slate-500">cannot sign in</span>
                        @endunless
                    </dt>

                    <dd class="flex shrink-0 items-center gap-2 text-sm">
                        <span class="font-semibold text-slate-900 tabular-nums dark:text-white">
                            {{ app_number($status['count']) }}
                        </span>
                        <span class="w-9 text-right text-xs text-slate-400 tabular-nums dark:text-slate-500">
                            {{ $status['share'] }}%
                        </span>

                        @if ($status['href'] && $status['count'] > 0)
                            <a
                                href="{{ $status['href'] }}"
                                class="text-slate-400 transition-colors hover:text-brand-600 dark:text-slate-500 dark:hover:text-brand-400"
                                aria-label="List {{ strtolower($status['label']) }} accounts"
                            >
                                <x-ui.icon name="arrow-right" class="h-3.5 w-3.5" />
                            </a>
                        @else
                            <span class="h-3.5 w-3.5" aria-hidden="true"></span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>

        <div class="border-t border-slate-100 pt-3 dark:border-slate-800">
            @include('admin.dashboard.partials.delta', [
                'delta' => $data['delta'],
                'against' => $data['previous_label'],
            ])
        </div>
    </div>
@endif
