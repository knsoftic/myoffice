@extends('layouts.admin')

@section('title', 'SEO')

{{--
    SEO manager — admin.website.seo.index (phase-03 §7.5, §8.12; requirement §105; D23).

    Controller variables (Admin\Cms\SeoController@index):
      $rows           LengthAwarePaginator<array>   SeoService::auditRows(), filtered, sorted, paginated. Each row: type
                     ('page' | 'route' | a later model basename), id, name, url, status, seo_meta_id, title,
                     meta_description, canonical_url, robots, og_image_media_id, sitemap_include, completeness, gaps, updated_at
      $ogImages       Collection<int, MediaAsset>   OG images of the rows on this page, keyed by id
      $filters        array<string, mixed>
      $sort           string   name | completeness | updated_at
      $direction      string   asc | desc
      $robotsOptions  array<string, string>
      $types          list<string>                  the target types present
      $sitemap        array{enabled: bool, last: ?App\Models\Cms\SitemapGeneration, url: ?string}
      $robots         array{mode: string, indexable: bool}
      $can            array{edit: bool, export: bool, robotsSettings: bool}
    Query string: search, type, robots (RobotsDirective value), gap (missing_title | missing_description |
    missing_og_image | noindex | excluded_from_sitemap), sort, direction, page.

    Targets are addressed as "page:{id}" or "route:{route name}" (§7.5 seo.edit ?target=). Rows of a later
    phase's model have no editor here until that phase adds its target form.

    Writes:
      POST admin.website.seo.bulk-robots           targets[] ("page:5", "route:site.home"), robots, sitemap_include ('' = unchanged)
      POST admin.website.seo.sitemap.regenerate    (throttled 6/min)
    robots.txt custom text is a Settings key (seo.robots_txt_custom, settings.edit) — this screen shows it
    read-only and links to Settings rather than widening seo.edit into a settings write (G-3).
--}}

@php
    use App\Enums\Cms\RobotsDirective;
    use Illuminate\Support\Facades\Route as RouteFacade;

    $can = array_merge(['edit' => false, 'export' => false, 'robotsSettings' => false], $can ?? []);
    $canEdit = (bool) $can['edit'];
    $canExport = (bool) $can['export'];
    $mediaService = app(\App\Services\Cms\MediaService::class);
    $ogImages = collect($ogImages ?? []);
    $sitemap = $sitemap ?? ['enabled' => true, 'last' => null];
    $robots = array_merge(['mode' => 'auto', 'indexable' => true], $robots ?? []);
    $robotsPreviewUrl = RouteFacade::has('admin.website.seo.robots.preview') ? route('admin.website.seo.robots.preview') : null;
    $sort = $sort ?? 'completeness';
    $direction = $direction ?? 'asc';

    $targetOf = static fn (array $row): ?string => match ($row['type']) {
        'page' => 'page:'.$row['id'],
        'route' => 'route:'.$row['name'],
        default => null,
    };

    $gapLabels = [
        'missing_title' => 'Missing title',
        'missing_description' => 'Missing description',
        'missing_og_image' => 'Missing social image',
        'noindex' => 'Not indexed',
        'excluded_from_sitemap' => 'Excluded from sitemap',
    ];

    $typeOptions = collect($types ?? ['page', 'route'])->mapWithKeys(fn (string $type): array => [$type => match ($type) { 'page' => 'Pages', 'route' => 'Static routes', default => \Illuminate\Support\Str::headline($type) }])->all();
    $lengthTone = static function (int $length, int $min, int $max): string {
        if ($length === 0) {
            return 'text-slate-400 dark:text-slate-500';
        }

        return $length >= $min && $length <= $max ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400';
    };

    $last = $sitemap['last'] ?? null;
    $settingsSeoUrl = RouteFacade::has('admin.settings.index') ? route('admin.settings.index', ['group' => 'seo']) : null;
@endphp

@section('header')
    <x-ui.page-header title="SEO" subtitle="Titles, descriptions, indexing and social previews for every public page, plus the sitemap and robots.txt." icon="magnifying-glass">
        <x-slot:actions>
            @if ($canExport)
                <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('admin.website.seo.export', request()->query())">Export CSV</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @include('admin.cms.partials.scripts')

    <div class="space-y-6">
        {{-- ── Sitemap + robots panels ─────────────────────────────────────────── --}}
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <x-ui.card title="Sitemap" icon="globe-alt">
                <x-slot:actions>
                    @if (RouteFacade::has('admin.website.seo.sitemap.history'))
                        <x-ui.button size="sm" variant="ghost" :href="route('admin.website.seo.sitemap.history')">History</x-ui.button>
                    @endif
                </x-slot:actions>

                @unless ($sitemap['enabled'] ?? true)
                    <div class="mb-3 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                        <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>The sitemap is switched off in Settings → SEO, so /sitemap.xml answers 404.</span>
                    </div>
                @endunless

                @if ($last)
                    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
                        <div>
                            <p class="text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">{{ app_number((int) $last->url_count) }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">URLs</p>
                        </div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">
                            <p>
                                Built {{ app_datetime($last->created_at) }} · {{ $last->trigger }} · {{ app_number((int) $last->duration_ms) }} ms
                                @if (($last->status ?? 'ok') !== 'ok')
                                    <x-ui.badge color="rose" size="sm" class="ml-1">Failed</x-ui.badge>
                                @endif
                            </p>
                            @if (is_array($last->providers) && $last->providers !== [])
                                <p class="mt-1 flex flex-wrap gap-1.5">
                                    @foreach ($last->providers as $provider => $count)
                                        <x-ui.badge color="slate" variant="outline" size="sm">{{ $provider }}: {{ app_number((int) $count) }}</x-ui.badge>
                                    @endforeach
                                </p>
                            @endif
                            @if (filled($last->failure_reason))
                                <p class="mt-1 text-rose-600 dark:text-rose-400">{{ $last->failure_reason }}</p>
                            @endif
                        </div>
                    </div>
                @else
                    <p class="text-sm text-slate-500 dark:text-slate-400">No sitemap has been built yet.</p>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($canEdit)
                        <form method="POST" action="{{ route('admin.website.seo.sitemap.regenerate') }}">
                            @csrf
                            <x-ui.button type="submit" size="sm" icon="arrow-path">Regenerate now</x-ui.button>
                        </form>
                    @endif
                    @if (filled($sitemap['url'] ?? null) && ($sitemap['enabled'] ?? true))
                        <x-ui.button size="sm" variant="secondary" icon="arrow-top-right-on-square" :href="$sitemap['url']" target="_blank" rel="noopener">/sitemap.xml</x-ui.button>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card title="robots.txt" icon="shield-check">
                <x-slot:actions>
                    <x-ui.badge :color="($robots['mode'] ?? 'auto') === 'custom' ? 'violet' : 'slate'" size="sm">{{ ($robots['mode'] ?? 'auto') === 'custom' ? 'Custom text' : 'Generated' }}</x-ui.badge>
                </x-slot:actions>

                @unless ($robots['indexable'])
                    <div class="mb-3 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                        <x-ui.icon name="lock-closed" class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>Search engines are told to stay away from the whole site (Settings → SEO: not indexable). robots.txt answers <code>Disallow: /</code> whatever its mode.</span>
                    </div>
                @endunless

                <p class="text-sm text-slate-600 dark:text-slate-300">
                    @if (($robots['mode'] ?? 'auto') === 'custom')
                        The custom text from Settings is served, with a Sitemap line added when the sitemap is on. Maintenance and a non-indexable site still override it.
                    @else
                        Generated: everything is allowed except the admin and portal areas, login, previews and original uploads, followed by the Sitemap line.
                    @endif
                </p>

                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($robotsPreviewUrl)
                        <x-ui.button size="sm" variant="secondary" icon="eye" :href="$robotsPreviewUrl">See the effective file</x-ui.button>
                    @endif
                    @if ($settingsSeoUrl && $can['robotsSettings'])
                        <x-ui.button size="sm" variant="ghost" icon="cog-6-tooth" :href="$settingsSeoUrl">Change in Settings</x-ui.button>
                    @endif
                </div>
            </x-ui.card>
        </div>

        {{-- ── Targets ─────────────────────────────────────────────────────────── --}}
        <div x-data="{ navigating: false }" x-on:submit.window="if ($event.target?.method === 'get') navigating = true" class="space-y-4">
            <x-ui.filter-bar placeholder="Search names, titles and addresses…" :reset="route('admin.website.seo.index')">
                <x-ui.form.select name="type" :options="$typeOptions" :selected="request('type')" placeholder="Any type" size="sm" aria-label="Filter by type" />
                <x-ui.form.select name="robots" :options="RobotsDirective::options()" :selected="request('robots')" placeholder="Any indexing" size="sm" aria-label="Filter by robots" />
                <x-ui.form.select name="gap" :options="$gapLabels" :selected="request('gap')" placeholder="Any gap" size="sm" aria-label="Filter by what is missing" />
            </x-ui.filter-bar>

            <x-ui.table loading="navigating" :is-empty="$rows->isEmpty()" :columns="7" :selectable="$canEdit" selection-label="target">
                <x-slot:head>
                    <x-ui.th-sortable column="name" :sort="$sort" :direction="$direction">Target</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3">Title</th>
                    <th scope="col" class="px-4 py-3">Description</th>
                    <th scope="col" class="px-4 py-3">Indexing</th>
                    <th scope="col" class="px-4 py-3">Social image</th>
                    <x-ui.th-sortable column="completeness" :sort="$sort" :direction="$direction" :numeric="true">Complete</x-ui.th-sortable>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </x-slot:head>

                @if ($canEdit)
                    <x-slot:bulk>
                        <x-ui.button size="sm" variant="secondary" icon="adjustments-horizontal" x-on:click="$dispatch('seo-bulk-targets', { targets: [...selected] }); $dispatch('open-modal', 'seo-bulk')">Set robots / sitemap</x-ui.button>

                    </x-slot:bulk>
                @endif

                @foreach ($rows as $row)
                    @php
                        $target = $targetOf($row);
                        $robotsCase = RobotsDirective::tryFrom((string) $row['robots']) ?? RobotsDirective::IndexFollow;
                        $titleLength = mb_strlen(trim((string) $row['title']));
                        $descriptionLength = mb_strlen(trim((string) $row['meta_description']));
                        $score = (int) $row['completeness'];
                        $ogAsset = $row['og_image_media_id'] !== null ? $ogImages->get((int) $row['og_image_media_id']) : null;
                        $thumb = $ogAsset ? $mediaService->url($ogAsset, 192) : null;
                    @endphp
                    <tr>
                        @if ($canEdit)
                            <td class="w-10">
                                @if ($target)
                                    <input type="checkbox" data-row-select value="{{ $target }}" x-model="selected" aria-label="Select {{ $row['name'] }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800">
                                @endif
                            </td>
                        @endif
                        <td class="min-w-[12rem]">
                            <div class="flex items-center gap-2">
                                <x-ui.badge :color="$row['type'] === 'page' ? 'indigo' : ($row['type'] === 'route' ? 'cyan' : 'slate')" size="sm">{{ $row['type'] === 'route' ? 'Route' : \Illuminate\Support\Str::headline($row['type']) }}</x-ui.badge>
                                <span class="font-medium text-slate-900 dark:text-white">{{ $row['name'] }}</span>
                            </div>
                            @if (filled($row['url']))
                                <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="mt-0.5 block truncate font-mono text-2xs text-slate-500 hover:text-brand-600 dark:text-slate-400">{{ $row['url'] }}</a>
                            @endif
                            @if (filled($row['canonical_url']))
                                <x-ui.badge color="violet" variant="outline" size="sm" class="mt-1" :title="$row['canonical_url']">Canonical override</x-ui.badge>
                            @endif
                        </td>
                        <td class="max-w-[16rem]">
                            <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $row['title'] ?: '—' }}</p>
                            <p class="text-2xs tabular-nums {{ $lengthTone($titleLength, 50, 60) }}">{{ $titleLength }} chars · ideal 50–60</p>
                        </td>
                        <td class="max-w-[18rem]">
                            <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $row['meta_description'] ?: '—' }}</p>
                            <p class="text-2xs tabular-nums {{ $lengthTone($descriptionLength, 120, 160) }}">{{ $descriptionLength }} chars · ideal 120–160</p>
                        </td>
                        <td>
                            <x-ui.badge :color="$robotsCase->isIndexable() ? 'emerald' : 'slate'" size="sm">{{ $robotsCase->isIndexable() ? 'index' : 'noindex' }}</x-ui.badge>
                            @unless ($row['sitemap_include'])
                                <x-ui.badge color="amber" variant="outline" size="sm" class="mt-1">not in sitemap</x-ui.badge>
                            @endunless
                        </td>
                        <td>
                            @if ($thumb)
                                <img src="{{ $thumb }}" alt="" class="h-10 w-16 rounded object-cover ring-1 ring-slate-200 dark:ring-slate-700" loading="lazy">
                            @elseif ($row['og_image_media_id'] !== null)
                                <x-ui.badge color="slate" size="sm">Set</x-ui.badge>
                            @else
                                <x-ui.badge color="amber" variant="outline" size="sm">missing</x-ui.badge>
                            @endif
                        </td>
                        <td class="w-28">
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 w-12 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800" aria-hidden="true">
                                    <div @class(['h-full rounded-full', 'bg-emerald-500' => $score >= 80, 'bg-amber-500' => $score >= 50 && $score < 80, 'bg-rose-500' => $score < 50]) style="width: {{ max(0, min(100, $score)) }}%"></div>
                                </div>
                                <span class="text-xs tabular-nums text-slate-700 dark:text-slate-300">{{ $score }}%</span>
                            </div>
                            @if ($row['updated_at'])
                                <p class="mt-0.5 text-2xs text-slate-400 dark:text-slate-500">{{ \App\Support\Format::instantDate($row['updated_at']) }}</p>
                            @endif
                        </td>
                        <td class="text-right">
                            @if ($target)
                                <x-ui.button size="sm" variant="ghost" :icon="$canEdit ? 'pencil' : 'eye'" :href="route('admin.website.seo.edit', ['target' => $target])">{{ $canEdit ? 'Edit' : 'View' }}</x-ui.button>
                            @endif
                        </td>
                    </tr>
                @endforeach

                <x-slot:empty>
                    <x-ui.empty-state icon="magnifying-glass" title="No targets match those filters">
                        <x-slot:action>
                            <x-ui.button variant="secondary" :href="route('admin.website.seo.index')">Clear filters</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                </x-slot:empty>

                <x-slot:footer>
                    <x-ui.pagination-summary :paginator="$rows" label="targets" />
                </x-slot:footer>
            </x-ui.table>
        </div>
    </div>
    @if ($canEdit)
        <div x-data="{ targets: [] }" x-on:seo-bulk-targets.window="targets = $event.detail.targets">
            <x-ui.modal name="seo-bulk" title="Change the selected targets" icon="adjustments-horizontal">
                <form id="seo-bulk-form" method="POST" action="{{ route('admin.website.seo.bulk-robots') }}" class="space-y-4">
                    @csrf
                    <template x-for="picked in targets" :key="picked">
                        <input type="hidden" name="targets[]" x-bind:value="picked">
                    </template>

                    <p>Applies to <strong x-text="targets.length"></strong> <span x-text="targets.length === 1 ? 'target' : 'targets'"></span>. Each change is audited with its old and new value.</p>

                    <x-ui.form.select name="robots" id="bulk-robots" label="Search engines" :options="RobotsDirective::options()" placeholder="Leave unchanged" />
                    <x-ui.form.select name="sitemap_include" id="bulk-sitemap" label="Sitemap" :options="['1' => 'Include in the sitemap', '0' => 'Exclude from the sitemap']" placeholder="Leave unchanged" />
                </form>

                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'seo-bulk')">Cancel</x-ui.button>
                    <x-ui.button type="submit" form="seo-bulk-form" icon="check">Apply to <span x-text="targets.length" class="ml-1"></span></x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        </div>
    @endif
@endsection
