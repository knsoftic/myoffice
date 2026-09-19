{{--
    One pill for any Phase 5 enum cast (phase-05 §3): LeadStatus, LeadActivityType, LeadContactOutcome, LeadFollowUpType,
    LeadFollowUpStatus, LeadConversionType, LeadDuplicateMatchType, LeadImportStatus, LeadImportRowStatus,
    LeadImportDuplicateStrategy, ClientType, ClientStatus, ClientDocumentCategory, and InquirySource (phase-04 §3).

    @include('admin.crm.partials.enum-badge', [
        'value' => $lead->status,   // a backed enum with label() + color(), a raw string, or null
        'size' => 'sm',
        'dot' => true,
        'variant' => 'soft',
        'empty' => '—',             // printed when the value is null
    ])

    The colour and the label always come from the enum (D9); a view never restyles a status. A raw string (a value
    no case matches) renders as a neutral slate pill with a headline label rather than throwing.
--}}

@php
    $badgeValue = $value ?? null;
    $badgeSize = $size ?? 'sm';
    $badgeDot = (bool) ($dot ?? true);
    $badgeVariant = $variant ?? 'soft';

    if ($badgeValue instanceof \BackedEnum) {
        $badgeLabel = method_exists($badgeValue, 'label') ? (string) $badgeValue->label() : \Illuminate\Support\Str::headline((string) $badgeValue->value);
        $badgeColor = method_exists($badgeValue, 'color') ? (string) $badgeValue->color() : 'slate';
    } elseif (is_string($badgeValue) && $badgeValue !== '') {
        $badgeLabel = \Illuminate\Support\Str::headline($badgeValue);
        $badgeColor = 'slate';
    } else {
        $badgeLabel = null;
        $badgeColor = 'slate';
    }
@endphp

@if ($badgeLabel !== null)
    <x-ui.badge :color="$badgeColor" :size="$badgeSize" :dot="$badgeDot" :variant="$badgeVariant">{{ $badgeLabel }}</x-ui.badge>
@else
    <span class="text-xs text-slate-400 dark:text-slate-500">{{ $empty ?? '—' }}</span>
@endif
