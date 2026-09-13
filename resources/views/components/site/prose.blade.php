@props([
    'html' => null,
    'profile' => 'cms',
    'size' => 'default',
])

{{--
    x-site.prose — the ONLY place the public site prints CMS rich text (INV-13, D25).

        <x-site.prose :html="$content['company_intro'] ?? null" />
        <x-site.prose :html="$siteSetting('contact.map_embed')" />   {{-- §12.2 Q3's map embed --}}

    Rich text is sanitised **on write and again on render**, by `App\Support\RichText::sanitize()`
    and nothing else: the database is not a trust boundary, so a row hand-written into `content`
    with a `<script>` tag still renders sanitised (FT-36). `profile` is a **name** resolved through
    that class's own closed map (`cms`, `material`) — an unknown name throws rather than being
    forwarded to the sanitiser as a config key.

    Every other `{!! !!}` in `resources/views/site/**` is a bug, and FT-37 is the static scan that
    says so. Pass HTML here; never print it yourself.

    Until `RichText` ships, the fallback below prints the value **escaped**. That is deliberately the
    safe direction: an editor briefly sees their markup as text instead of a visitor being served an
    unsanitised `<script>`.

    Typography is a hand-written list rather than @tailwindcss/typography, which this project does
    not install — the allowlisted tag set of §6.6 is small enough to style exactly.
--}}

@php
    $value = (string) ($html ?? (isset($slot) ? $slot->toHtml() : '') ?? '');
    $hasSanitiser = class_exists(\App\Support\RichText::class);

    $scale = match ($size) {
        'sm' => 'text-sm',
        'lg' => 'text-base sm:text-lg',
        default => 'text-[0.9375rem] sm:text-base',
    };
@endphp

@if (trim(strip_tags($value, '<img><iframe><br><hr>')) !== '' || str_contains($value, '<img') || str_contains($value, '<iframe'))
    <div {{ $attributes->class([
        $scale,
        'max-w-none leading-relaxed text-slate-600 dark:text-slate-300',
        '[&>*+*]:mt-4',
        '[&_h2]:mt-8 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:tracking-tight [&_h2]:text-slate-900 dark:[&_h2]:text-white',
        '[&_h3]:mt-6 [&_h3]:text-lg [&_h3]:font-semibold [&_h3]:text-slate-900 dark:[&_h3]:text-white',
        '[&_h4]:mt-6 [&_h4]:font-semibold [&_h4]:text-slate-900 dark:[&_h4]:text-white',
        '[&_strong]:font-semibold [&_strong]:text-slate-900 dark:[&_strong]:text-white',
        '[&_a]:font-medium [&_a]:text-brand-600 [&_a]:underline [&_a]:underline-offset-4 hover:[&_a]:text-brand-700 dark:[&_a]:text-brand-400 dark:hover:[&_a]:text-brand-300',
        '[&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:mt-1.5',
        '[&_blockquote]:border-l-2 [&_blockquote]:border-brand-500 [&_blockquote]:pl-4 [&_blockquote]:italic',
        '[&_img]:rounded-xl [&_img]:shadow-sm',
        '[&_figcaption]:mt-2 [&_figcaption]:text-xs [&_figcaption]:text-slate-500 dark:[&_figcaption]:text-slate-400',
        '[&_hr]:border-slate-200 dark:[&_hr]:border-slate-800',
        '[&_table]:w-full [&_table]:text-left [&_th]:border-b [&_th]:border-slate-200 [&_th]:py-2 [&_th]:font-semibold [&_td]:border-b [&_td]:border-slate-100 [&_td]:py-2 dark:[&_th]:border-slate-700 dark:[&_td]:border-slate-800',
        '[&_iframe]:aspect-video [&_iframe]:h-auto [&_iframe]:w-full [&_iframe]:rounded-xl [&_iframe]:border-0',
    ]) }}>
        @if ($hasSanitiser)
            {!! \App\Support\RichText::sanitize($value, $profile) !!}
        @else
            {{-- The sanitiser has not shipped yet: print it escaped rather than unsanitised. --}}
            {{ $value }}
        @endif
    </div>
@endif
