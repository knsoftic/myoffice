@props([
    'mode' => 'preview',
    'target' => null,
    'exitUrl' => null,
    'editorUrl' => null,
])

{{--
    x-site.preview-ribbon — the fixed amber ribbon that tells staff they are NOT looking at the live
    site (phase-03 §6.10, §6.12).

        <x-site.preview-ribbon mode="preview" target="Home page" :exit-url="route('site.home')" />
        <x-site.preview-ribbon mode="maintenance" />
        <x-site.preview-ribbon mode="disabled" />

    preview      "Preview — draft content, not live", the target's name, and an exit link to the live
                 URL. Rendered only when the response is a preview, which is never cached (INV-9).
    maintenance  a signed-in user holding `website_sections.view` is looking at the real site while
                 visitors get the maintenance page.
    disabled     the same, while the public site is switched off.

    `role="status"` so the state is announced once, politely, rather than stealing focus.
--}}

@php
    $copy = match ($mode) {
        'maintenance' => ['Maintenance mode is on', 'Visitors see the maintenance page. You are seeing the site because you manage it.'],
        'disabled' => ['The public website is switched off', 'Visitors see the holding page. You are seeing the site because you manage it.'],
        default => ['Preview — draft content, not live', filled($target) ? (string) $target : null],
    };

    $safe = static fn (?string $url): ?string => is_string($url) && preg_match('/^(https?:\/\/|\/)/i', $url) === 1 ? $url : null;
    $exit = $safe($exitUrl);
    $editor = $safe($editorUrl);
@endphp

<div
    role="status"
    {{ $attributes->class('fixed inset-x-0 bottom-0 z-toast border-t border-amber-300 bg-amber-100/95 text-amber-950 shadow-[0_-8px_24px_-12px_rgb(0_0_0/0.25)] backdrop-blur dark:border-amber-500/40 dark:bg-amber-950/95 dark:text-amber-100') }}
>
    <div class="mx-auto flex max-w-screen-xl flex-col gap-2 px-4 py-2.5 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
        <p class="flex min-w-0 items-center gap-2.5">
            <x-ui.icon :name="$mode === 'preview' ? 'eye' : 'exclamation-triangle'" class="h-4 w-4 shrink-0 text-amber-700 dark:text-amber-300" />
            <span class="font-semibold">{{ $copy[0] }}</span>
            @if (filled($copy[1]))
                <span class="hidden truncate text-amber-800 sm:inline dark:text-amber-200/80">· {{ $copy[1] }}</span>
            @endif
        </p>

        @if ($exit !== null || $editor !== null)
            <div class="flex shrink-0 items-center gap-4">
                @if ($editor !== null)
                    <a href="{{ $editor }}" class="rounded font-medium underline underline-offset-4 hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">Back to the editor</a>
                @endif
                @if ($exit !== null)
                    <a href="{{ $exit }}" class="inline-flex items-center gap-1.5 rounded-md bg-amber-900 px-3 py-1.5 font-semibold text-amber-50 transition hover:bg-amber-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2 focus-visible:ring-offset-amber-100 dark:bg-amber-300 dark:text-amber-950 dark:hover:bg-amber-200 dark:focus-visible:ring-offset-amber-950">
                        Exit preview
                        <x-ui.icon name="arrow-right" class="h-3.5 w-3.5" />
                    </a>
                @endif
            </div>
        @endif
    </div>
</div>
