{{--
    SystemHealthWidget body.

    Each row carries a `state` the widget measured — ok, warn or error — so the dot beside a reading
    is the probe's own verdict and not this view guessing from the text. A failed probe arrives as a
    dash; it is never dressed up as a zero.
--}}

@php
    $states = [
        'ok' => ['bg-emerald-500', 'text-slate-500 dark:text-slate-400'],
        'warn' => ['bg-amber-500', 'text-amber-600 dark:text-amber-400'],
        'error' => ['bg-rose-500', 'text-rose-600 dark:text-rose-400'],
    ];
@endphp

@if (! ($data['available'] ?? false))
    <x-ui.empty-state
        icon="server-stack"
        title="Health checks unavailable"
        :compact="true"
    />
@else
    <div class="space-y-3">
        @if (($data['environment'] ?? 'production') !== 'production' || ($data['debug'] ?? false))
            <p class="flex flex-wrap items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                <x-ui.icon name="exclamation-triangle" class="h-4 w-4 shrink-0" />
                Running in <strong>{{ $data['environment'] }}</strong>{{ $data['debug'] ? ' with debug output on' : '' }}.
            </p>
        @endif

        <dl class="grid grid-cols-1 gap-x-6 gap-y-0 sm:grid-cols-2">
            @foreach ($data['rows'] as $row)
                @php [$dot, $metaClass] = $states[$row['state'] ?? 'ok'] ?? $states['ok']; @endphp

                <div class="flex items-start gap-2.5 border-b border-slate-100 py-2.5 last:border-0 dark:border-slate-800">
                    <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>

                    <div class="min-w-0 flex-1">
                        <dt class="text-2xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            {{ $row['label'] }}
                        </dt>
                        <dd class="truncate text-sm font-semibold text-slate-900 tabular-nums dark:text-white" title="{{ $row['value'] }}">
                            {{ $row['value'] }}
                        </dd>
                        @if (filled($row['meta']))
                            <dd class="truncate text-2xs {{ $metaClass }}" title="{{ $row['meta'] }}">{{ $row['meta'] }}</dd>
                        @endif
                    </div>
                </div>
            @endforeach
        </dl>

        @if (($data['checked_at'] ?? null) !== null)
            <p class="text-2xs text-slate-400 dark:text-slate-500">
                Measured <time datetime="{{ $data['checked_at'] }}">{{ app_datetime($data['checked_at']) }}</time> ·
                database size is what the engine reports and lags a freshly written table
            </p>
        @endif
    </div>
@endif
