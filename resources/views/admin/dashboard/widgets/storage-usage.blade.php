{{--
    StorageUsageWidget body.

    The shares are shares of what this card measured, not of the whole volume — otherwise every bar
    on a 500 GB disk would be invisible. The volume's own free space is reported separately, and the
    card says so in as many words.
--}}

@if (! ($data['available'] ?? false))
    <x-ui.empty-state
        icon="folder"
        title="Storage could not be measured"
        message="The application's directories could not be read."
        :compact="true"
    />
@else
    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-3xl font-semibold tracking-tight text-slate-900 tabular-nums dark:text-white">
                    {{ $data['total_label'] }}
                </p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    across {{ app_number($data['files']) }} {{ \Illuminate\Support\Str::plural('file', $data['files']) }} the application owns
                </p>
            </div>

            @if ($data['volume'] !== null)
                <div class="text-right">
                    <p class="text-sm font-semibold text-slate-900 tabular-nums dark:text-white">
                        {{ $data['volume']['free_label'] }} free
                    </p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        of {{ $data['volume']['total_label'] }} on this volume
                    </p>
                </div>
            @endif
        </div>

        @if ($data['volume'] !== null)
            @include('admin.dashboard.partials.meter', [
                'segments' => [[
                    'color' => $data['volume']['used_share'] >= 90 ? 'rose' : ($data['volume']['used_share'] >= 75 ? 'amber' : 'emerald'),
                    'share' => $data['volume']['used_share'],
                    'label' => 'Volume used',
                ]],
                'height' => 'h-2.5',
            ])
            <p class="-mt-2 text-2xs text-slate-400 dark:text-slate-500">
                {{ $data['volume']['used_share'] }}% of the volume is in use — by everything on it, not only this application
            </p>
        @endif

        <ul class="space-y-2.5 border-t border-slate-100 pt-3 dark:border-slate-800">
            @foreach ($data['areas'] as $area)
                <li>
                    <div class="flex items-center justify-between gap-3">
                        <span class="inline-flex min-w-0 items-center gap-2">
                            <x-ui.icon :name="$area['icon']" class="h-3.5 w-3.5 shrink-0 text-slate-400 dark:text-slate-500" />
                            <span class="truncate text-xs font-medium text-slate-700 dark:text-slate-200" title="{{ $area['description'] }}">
                                {{ $area['label'] }}
                            </span>
                            @unless ($area['exists'])
                                <span class="shrink-0 text-2xs text-slate-400 dark:text-slate-500">not created yet</span>
                            @endunless
                        </span>

                        <span class="shrink-0 text-xs font-semibold text-slate-900 tabular-nums dark:text-white">
                            {{ $area['size_label'] }}
                        </span>
                    </div>

                    @if ($area['exists'] && ($area['share'] ?? 0) > 0)
                        <div class="mt-1">
                            @include('admin.dashboard.partials.meter', [
                                'segments' => [['color' => $area['color'], 'share' => $area['share'], 'label' => $area['label']]],
                                'height' => 'h-1',
                            ])
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        <p class="text-2xs text-slate-400 dark:text-slate-500">
            @if ($data['truncated'])
                Measurement stopped at {{ app_number($data['file_limit']) }} files, so the total is a floor, not a total.
            @else
                Measured directly, cached for five minutes.
            @endif

            @if (($data['measured_at'] ?? null) !== null)
                · <time datetime="{{ $data['measured_at'] }}">{{ app_datetime($data['measured_at']) }}</time>
            @endif
        </p>
    </div>
@endif
