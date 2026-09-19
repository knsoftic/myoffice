{{--
    One status pill for any Phase 4 enum cast (phase-04 §3): ContentStatus, ApprovalStatus, JobOpeningStatus,
    JobApplicationStatus, ContactInquiryStatus, InquiryRoutingStatus, InquiryType, InquirySource, WorkMode,
    TestimonialType, ContentSource, App\Enums\EmploymentType.

    @include('admin.marketing.partials.enum-badge', [
        'value' => $service->status,   // a backed enum with label() + color(), a raw string, or null
        'size' => 'sm',
        'dot' => true,
        'variant' => 'soft',
        'empty' => '—',                // printed when the value is null
    ])

    The colour and the label always come from the enum itself (D9), so a status is never restyled in a view.
    A raw string (an unknown value in a legacy row) renders as a neutral slate pill with a headline label
    rather than throwing.
--}}

@php
    $value = $value ?? null;
    $size = $size ?? 'sm';
    $dot = (bool) ($dot ?? true);
    $variant = $variant ?? 'soft';

    if ($value instanceof \BackedEnum) {
        $badgeLabel = method_exists($value, 'label') ? (string) $value->label() : \Illuminate\Support\Str::headline((string) $value->value);
        $badgeColor = method_exists($value, 'color') ? (string) $value->color() : 'slate';
    } elseif (is_string($value) && $value !== '') {
        $badgeLabel = \Illuminate\Support\Str::headline($value);
        $badgeColor = 'slate';
    } else {
        $badgeLabel = null;
        $badgeColor = 'slate';
    }
@endphp

@if ($badgeLabel !== null)
    <x-ui.badge :color="$badgeColor" :size="$size" :dot="$dot" :variant="$variant">{{ $badgeLabel }}</x-ui.badge>
@else
    <span class="text-xs text-slate-400 dark:text-slate-500">{{ $empty ?? '—' }}</span>
@endif
