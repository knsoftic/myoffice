@extends('layouts.admin')

@section('title', 'Website')

{{--
    Website CMS overview — admin.website.index (phase-03 §7.1, §8.3): what is live, what is waiting,
    what is broken.

    Controller variables (Admin\Cms\WebsiteOverviewController@index):
      $siteState      'live' | 'maintenance' | 'disabled'
      $sections       array<string, array{label: string, total: int, enabled: int, published: int, unpublished: int}>
                      placement value => counts, one entry per SectionPlacement case
      $orphanedCount  int                         placed sections whose type is no longer registered (INV-2)
      $areas          array<string, array{total: ?int, attention: ?int, route: string}>
                      only the areas the viewer may open: menus, pages, cta_blocks, faqs, media, seo
      $lastPublish    ?array{type: string, label: string, at: CarbonInterface, by: ?string}
      $cacheVersion   int
      $canFlush       bool                        website_sections.change_status
      $lastSitemap    ?App\Models\Cms\SitemapGeneration   (null when the viewer has no seo.view)

    Writes: POST admin.website.cache.flush (throttle 6/min).
--}}

@php
    use App\Enums\Cms\SectionPlacement;
    use Illuminate\Support\Facades\Route as RouteFacade;

    $sections = $sections ?? [];
    $areas = $areas ?? [];

    [$stateColor, $stateLabel, $stateHelp, $stateIcon] = match ($siteState ?? 'live') {
        'maintenance' => ['amber', 'Maintenance mode', 'Visitors see the maintenance page (HTTP 503). Staff with website access see the real site with a ribbon.', 'wrench-screwdriver'],
        'disabled' => ['rose', 'Public site switched off', 'Visitors see the holding page (HTTP 503). Nothing is indexed.', 'eye-slash'],
        default => ['emerald', 'Live', 'The public site serves the last published version of every section and page.', 'globe-alt'],
    };

    $settingsUrl = RouteFacade::has('admin.settings.index') ? route('admin.settings.index', ['group' => 'maintenance']) : null;
    $siteUrl = RouteFacade::has('site.home') ? route('site.home') : url('/');

    $placementCards = [
        SectionPlacement::GlobalHeader->value => ['Header', 'bars-3', 'Edit header'],
        SectionPlacement::Home->value => ['Home page sections', 'home', 'Manage sections'],
        SectionPlacement::GlobalFooter->value => ['Footer', 'queue-list', 'Edit footer'],
    ];

    $areaMeta = [
        'menus' => ['Menus', 'bars-3', 'Build menus', null],
        'pages' => ['Pages', 'document', 'Manage pages', 'with unpublished changes'],
        'cta_blocks' => ['CTA blocks', 'megaphone', 'Manage CTAs', 'still drafts'],
        'faqs' => ['FAQs', 'question-mark-circle', 'Manage FAQs', 'still drafts'],
        'media' => ['Media library', 'photo', 'Open library', 'unused'],
        'seo' => ['SEO', 'magnifying-glass', 'Open SEO manager', null],
    ];
@endphp

@section('header')
    <x-ui.page-header title="Website" subtitle="What is live, what is waiting to be published, and what needs attention." icon="globe-alt">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow-top-right-on-square" :href="$siteUrl" target="_blank" rel="noopener">View site</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="space-y-6">
        {{-- ── Status strip ─────────────────────────────────────────────────────── --}}
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800">
                <x-ui.badge :color="$stateColor" :dot="true" :icon="$stateIcon">{{ $stateLabel }}</x-ui.badge>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ $stateHelp }}</p>
                @if ($settingsUrl)
                    @can('settings.view')
                        <a href="{{ $settingsUrl }}" class="mt-2 inline-flex text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Maintenance settings</a>
                    @endcan
                @endif
            </div>

            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-xs font-medium uppercase tracking-wider text-slate-400 dark:text-slate-500">Last publish</p>
                @if (! empty($lastPublish))
                    <p class="mt-1 truncate text-sm font-semibold text-slate-900 dark:text-white">
                        {{ $lastPublish['label'] }}
                        <span class="text-xs font-normal text-slate-500">({{ ($lastPublish['type'] ?? '') === 'page' ? 'page' : 'section' }})</span>
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ app_datetime($lastPublish['at']) }}@if (filled($lastPublish['by'] ?? null)) · {{ $lastPublish['by'] }}@endif
                    </p>
                @else
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Nothing has been published yet.</p>
                @endif
            </div>

            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-xs font-medium uppercase tracking-wider text-slate-400 dark:text-slate-500">Public cache</p>
                <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">Version {{ app_number((int) ($cacheVersion ?? 1)) }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Every publish moves to a new version automatically.</p>

                @if ($canFlush ?? false)
                    <x-ui.confirm
                        :action="route('admin.website.cache.flush')"
                        method="POST"
                        title="Flush the public cache?"
                        message="Every visitor gets a freshly rendered page on their next request. Nothing is deleted and nothing is published — use it when a change made outside the CMS is not showing."
                        confirm-label="Flush cache"
                        variant="warning"
                        icon="arrow-path"
                    >
                        <x-slot:trigger>
                            <x-ui.button size="sm" variant="secondary" icon="arrow-path" class="mt-2">Flush</x-ui.button>
                        </x-slot:trigger>
                    </x-ui.confirm>
                @endif
            </div>

            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-slate-800">
                <p class="text-xs font-medium uppercase tracking-wider text-slate-400 dark:text-slate-500">Sitemap</p>
                @if (! empty($lastSitemap))
                    <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">
                        {{ app_number((int) $lastSitemap->url_count) }} URLs
                        @if ((string) $lastSitemap->status !== 'ok')
                            <x-ui.badge color="rose" size="sm" class="ml-1">Failed</x-ui.badge>
                        @endif
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Built {{ app_datetime($lastSitemap->created_at) }} · {{ $lastSitemap->trigger }}</p>
                @else
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">No build recorded.</p>
                @endif
            </div>
        </div>

        @if ((int) ($orphanedCount ?? 0) > 0)
            <div class="flex items-start gap-3 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/25" role="alert">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                <p>
                    <strong>{{ app_number((int) $orphanedCount) }} {{ \Illuminate\Support\Str::plural('section', (int) $orphanedCount) }}</strong>
                    use a section type that is no longer registered. They are skipped on the public site and marked
                    “Orphaned type” in the section lists.
                </p>
            </div>
        @endif

        {{-- ── Sections, one card per placement ────────────────────────────────── --}}
        <div class="card-grid-3">
            @foreach ($placementCards as $placementValue => [$label, $icon, $action])
                @php
                    $card = $sections[$placementValue] ?? ['total' => 0, 'enabled' => 0, 'published' => 0, 'unpublished' => 0];
                @endphp
                <x-ui.card :hover="true" class="flex h-full flex-col">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                                <x-ui.icon :name="$icon" class="h-5 w-5" />
                            </span>
                            <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $label }}</h3>
                        </div>
                        @if ((int) $card['unpublished'] > 0)
                            <x-ui.badge color="amber" size="sm" icon="pencil">{{ app_number((int) $card['unpublished']) }} unpublished</x-ui.badge>
                        @endif
                    </div>

                    <dl class="mt-4 grid grid-cols-3 gap-2">
                        @foreach (['total' => 'sections', 'published' => 'published', 'enabled' => 'enabled'] as $metric => $caption)
                            <div>
                                <dd class="text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((int) ($card[$metric] ?? 0)) }}</dd>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">{{ $caption }}</dt>
                            </div>
                        @endforeach
                    </dl>

                    <div class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                        <x-ui.button size="sm" variant="ghost" icon-trailing="arrow-right" :href="route('admin.website.sections.index', ['placement' => $placementValue])">{{ $action }}</x-ui.button>
                    </div>
                </x-ui.card>
            @endforeach

            @if ((int) ($sections[SectionPlacement::Page->value]['total'] ?? 0) > 0)
                <x-ui.card class="flex h-full flex-col">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                            <x-ui.icon name="view-columns" class="h-5 w-5" />
                        </span>
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Sections on custom pages</h3>
                    </div>
                    <p class="mt-4 text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((int) $sections[SectionPlacement::Page->value]['total']) }}</p>
                    @if ((int) ($sections[SectionPlacement::Page->value]['unpublished'] ?? 0) > 0)
                        <p class="text-xs text-amber-700 dark:text-amber-400">{{ app_number((int) $sections[SectionPlacement::Page->value]['unpublished']) }} with unpublished changes</p>
                    @endif
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Manage them from each page built from sections.</p>
                </x-ui.card>
            @endif

            {{-- ── Other areas the viewer may open ────────────────────────────── --}}
            @foreach ($areas as $areaKey => $area)
                @php
                    [$label, $icon, $action, $attentionLabel] = $areaMeta[$areaKey] ?? [\Illuminate\Support\Str::headline((string) $areaKey), 'rectangle-stack', 'Open', null];
                    $url = isset($area['route']) && RouteFacade::has($area['route']) ? route($area['route']) : null;
                    $attention = $area['attention'] ?? null;
                @endphp
                <x-ui.card :hover="$url !== null" class="flex h-full flex-col">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                                <x-ui.icon :name="$icon" class="h-5 w-5" />
                            </span>
                            <h3 class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $label }}</h3>
                        </div>
                        @if ($attentionLabel !== null && (int) $attention > 0)
                            <x-ui.badge color="amber" size="sm">{{ app_number((int) $attention) }} {{ $attentionLabel }}</x-ui.badge>
                        @endif
                    </div>

                    <p class="mt-4 text-lg font-semibold tabular-nums text-slate-900 dark:text-white">
                        {{ ($area['total'] ?? null) === null ? '—' : app_number((int) $area['total']) }}
                    </p>

                    @if ($url)
                        <div class="mt-auto border-t border-slate-100 pt-3 dark:border-slate-800">
                            <x-ui.button size="sm" variant="ghost" icon-trailing="arrow-right" :href="$url">{{ $action }}</x-ui.button>
                        </div>
                    @endif
                </x-ui.card>
            @endforeach
        </div>
    </div>
@endsection
