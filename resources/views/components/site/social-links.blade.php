@props([
    'size' => 'md',
])

{{--
    x-site.social-links — the company's social profiles (phase-03 §8.14 footer, Phase 2 `social.*`).

        <x-site.social-links />

    Only the profiles that are filled in render; with none filled in the component renders nothing at
    all, not an empty list. Each URL is re-checked against the `https?://` scheme at render time — the
    settings form validates on write, and the database is not a trust boundary.

    Brand glyphs are inline SVG paths (the Phase 1 icon set carries no brand marks) whose path data is
    printed through the escaping echo like any other attribute, so the component has no raw output at
    all. They are decorative, with the network name as the link's accessible name. Every link opens
    in a new tab with `rel="noopener noreferrer me"`.
--}}

@php
    $siteSetting = static fn (string $key, mixed $default = null): mixed => function_exists('site_setting')
        ? rescue(static fn () => site_setting($key, $default), $default)
        : $default;

    // key => [label, SVG path data (printed escaped as an attribute value), glyph kind]
    $networks = [
        'facebook' => ['Facebook', 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z', 'path'],
        'instagram' => ['Instagram', null, 'instagram'],
        'linkedin' => ['LinkedIn', 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 1 1 0-4.125 2.062 2.062 0 0 1 0 4.125zM7.119 20.452H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z', 'path'],
        'youtube' => ['YouTube', 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z', 'path'],
        'tiktok' => ['TikTok', 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z', 'path'],
        'x_twitter' => ['X', 'M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z', 'path'],
        'github' => ['GitHub', 'M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12', 'path'],
        'whatsapp_link' => ['WhatsApp', null, 'whatsapp'],
    ];

    $links = [];

    foreach ($networks as $key => [$label, $path, $kind]) {
        $url = trim((string) ($siteSetting('social.'.$key) ?? ''));

        if ($url === '' || preg_match('~^https?://~i', $url) !== 1) {
            continue;
        }

        $links[] = compact('key', 'label', 'path', 'kind', 'url');
    }

    $box = $size === 'sm' ? 'h-8 w-8' : 'h-10 w-10';
    $glyph = $size === 'sm' ? 'h-4 w-4' : 'h-[1.125rem] w-[1.125rem]';
@endphp

@if ($links !== [])
    <ul {{ $attributes->class('flex flex-wrap items-center gap-2') }}>
        @foreach ($links as $link)
            <li>
                <a
                    href="{{ $link['url'] }}"
                    target="_blank"
                    rel="noopener noreferrer me"
                    class="{{ $box }} inline-flex items-center justify-center rounded-lg text-slate-500 ring-1 ring-inset ring-slate-200 transition duration-150 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-400 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white"
                >
                    @if ($link['kind'] === 'whatsapp')
                        <x-ui.icon name="whatsapp" class="{{ $glyph }}" />
                    @elseif ($link['kind'] === 'instagram')
                        <svg class="{{ $glyph }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false">
                            <rect x="3" y="3" width="18" height="18" rx="5" />
                            <circle cx="12" cy="12" r="4" />
                            <circle cx="17.5" cy="6.5" r="1.1" fill="currentColor" stroke="none" />
                        </svg>
                    @else
                        <svg class="{{ $glyph }}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="{{ $link['path'] }}" /></svg>
                    @endif
                    <span class="sr-only">{{ $link['label'] }} (opens in a new tab)</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
