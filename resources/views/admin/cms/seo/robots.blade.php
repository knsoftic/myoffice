@extends('layouts.admin')

@section('title', 'robots.txt')

{{--
    robots.txt preview — admin.website.seo.robots.preview (phase-03 §7.5, §6.5, §8.12 robots panel; D-W3-13).
    Shows the file exactly as a crawler receives it now, including the lockdown that overrides custom text
    while the site is not indexable, in maintenance, or switched off. JSON and ?format=text are answered by
    the controller.

    Controller variables (Admin\Cms\SeoController@robotsPreview):
      $mode             'auto' | 'custom'     seo.robots_txt_mode
      $body             string                SeoService::robotsTxt()
      $indexable        bool                  seo.robots_indexable
      $maintenance      bool                  maintenance.maintenance_mode
      $publicSite       bool                  maintenance.public_site_enabled
      $canEditSettings  bool                  settings.edit — the custom text is a settings key, never seo.edit (G-3)
--}}

@php
    use Illuminate\Support\Facades\Route as RouteFacade;

    $locked = ! ($indexable ?? true) || ($maintenance ?? false) || ! ($publicSite ?? true);
    $reasons = array_filter([
        ! ($indexable ?? true) ? 'the site is set to not be indexed' : null,
        ($maintenance ?? false) ? 'maintenance mode is on' : null,
        ! ($publicSite ?? true) ? 'the public site is switched off' : null,
    ]);
    $settingsUrl = RouteFacade::has('admin.settings.index') ? route('admin.settings.index', ['group' => 'seo']) : null;
    $textUrl = route('admin.website.seo.robots.preview', ['format' => 'text']);
@endphp

@section('header')
    <x-ui.page-header
        title="robots.txt"
        subtitle="The effective file, exactly as a crawler receives it at /robots.txt."
        icon="shield-check"
        :back="route('admin.website.seo.index')"
        :badge="($mode ?? 'auto') === 'custom' ? 'Custom text' : 'Generated'"
        :badge-color="($mode ?? 'auto') === 'custom' ? 'violet' : 'slate'"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="document-text" :href="$textUrl" target="_blank" rel="noopener">Plain text</x-ui.button>
            @if (($canEditSettings ?? false) && $settingsUrl)
                <x-ui.button variant="secondary" icon="cog-6-tooth" :href="$settingsUrl">Change in Settings</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            @if ($locked)
                <div class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25" role="status">
                    <x-ui.icon name="lock-closed" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Crawlers are told to stay away because {{ implode(' and ', $reasons) }}. This wins over the {{ ($mode ?? 'auto') === 'custom' ? 'custom text' : 'generated rules' }} until it changes.</span>
                </div>
            @endif

            <div class="overflow-x-auto rounded-xl bg-slate-950 p-4 ring-1 ring-slate-800">
                <pre class="text-sm leading-relaxed text-slate-100">{{ $body ?? '' }}</pre>
            </div>
        </div>

        <x-ui.card title="What each part means" icon="information-circle">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="font-mono text-xs font-semibold text-slate-900 dark:text-white">User-agent: *</dt>
                    <dd class="text-slate-500 dark:text-slate-400">The rules below apply to every crawler.</dd>
                </div>
                <div>
                    <dt class="font-mono text-xs font-semibold text-slate-900 dark:text-white">Disallow: /admin, /student, …</dt>
                    <dd class="text-slate-500 dark:text-slate-400">The admin and portal areas, login, previews and original uploads are never crawled.</dd>
                </div>
                <div>
                    <dt class="font-mono text-xs font-semibold text-slate-900 dark:text-white">Disallow: /</dt>
                    <dd class="text-slate-500 dark:text-slate-400">Nothing may be crawled — used while the site is closed or not indexable.</dd>
                </div>
                <div>
                    <dt class="font-mono text-xs font-semibold text-slate-900 dark:text-white">Sitemap: …/sitemap.xml</dt>
                    <dd class="text-slate-500 dark:text-slate-400">Where the list of public pages lives. Omitted during a lockdown and when the sitemap is off.</dd>
                </div>
            </dl>
        </x-ui.card>
    </div>
@endsection
