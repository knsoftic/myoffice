@extends('layouts.admin')

@section('title', 'Guide')

@section('header')
    <x-ui.page-header title="Daftar chalane ka tareeqa"
                      subtitle="Setup, rozana ka kaam, har module — aur woh cheezein jo abhi tooti hui hain."
                      icon="book-open">
        @if ($updatedAt)
            <x-slot:actions>
                <span class="text-xs text-slate-500 dark:text-slate-400">
                    {{ \App\Support\Format::date($updatedAt) }}
                </span>
            </x-slot:actions>
        @endif
    </x-ui.page-header>
@endsection

@section('content')
    @if ($missing)
        <x-ui.empty-state icon="document-magnifying-glass"
                          title="Guide is not in this build"
                          :description="$missing . ' is missing from the deployed files. The guide ships with the code, so a deploy that skipped docs/ is the usual cause.'" />
    @else
        <div class="grid gap-4 lg:grid-cols-4">
            {{-- Fehrist. Sticky on wide screens so the reader keeps their place mid-task. --}}
            <nav class="lg:col-span-1" aria-label="Fehrist">
                <div class="lg:sticky lg:top-4">
                    <x-ui.card title="Fehrist">
                        <ol class="space-y-0.5 text-sm">
                            @foreach ($sections as $section)
                                <li @class(['ps-3 border-s border-slate-200 dark:border-slate-700' => $section['level'] === 3])>
                                    <a href="#{{ $section['id'] }}"
                                       @class([
                                           'block rounded px-2 py-1 transition hover:bg-slate-100 dark:hover:bg-slate-800',
                                           'font-semibold text-slate-800 dark:text-slate-100' => $section['level'] === 2,
                                           'text-slate-600 dark:text-slate-300' => $section['level'] === 3,
                                       ])>{{ $section['title'] }}</a>
                                </li>
                            @endforeach
                        </ol>
                    </x-ui.card>
                </div>
            </nav>

            <div class="lg:col-span-3">
                <x-ui.card>
                    {{--
                        Sanitised by `RichText::sanitize()` under the `material` profile, then given
                        heading ids by `HelpController::indexed()` — see the raw-output allowlist row
                        for this view, which names both.
                    --}}
                    <article class="guide-prose">
                        {!! $html !!}
                    </article>
                </x-ui.card>
            </div>
        </div>

        {{--
            Scoped to this one article rather than added to the global stylesheet: the guide is the
            only place in the admin panel that renders long-form markdown, and a `.prose` in the
            shared CSS would be a style nothing else asks for.
        --}}
        <style nonce="{{ csp_nonce() }}">
            .guide-prose { color: rgb(51 65 85); font-size: 15px; line-height: 1.7; }
            .dark .guide-prose { color: rgb(203 213 225); }
            .guide-prose h2 {
                font-size: 20px; font-weight: 700; margin: 32px 0 12px;
                padding-top: 16px; border-top: 1px solid rgb(226 232 240);
                color: rgb(15 23 42); scroll-margin-top: 16px;
            }
            .dark .guide-prose h2 { color: rgb(241 245 249); border-color: rgb(51 65 65); }
            .guide-prose h2:first-child { margin-top: 0; padding-top: 0; border-top: 0; }
            .guide-prose h3 {
                font-size: 16px; font-weight: 600; margin: 24px 0 8px;
                color: rgb(30 41 59); scroll-margin-top: 16px;
            }
            .dark .guide-prose h3 { color: rgb(226 232 240); }
            .guide-prose h4 { font-size: 15px; font-weight: 600; margin: 18px 0 6px; }
            .guide-prose p { margin: 0 0 12px; max-width: 72ch; }
            .guide-prose ul, .guide-prose ol { margin: 0 0 12px; padding-inline-start: 22px; max-width: 72ch; }
            .guide-prose ul { list-style: disc; }
            .guide-prose ol { list-style: decimal; }
            .guide-prose li { margin-bottom: 5px; }
            .guide-prose a { color: rgb(37 99 235); text-decoration: underline; text-underline-offset: 2px; }
            .dark .guide-prose a { color: rgb(125 178 255); }
            .guide-prose strong { font-weight: 700; color: rgb(15 23 42); }
            .dark .guide-prose strong { color: rgb(241 245 249); }
            .guide-prose code {
                font-family: ui-monospace, 'Cascadia Mono', Consolas, monospace;
                font-size: 0.87em; background: rgb(241 245 249); padding: 1.5px 5px; border-radius: 3px;
            }
            .dark .guide-prose code { background: rgb(30 41 59); }
            .guide-prose pre {
                background: rgb(241 245 249); padding: 12px 14px; border-radius: 6px;
                overflow-x: auto; margin: 0 0 14px; font-size: 13px; line-height: 1.55;
            }
            .dark .guide-prose pre { background: rgb(15 23 42); }
            .guide-prose pre code { background: none; padding: 0; font-size: inherit; }
            .guide-prose blockquote {
                border-inline-start: 3px solid rgb(148 163 184);
                padding: 2px 0 2px 14px; margin: 0 0 14px; color: rgb(71 85 105);
            }
            .dark .guide-prose blockquote { color: rgb(148 163 184); border-color: rgb(71 85 105); }
            /* Tables scroll inside their own container, never the page (CLAUDE.md §6). */
            .guide-prose table {
                width: 100%; border-collapse: collapse; margin: 0 0 16px; font-size: 14px;
                display: block; overflow-x: auto;
            }
            .guide-prose th, .guide-prose td {
                text-align: start; padding: 7px 11px; border-bottom: 1px solid rgb(226 232 240);
                vertical-align: top;
            }
            .dark .guide-prose th, .dark .guide-prose td { border-color: rgb(51 65 85); }
            .guide-prose th { font-weight: 600; background: rgb(248 250 252); white-space: nowrap; }
            .dark .guide-prose th { background: rgb(30 41 59); }
            .guide-prose hr { border: 0; border-top: 1px solid rgb(226 232 240); margin: 24px 0; }
            .dark .guide-prose hr { border-color: rgb(51 65 85); }
        </style>
    @endif
@endsection
