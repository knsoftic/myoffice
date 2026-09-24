{{--
    One report cell, rendered by its declared type (phase-19-23 6.20).

    The type decides the formatting here and the alignment on the header, so a figure cannot be
    right-aligned on screen and left-aligned in the file. Money goes through `money()` and a
    percentage through `app_number()` - the same helpers the rest of the system uses, so a total on a
    report and the same total on its module's own screen are formatted identically.

    A null is an em dash, never an empty cell: "this was empty" and "this failed to render" look the
    same otherwise.
--}}
@php($type = $column->type)

@if ($value === null || $value === '')
    <span class="text-slate-300 dark:text-slate-600">&mdash;</span>
@elseif ($type === \App\Enums\ReportColumnType::Money)
    <span class="tabular-nums">{{ money((string) $value) }}</span>
@elseif ($type === \App\Enums\ReportColumnType::Percent)
    <span class="tabular-nums">{{ app_number((float) $value, 2) }}%</span>
@elseif ($type === \App\Enums\ReportColumnType::Number)
    <span class="tabular-nums">{{ app_number((float) $value) }}</span>
@elseif ($type === \App\Enums\ReportColumnType::Badge)
    <x-ui.badge color="slate">{{ $value }}</x-ui.badge>
@else
    {{ $value }}
@endif
