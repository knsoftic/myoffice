@props([
    'link' => null,
    'label' => null,
    'url' => null,
    'style' => null,
    'newTab' => null,
    'icon' => null,
    'block' => false,
    'size' => null,
])

{{--
    x-site.button — one CMS-authored button (a `link` composite field, or a CTA block's primary /
    secondary pair).

        <x-site.button :link="$content['primary_button'] ?? null" />
        <x-site.button label="Apply now" url="/admission" style="primary" icon="arrow-right" />

    A `link` field is stored as `['label' => …, 'url' => …, 'style' => …, 'new_tab' => bool]`
    (`SectionRegistry::linkDefault()`), so passing the raw field value is the normal form.

    **No colour is written here.** The class attribute comes from `App\Enums\Cms\ButtonStyle::classes()`,
    which is the single place a public button's Tailwind classes exist (§3) — that is what makes the
    site re-brand with `branding.brand_color` and keeps a `dark:` counterpart on every utility.

    A button with no label or no URL renders **nothing at all**: an editor who cleared the label has
    switched the button off, and an empty `<a>` would be an unlabelled control for a screen reader.

    `new_tab` always emits `rel="noopener noreferrer"` (§6.6).
--}}

@php
    use App\Enums\Cms\ButtonStyle;

    $resolvedLabel = trim((string) ($label ?? data_get($link, 'label') ?? ''));
    $resolvedUrl = trim((string) ($url ?? data_get($link, 'url') ?? ''));
    $resolvedNewTab = (bool) ($newTab ?? data_get($link, 'new_tab', false));
    $resolvedIcon = $icon ?? data_get($link, 'icon');

    $styleValue = (string) ($style ?? data_get($link, 'style') ?? ButtonStyle::Primary->value);
    $buttonStyle = ButtonStyle::tryFrom($styleValue) ?? ButtonStyle::Primary;

    // The one scheme allowlist a rendered href may carry (§6.6, INV-13). Validation rejects
    // anything else on write; this is the render-time half, because the database is not a trust
    // boundary.
    $safeUrl = preg_match('/^(https?:\/\/|mailto:|tel:|\/|#)/i', $resolvedUrl) === 1 ? $resolvedUrl : null;

    $sizeClass = match ($size) {
        'sm' => 'h-9 px-4 text-xs',
        'lg' => 'h-12 px-7 text-base',
        default => null,
    };

    // A size REPLACES the style's default size rather than being appended to it: two height
    // utilities on one element are decided by stylesheet order, not by intent, so `sm` would
    // silently lose to the default h-11. The `link` style carries no size and takes none.
    $styleClasses = $buttonStyle->classes();

    if ($sizeClass !== null && str_contains($styleClasses, ButtonStyle::SIZE)) {
        $styleClasses = str_replace(ButtonStyle::SIZE, $sizeClass, $styleClasses);
    }
@endphp

@if ($resolvedLabel !== '' && $safeUrl !== null)
    <a
        href="{{ $safeUrl }}"
        @if ($resolvedNewTab) target="_blank" rel="noopener noreferrer" @endif
        {{ $attributes->class([
            $styleClasses,
            'w-full' => $block,
        ]) }}
    >
        @if (filled($resolvedIcon))
            <x-ui.icon :name="$resolvedIcon" class="h-4 w-4" />
        @endif

        <span>{{ $resolvedLabel }}</span>

        @if ($resolvedNewTab)
            <span class="sr-only">(opens in a new tab)</span>
        @endif
    </a>
@endif
