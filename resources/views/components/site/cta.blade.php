@props([
    'cta' => null,
    'headingLevel' => 'h2',
])

{{--
    x-site.cta — one reusable call-to-action block (phase-03 §2.8, §6.1 `cta` type, requirement §100).

        <x-site.cta :cta="$cta" />

    `cta` is the resolved block the section snapshot carries (`SnapshotBuilder::cta()`):
    `id key variant heading subheading description primary secondary background background_color`,
    where `primary` / `secondary` are button arrays (`label url style new_tab rel`) or null and
    `background` is a media array or null. A draft or deleted block is already null in the snapshot,
    so a null `cta` renders nothing.

    The variant chooses the partial through `CtaVariant::view()` (`site.cta.{variant}`), falling back to
    the banner when the stored value is unknown or its partial is missing.

    This component prepares what every variant needs so no partial repeats it:
      $heading $subheading $description   plain text (CTA copy is not rich text, §2.8)
      $buttons      the one or two buttons that have both a label and a safe URL
      $background   a media array, or null
      $color        a validated `#rrggbb`, or null (ignored when a background image is set)
      $tone         'dark' when the surface behind the text is dark (an image, a dark colour, or the
                    default slate panel), otherwise 'light'
      $headingTag   h2 unless told otherwise, so the page keeps exactly one h1
--}}

@php
    use App\Enums\Cms\CtaVariant;
    use Illuminate\Support\Facades\View;

    $heading = trim((string) data_get($cta, 'heading', ''));
    $variant = CtaVariant::tryFrom((string) data_get($cta, 'variant', '')) ?? CtaVariant::Banner;
    $view = View::exists($variant->view()) ? $variant->view() : CtaVariant::Banner->view();

    $buttons = collect([data_get($cta, 'primary'), data_get($cta, 'secondary')])
        ->filter(static fn ($button): bool => is_array($button)
            && trim((string) ($button['label'] ?? '')) !== ''
            && preg_match('/^(https?:\/\/|mailto:|tel:|\/|#)/i', trim((string) ($button['url'] ?? ''))) === 1)
        ->values()
        ->all();

    $background = filled(data_get($cta, 'background.url')) ? array_merge((array) data_get($cta, 'background'), ['alt' => '']) : null;

    $color = (string) data_get($cta, 'background_color', '');
    $color = $background === null && preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? strtolower($color) : null;

    // Perceived brightness of the custom colour (ITU-R BT.601 weights, integer arithmetic), so the copy
    // on top of it stays readable whichever colour an editor picks.
    $colorIsDark = true;

    if ($color !== null) {
        $r = hexdec(substr($color, 1, 2));
        $g = hexdec(substr($color, 3, 2));
        $b = hexdec(substr($color, 5, 2));
        $colorIsDark = ((299 * $r) + (587 * $g) + (114 * $b)) < 150000;
    }

    $tone = ($background !== null || $color === null || $colorIsDark) ? 'dark' : 'light';
    $headingTag = in_array($headingLevel, ['h1', 'h2', 'h3'], true) ? $headingLevel : 'h2';
@endphp

@if ($heading !== '')
    <div {{ $attributes }}>
        @include($view, [
            'cta' => $cta,
            'heading' => $heading,
            'subheading' => trim((string) data_get($cta, 'subheading', '')),
            'description' => trim((string) data_get($cta, 'description', '')),
            'buttons' => $buttons,
            'background' => $background,
            'color' => $color,
            'tone' => $tone,
            'headingTag' => $headingTag,
        ])
    </div>
@endif
