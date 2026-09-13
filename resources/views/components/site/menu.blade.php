@props([
    'menu' => null,
    'variant' => 'desktop',
    'label' => null,
    'idPrefix' => 'site-menu',
])

{{--
    x-site.menu — one navigation tree from a published snapshot (phase-03 §6.3, §8.14, §102).

        <x-site.menu :menu="$menus['menu_ref'] ?? null" variant="desktop" label="Main" />
        <x-site.menu :menu="$menus['menu_ref'] ?? null" variant="drawer"  label="Main" />
        <x-site.menu :menu="$menus['menu_ref_2'] ?? null" variant="footer" label="Explore" />
        <x-site.menu :menu="$menus['legal_menu_ref'] ?? null" variant="legal" label="Legal" />

    `menu` is the resolved tree `SnapshotBuilder` froze into the header / footer snapshot:
    `['id', 'name', 'slug', 'location', 'items' => [['id', 'label', 'url', 'link_type', 'icon',
    'new_tab', 'rel', 'visibility', 'children' => [...]], ...]]`. A bare list of items works too.

    Per request, after the page cache (§6.3, FT-44):
      · `visibility` is applied with `MenuVisibility::matches()` — a `guest` item disappears for a
        signed-in visitor (and the page cache never stores an authenticated render);
      · every href is re-checked against the site's scheme allowlist, because the database is not a
        trust boundary;
      · a `page` link whose page is no longer published is dropped (FT-28). The snapshot froze the
        tree at publish time, so without this a policy page unpublished afterwards would stay in the
        header as a dead link until the header was republished. One `pluck('slug')` per request,
        memoised on the request, and only when the tree holds a page link at all;
      · a label-only parent whose children were all filtered out is dropped with them.

    Variants:
      desktop  a horizontal bar; parents open a dropdown on hover, on click, and from the keyboard
               (ArrowDown opens and moves in, ArrowUp/ArrowDown move, Escape closes and returns focus)
      drawer   the off-canvas list; parents expand in place as a disclosure
      footer   a vertical column; children render indented under their parent
      legal    one inline row of small links

    `aria-current="page"` marks the link to the page being rendered. Items opening in a new tab carry
    `rel="noopener noreferrer"` and say so to screen readers.
--}}

@php
    use App\Enums\Cms\MenuVisibility;
    use App\Models\Cms\Page;

    $user = auth()->user();
    $request = request();

    $nodes = is_array(data_get($menu, 'items')) ? data_get($menu, 'items') : (is_array($menu) && array_is_list($menu) ? $menu : []);

    $containsPageLink = static function (array $list) use (&$containsPageLink): bool {
        foreach ($list as $node) {
            if (data_get($node, 'link_type') === 'page' || $containsPageLink((array) data_get($node, 'children', []))) {
                return true;
            }
        }

        return false;
    };

    $publishedSlugs = null;

    if ($containsPageLink($nodes)) {
        if (! $request->attributes->has('site.published_page_slugs')) {
            $request->attributes->set('site.published_page_slugs', rescue(
                static fn (): ?array => class_exists(Page::class)
                    ? Page::query()->visible()->pluck('slug')->map(static fn ($slug): string => (string) $slug)->all()
                    : null,
                null
            ));
        }

        $publishedSlugs = $request->attributes->get('site.published_page_slugs');
    }

    $here = rtrim(rawurldecode($request->getBaseUrl().$request->getPathInfo()), '/');
    $here = $here === '' ? '/' : $here;

    $isCurrent = static function (?string $url) use ($here, $request): bool {
        if ($url === null || str_contains($url, '#')) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && strcasecmp($host, $request->getHost()) !== 0) {
            return false;
        }

        $path = rtrim(rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?? '')), '/');

        return ($path === '' ? '/' : $path) === $here;
    };

    $filter = static function (array $list) use (&$filter, $user, $publishedSlugs, $isCurrent): array {
        $kept = [];

        foreach ($list as $node) {
            $visibility = MenuVisibility::tryFrom((string) data_get($node, 'visibility', 'all')) ?? MenuVisibility::All;

            if (! $visibility->matches($user)) {
                continue;
            }

            $label = trim((string) data_get($node, 'label', ''));
            $url = trim((string) data_get($node, 'url', ''));
            $type = (string) data_get($node, 'link_type', 'url');

            if ($label === '') {
                continue;
            }

            $url = preg_match('/^(https?:\/\/|mailto:|tel:|\/|#)/i', $url) === 1 ? $url : null;

            if ($type === 'page' && $url !== null && is_array($publishedSlugs)) {
                $slug = basename(rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?? '')));

                if (! in_array($slug, $publishedSlugs, true)) {
                    continue;
                }
            }

            $children = $filter((array) data_get($node, 'children', []));

            if ($url === null && $children === []) {
                continue;
            }

            $newTab = (bool) data_get($node, 'new_tab', false);
            $rel = trim((string) data_get($node, 'rel', ''));

            if ($newTab && ! str_contains($rel, 'noopener')) {
                $rel = trim('noopener noreferrer '.$rel);
            }

            $current = $isCurrent($url);

            $kept[] = [
                'id' => (int) data_get($node, 'id', count($kept)),
                'label' => $label,
                'url' => $url,
                'icon' => data_get($node, 'icon'),
                'new_tab' => $newTab,
                'rel' => $rel === '' ? null : $rel,
                'current' => $current,
                'children' => $children,
                'active' => $current || collect($children)->contains(static fn (array $child): bool => $child['current']),
            ];
        }

        return $kept;
    };

    $items = $filter($nodes);
    $navLabel = filled($label) ? (string) $label : (string) (data_get($menu, 'name') ?: 'Site');
    $prefix = $idPrefix.'-'.(data_get($menu, 'id') ?? 'x');
@endphp

@if ($items !== [])
    @if ($variant === 'desktop')
        <nav aria-label="{{ $navLabel }}" {{ $attributes }}>
            <ul class="flex items-center gap-0.5">
                @foreach ($items as $item)
                    @if ($item['children'] !== [])
                        <li
                            class="relative"
                            x-data="{
                                open: false,
                                links() { return Array.from(this.$refs.panel.querySelectorAll('a')); },
                                move(step) {
                                    const links = this.links();
                                    if (links.length === 0) { return; }
                                    const index = links.indexOf(document.activeElement);
                                    links[(index + step + links.length) % links.length].focus();
                                },
                                enter() { this.open = true; this.$nextTick(() => this.links()[0]?.focus()); },
                            }"
                            x-on:mouseenter="open = true"
                            x-on:mouseleave="open = false"
                            x-on:keydown.escape.stop="if (open) { open = false; $refs.toggle.focus(); }"
                            x-on:focusout="if (! $el.contains($event.relatedTarget)) { open = false; }"
                        >
                            <div class="flex items-center">
                                @if ($item['url'] !== null)
                                    <a
                                        href="{{ $item['url'] }}"
                                        @if ($item['new_tab']) target="_blank" @endif
                                        @if ($item['rel']) rel="{{ $item['rel'] }}" @endif
                                        @if ($item['current']) aria-current="page" @endif
                                        @class([
                                            'rounded-l-lg py-2 pl-3 pr-1 text-sm font-medium transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60',
                                            'text-brand-700 dark:text-white' => $item['active'],
                                            'text-slate-700 hover:text-slate-950 dark:text-slate-300 dark:hover:text-white' => ! $item['active'],
                                        ])
                                    >{{ $item['label'] }}</a>
                                    <button
                                        type="button"
                                        x-ref="toggle"
                                        x-on:click="open = ! open"
                                        x-on:keydown.arrow-down.prevent="enter()"
                                        x-bind:aria-expanded="open ? 'true' : 'false'"
                                        aria-expanded="false"
                                        aria-controls="{{ $prefix }}-panel-{{ $item['id'] }}"
                                        class="rounded-r-lg py-2 pl-0.5 pr-2 text-slate-500 transition duration-150 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-400 dark:hover:text-white"
                                    >
                                        <x-ui.icon name="chevron-down" class="h-4 w-4 transition-transform duration-150" x-bind:class="open ? 'rotate-180' : ''" />
                                        <span class="sr-only">{{ $item['label'] }} submenu</span>
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        x-ref="toggle"
                                        x-on:click="open = ! open"
                                        x-on:keydown.arrow-down.prevent="enter()"
                                        x-bind:aria-expanded="open ? 'true' : 'false'"
                                        aria-expanded="false"
                                        aria-controls="{{ $prefix }}-panel-{{ $item['id'] }}"
                                        @class([
                                            'inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60',
                                            'text-brand-700 dark:text-white' => $item['active'],
                                            'text-slate-700 hover:text-slate-950 dark:text-slate-300 dark:hover:text-white' => ! $item['active'],
                                        ])
                                    >
                                        <span>{{ $item['label'] }}</span>
                                        <x-ui.icon name="chevron-down" class="h-4 w-4 text-slate-400 transition-transform duration-150" x-bind:class="open ? 'rotate-180' : ''" />
                                    </button>
                                @endif
                            </div>

                            <div
                                x-ref="panel"
                                id="{{ $prefix }}-panel-{{ $item['id'] }}"
                                x-show="open"
                                x-cloak
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100"
                                x-transition:leave-end="opacity-0"
                                x-on:keydown.arrow-down.prevent="move(1)"
                                x-on:keydown.arrow-up.prevent="move(-1)"
                                class="absolute left-0 top-full z-dropdown pt-2"
                            >
                                <ul class="w-64 rounded-xl border border-slate-200/80 bg-white p-1.5 shadow-dropdown dark:border-white/10 dark:bg-slate-900">
                                    @foreach ($item['children'] as $child)
                                        @if ($child['url'] !== null)
                                            <li>
                                                <a
                                                    href="{{ $child['url'] }}"
                                                    @if ($child['new_tab']) target="_blank" @endif
                                                    @if ($child['rel']) rel="{{ $child['rel'] }}" @endif
                                                    @if ($child['current']) aria-current="page" @endif
                                                    @class([
                                                        'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60',
                                                        'bg-brand-50 font-semibold text-brand-700 dark:bg-white/10 dark:text-white' => $child['current'],
                                                        'text-slate-700 hover:bg-slate-50 hover:text-slate-950 focus:bg-slate-50 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white dark:focus:bg-white/5' => ! $child['current'],
                                                    ])
                                                >
                                                    @if (filled($child['icon']))
                                                        <x-ui.icon :name="$child['icon']" class="h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400" />
                                                    @endif
                                                    <span class="min-w-0 flex-1">{{ $child['label'] }}</span>
                                                    @if ($child['new_tab'])
                                                        <x-ui.icon name="arrow-top-right-on-square" class="h-3.5 w-3.5 shrink-0 text-slate-400" />
                                                        <span class="sr-only">(opens in a new tab)</span>
                                                    @endif
                                                </a>
                                            </li>
                                        @endif
                                    @endforeach
                                </ul>
                            </div>
                        </li>
                    @elseif ($item['url'] !== null)
                        <li>
                            <a
                                href="{{ $item['url'] }}"
                                @if ($item['new_tab']) target="_blank" @endif
                                @if ($item['rel']) rel="{{ $item['rel'] }}" @endif
                                @if ($item['current']) aria-current="page" @endif
                                @class([
                                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60',
                                    'text-brand-700 dark:text-white' => $item['current'],
                                    'text-slate-700 hover:text-slate-950 dark:text-slate-300 dark:hover:text-white' => ! $item['current'],
                                ])
                            >
                                <span>{{ $item['label'] }}</span>
                                @if ($item['new_tab'])
                                    <span class="sr-only">(opens in a new tab)</span>
                                @endif
                            </a>
                        </li>
                    @endif
                @endforeach
            </ul>
        </nav>
    @elseif ($variant === 'drawer')
        <nav aria-label="{{ $navLabel }}" {{ $attributes }}>
            <ul class="space-y-1">
                @foreach ($items as $item)
                    <li @if ($item['children'] !== []) x-data="{ expanded: {{ $item['active'] ? 'true' : 'false' }} }" @endif>
                        <div class="flex items-center gap-1">
                            @if ($item['url'] !== null)
                                <a
                                    href="{{ $item['url'] }}"
                                    @if ($item['new_tab']) target="_blank" @endif
                                    @if ($item['rel']) rel="{{ $item['rel'] }}" @endif
                                    @if ($item['current']) aria-current="page" @endif
                                    @class([
                                        'flex min-h-11 flex-1 items-center gap-3 rounded-lg px-3 text-base font-medium transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60',
                                        'bg-brand-50 text-brand-700 dark:bg-white/10 dark:text-white' => $item['current'],
                                        'text-slate-800 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-white/5' => ! $item['current'],
                                    ])
                                >
                                    @if (filled($item['icon']))
                                        <x-ui.icon :name="$item['icon']" class="h-5 w-5 shrink-0 text-slate-400" />
                                    @endif
                                    <span>{{ $item['label'] }}</span>
                                    @if ($item['new_tab'])
                                        <span class="sr-only">(opens in a new tab)</span>
                                    @endif
                                </a>
                            @else
                                <span class="flex min-h-11 flex-1 items-center px-3 text-base font-medium text-slate-800 dark:text-slate-200">{{ $item['label'] }}</span>
                            @endif

                            @if ($item['children'] !== [])
                                <button
                                    type="button"
                                    x-on:click="expanded = ! expanded"
                                    x-bind:aria-expanded="expanded ? 'true' : 'false'"
                                    aria-expanded="{{ $item['active'] ? 'true' : 'false' }}"
                                    aria-controls="{{ $prefix }}-group-{{ $item['id'] }}"
                                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-slate-500 transition duration-150 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-400 dark:hover:bg-white/5 dark:hover:text-white"
                                >
                                    <x-ui.icon name="chevron-down" class="h-5 w-5 transition-transform duration-150" x-bind:class="expanded ? 'rotate-180' : ''" />
                                    <span class="sr-only">{{ $item['label'] }} submenu</span>
                                </button>
                            @endif
                        </div>

                        @if ($item['children'] !== [])
                            <ul
                                id="{{ $prefix }}-group-{{ $item['id'] }}"
                                x-show="expanded"
                                @unless ($item['active']) x-cloak @endunless
                                class="mb-2 ml-3 mt-1 space-y-0.5 border-l border-slate-200 pl-3 dark:border-white/10"
                            >
                                @foreach ($item['children'] as $child)
                                    @if ($child['url'] !== null)
                                        <li>
                                            <a
                                                href="{{ $child['url'] }}"
                                                @if ($child['new_tab']) target="_blank" @endif
                                                @if ($child['rel']) rel="{{ $child['rel'] }}" @endif
                                                @if ($child['current']) aria-current="page" @endif
                                                @class([
                                                    'flex min-h-10 items-center rounded-lg px-3 text-sm transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60',
                                                    'font-semibold text-brand-700 dark:text-white' => $child['current'],
                                                    'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-white/5 dark:hover:text-white' => ! $child['current'],
                                                ])
                                            >
                                                {{ $child['label'] }}
                                                @if ($child['new_tab'])
                                                    <span class="sr-only">(opens in a new tab)</span>
                                                @endif
                                            </a>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>
    @elseif ($variant === 'legal')
        <nav aria-label="{{ $navLabel }}" {{ $attributes }}>
            <ul class="flex flex-wrap items-center gap-x-6 gap-y-2">
                @foreach ($items as $item)
                    @foreach (array_merge([$item], $item['children']) as $link)
                        @if ($link['url'] !== null)
                            <li>
                                <a
                                    href="{{ $link['url'] }}"
                                    @if ($link['new_tab']) target="_blank" @endif
                                    @if ($link['rel']) rel="{{ $link['rel'] }}" @endif
                                    @if ($link['current']) aria-current="page" @endif
                                    class="rounded text-sm text-slate-500 underline-offset-4 transition duration-150 hover:text-slate-900 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-400 dark:hover:text-white"
                                >{{ $link['label'] }}</a>
                            </li>
                        @endif
                    @endforeach
                @endforeach
            </ul>
        </nav>
    @else
        <nav aria-label="{{ $navLabel }}" {{ $attributes }}>
            <ul class="space-y-3">
                @foreach ($items as $item)
                    <li>
                        @if ($item['url'] !== null)
                            <a
                                href="{{ $item['url'] }}"
                                @if ($item['new_tab']) target="_blank" @endif
                                @if ($item['rel']) rel="{{ $item['rel'] }}" @endif
                                @if ($item['current']) aria-current="page" @endif
                                class="rounded text-sm text-slate-600 transition duration-150 hover:text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-400 dark:hover:text-white"
                            >
                                {{ $item['label'] }}
                                @if ($item['new_tab'])
                                    <span class="sr-only">(opens in a new tab)</span>
                                @endif
                            </a>
                        @else
                            <span class="text-sm font-medium text-slate-900 dark:text-slate-200">{{ $item['label'] }}</span>
                        @endif

                        @if ($item['children'] !== [])
                            <ul class="mt-3 space-y-2.5 border-l border-slate-200 pl-3 dark:border-white/10">
                                @foreach ($item['children'] as $child)
                                    @if ($child['url'] !== null)
                                        <li>
                                            <a
                                                href="{{ $child['url'] }}"
                                                @if ($child['new_tab']) target="_blank" @endif
                                                @if ($child['rel']) rel="{{ $child['rel'] }}" @endif
                                                @if ($child['current']) aria-current="page" @endif
                                                class="rounded text-sm text-slate-500 transition duration-150 hover:text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-500 dark:hover:text-white"
                                            >{{ $child['label'] }}</a>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif
@endif
