@props([
    'eyebrow' => null,
    'title' => null,
    'subtitle' => null,
    'level' => 'h2',
    'align' => 'center',
    'size' => 'default',
    'id' => null,
])

{{--
    x-site.heading — the section heading block: an optional eyebrow, the heading itself, and an
    optional standfirst paragraph.

        <x-site.heading eyebrow="About us" :title="$content['heading'] ?? null"
                        :subtitle="$content['description'] ?? null" />

    `level` exists because **a page has exactly one `<h1>`** (§8.14 accessibility): the hero (or a
    page banner) owns it, and every other section heading is an `<h2>`. Pass `level="h1"` only there.

    The eyebrow is decorative *text*, not a heading, so it is a `<p>` — a screen reader should not
    hear "About us, heading level 3" above "Who we are, heading level 2".

    Renders nothing when there is no title and no subtitle, so an editor who cleared the heading
    does not leave an empty block of margin behind.
--}}

@php
    $hasTitle = filled($title);
    $hasSubtitle = filled($subtitle);

    $tag = in_array($level, ['h1', 'h2', 'h3', 'h4'], true) ? $level : 'h2';

    $titleClasses = match ($size) {
        'sm' => 'text-2xl sm:text-3xl',
        'lg' => 'text-4xl sm:text-5xl lg:text-6xl',
        default => 'text-3xl sm:text-4xl lg:text-[2.75rem] lg:leading-[1.1]',
    };

    $alignment = match ($align) {
        'left' => 'text-left',
        'right' => 'text-right',
        default => 'mx-auto text-center',
    };

    $subtitleWidth = $align === 'center' ? 'mx-auto max-w-2xl' : 'max-w-2xl';
@endphp

@if ($hasTitle || $hasSubtitle || filled($eyebrow))
    <div {{ $attributes->class(['max-w-3xl', $alignment]) }}>
        @if (filled($eyebrow))
            <p class="mb-3 text-xs font-semibold uppercase tracking-[0.14em] text-brand-600 dark:text-brand-400">
                {{ $eyebrow }}
            </p>
        @endif

        @if ($hasTitle)
            <{{ $tag }}
                @if (filled($id)) id="{{ $id }}" @endif
                class="{{ $titleClasses }} font-bold tracking-tight text-slate-900 dark:text-white"
            >{{ $title }}</{{ $tag }}>
        @endif

        @if ($hasSubtitle)
            <p class="{{ $subtitleWidth }} mt-4 text-base leading-relaxed text-slate-600 sm:text-lg dark:text-slate-300">
                {{ $subtitle }}
            </p>
        @endif
    </div>
@endif
