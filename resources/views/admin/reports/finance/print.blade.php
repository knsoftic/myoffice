{{--
    The printed finance report — admin.reports.finance.export with format `print` or `pdf`.

    `print` and `pdf` render this same page (phase-13 §6.9): a PDF that had drifted from the printed
    one would be a second document claiming to be the first. Everything it shows comes from the one
    `ReportResult` the screen renders, so the three cannot disagree about a figure or about what the
    figure covers.
--}}

@extends('layouts.print')

@php
    $meta = $result->meta;
    $slug = str_replace('_', '-', $type->value);
    $backUrl = route('admin.reports.finance.'.$slug, array_filter(request()->only(['preset', 'from', 'to', 'context'])));
    $backLabel = 'Back to the report';
@endphp

@section('title', $type->label().' — '.($meta['from'] ?? '').' to '.($meta['to'] ?? ''))

@section('document')
    <h2>{{ $type->label() }}</h2>
    <div class="muted tiny" style="margin-top:6px;">
        {{ app_date($meta['from'] ?? null) }} – {{ app_date($meta['to'] ?? null) }}<br>
        {{ $meta['range_label'] ?? '' }}<br>
        Generated {{ app_datetime($generatedAt) }}
    </div>
@endsection

@section('content')
    <h3 class="section">What this covers</h3>
    <table class="doc" style="margin-top:4px;">
        <tbody>
            <tr>
                <td style="width:30%;" class="muted">Basis</td>
                <td>{{ $meta['basis'] ?? 'cash' }} — money that moved, not money that was promised</td>
            </tr>
            <tr>
                <td class="muted">Dated on</td>
                <td>{{ $meta['date_column'] ?? '—' }}</td>
            </tr>
            @if (($meta['filters'] ?? []) !== [])
                <tr>
                    <td class="muted">Filters</td>
                    <td>{{ collect($meta['filters'])->map(fn ($v, $k) => $k.' = '.$v)->implode(' · ') }}</td>
                </tr>
            @endif
            @if (array_key_exists('includes_institute', $meta))
                <tr>
                    <td class="muted">Institute</td>
                    <td>{{ $meta['includes_institute'] ? 'Included' : 'Excluded by the finance setting' }}</td>
                </tr>
            @endif
            @if ($result->omittedSources() !== [])
                <tr>
                    <td class="muted">Not included</td>
                    <td><strong>{{ implode(', ', $result->omittedSources()) }}</strong> — the reader of this
                        copy may not see them, so the total below is partial and says so.</td>
                </tr>
            @endif
        </tbody>
    </table>

    <h3 class="section">{{ $type->label() }}</h3>

    @if ($result->isEmpty())
        <p class="muted">Nothing in this range. The period and the date column above say what was looked at.</p>
    @else
        @include('admin.reports.finance._table', ['print' => true, 'linkRows' => false])
    @endif
@endsection

@section('footer')
    <div>
        {{ $type->label() }} · {{ app_date($meta['from'] ?? null) }} – {{ app_date($meta['to'] ?? null) }}
        · {{ $meta['basis'] ?? 'cash' }} basis · dated on {{ $meta['date_column'] ?? '—' }}
    </div>
    <div>Generated {{ app_datetime($generatedAt) }}. Figures are as at that moment.</div>
@endsection
