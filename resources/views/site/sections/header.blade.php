{{--
    Section type `header` (placement global_header) — requirement §8, phase-03 §6.1, §8.6, §8.14.

    Receives (the renderer's variable contract):
      $section  the published header snapshot, or null when no header section is published yet
      $content  $section['fields']: show_company_name, company_name_override, login_button(_enabled),
                contact_button(_enabled), admission_button(_enabled), cta_button(_enabled), sticky,
                transparent_over_hero
      $items    $section['items']: `link` => up to three top-bar links (label, url, icon, new_tab)
      $media    $section['media']: logo_override_light, logo_override_dark
      $menus    $section['menus']: `menu_ref` => the resolved navigation tree
      $overlay  bool, decided by the layout: sit transparent over a hero that has a background

    Behaviour:
      · sticky when the section says so; it tightens and gains a surface once the page scrolls, with
        hysteresis (on after 48px, off under 4px) so the height change can never make it flicker;
      · over a hero it starts transparent inside a forced-dark scope (white text on the image) and
        turns into the normal opaque header once scrolled;
      · under `lg` the navigation moves to an off-canvas drawer: teleported to <body> so no ancestor
        can clip it, focus trapped, Escape and the backdrop close it, focus returns to the button;
      · Login is shown to signed-out visitors only (MenuVisibility::Guest semantics, §8.6);
      · with no published header section, only the brand and the theme toggle render — nothing
        invented, no dead links.
--}}

@php
    use App\Enums\Cms\ButtonStyle;

    $fields = (array) ($content ?? []);
    $hasSection = $section !== null;
    $overlay = (bool) ($overlay ?? false);
    $sticky = $hasSection ? (bool) data_get($fields, 'sticky', true) : false;

    $companyName = trim((string) (data_get($fields, 'company_name_override') ?: site_setting('company.name', '')));
    $showName = (bool) data_get($fields, 'show_company_name', true);
    $navigation = data_get($menus ?? [], 'menu_ref');

    $safeUrl = static fn (mixed $url): ?string => is_string($url) && preg_match('/^(https?:\/\/|mailto:|tel:|\/|#)/i', trim($url)) === 1 ? trim($url) : null;

    $button = static function (string $name) use ($fields, $safeUrl): ?array {
        if (! (bool) data_get($fields, $name.'_enabled', false)) {
            return null;
        }

        $link = data_get($fields, $name);
        $label = trim((string) data_get($link, 'label', ''));
        $url = $safeUrl(data_get($link, 'url'));

        return $label === '' || $url === null ? null : [
            'label' => $label,
            'url' => $url,
            'style' => (string) data_get($link, 'style', ButtonStyle::Primary->value),
            'new_tab' => (bool) data_get($link, 'new_tab', false),
        ];
    };

    $contactButton = $button('contact_button');
    $admissionButton = $button('admission_button');
    $loginButton = auth()->guest() ? $button('login_button') : null;
    $ctaButton = $button('cta_button');

    $drawerButtons = array_values(array_filter([$ctaButton, $admissionButton, $contactButton, $loginButton]));

    $topLinks = collect(data_get($items ?? [], 'link', []))
        ->map(static fn ($item): array => [
            'label' => trim((string) data_get($item, 'content.label', '')),
            'url' => $safeUrl(data_get($item, 'content.url')),
            'icon' => data_get($item, 'content.icon'),
            'new_tab' => (bool) data_get($item, 'content.new_tab', false),
        ])
        ->filter(static fn (array $link): bool => $link['label'] !== '' && $link['url'] !== null)
        ->take(3)
        ->values();

    $themeToggle = filter_var(rescue(static fn () => site_setting('website.show_theme_toggle', true), true), FILTER_VALIDATE_BOOLEAN);

    $hasDrawer = $hasSection && (filled(data_get($navigation, 'items')) || $drawerButtons !== [] || $topLinks->isNotEmpty());

    $position = match (true) {
        $overlay && $sticky => 'fixed inset-x-0 top-0',
        $overlay => 'absolute inset-x-0 top-0',
        $sticky => 'sticky top-0',
        default => 'relative',
    };
@endphp

<header
    x-data="{ scrolled: false, drawer: false }"
    x-init="scrolled = window.scrollY > 48"
    @if ($sticky || $overlay) x-on:scroll.window.passive="scrolled = scrolled ? window.scrollY > 4 : window.scrollY > 48" @endif
    x-on:resize.window="if (window.innerWidth >= 1024) { drawer = false }"
    class="{{ $position }} z-topbar w-full"
>
    <div
        @if ($overlay)
            x-bind:class="scrolled
                ? 'border-slate-200/80 bg-white/90 shadow-sm backdrop-blur-md dark:border-white/10 dark:bg-slate-950/85'
                : 'border-transparent bg-transparent'"
            class="border-b border-transparent bg-transparent transition-colors duration-200"
        @else
            x-bind:class="scrolled ? 'shadow-sm' : ''"
            class="border-b border-slate-200/80 bg-white/90 backdrop-blur-md transition-shadow duration-200 dark:border-white/10 dark:bg-slate-950/85"
        @endif
    >
        <div @if ($overlay) x-bind:class="{ 'dark': ! scrolled }" class="dark" @endif>
            @if ($topLinks->isNotEmpty())
                <div class="hidden border-b border-slate-200/70 md:block dark:border-white/10">
                    <div class="mx-auto flex h-9 max-w-screen-xl items-center justify-end gap-6 px-4 text-xs sm:px-6 lg:px-8">
                        @foreach ($topLinks as $link)
                            <a
                                href="{{ $link['url'] }}"
                                @if ($link['new_tab']) target="_blank" rel="noopener noreferrer" @endif
                                class="inline-flex items-center gap-1.5 rounded font-medium text-slate-600 transition duration-150 hover:text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-300 dark:hover:text-white"
                            >
                                @if (filled($link['icon']))
                                    <x-ui.icon :name="$link['icon']" class="h-3.5 w-3.5" />
                                @endif
                                <span>{{ $link['label'] }}</span>
                                @if ($link['new_tab'])
                                    <span class="sr-only">(opens in a new tab)</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <div
                x-bind:class="{ 'lg:h-16': scrolled, 'lg:h-20': ! scrolled }"
                class="mx-auto flex h-16 max-w-screen-xl items-center gap-4 px-4 transition-[height] duration-200 sm:px-6 lg:h-20 lg:gap-8 lg:px-8"
            >
                <x-site.brand
                    :logo-light="data_get($media ?? [], 'logo_override_light')"
                    :logo-dark="data_get($media ?? [], 'logo_override_dark')"
                    :name="$companyName"
                    :show-name="$showName"
                    class="mr-auto lg:mr-0"
                />

                @if (filled(data_get($navigation, 'items')))
                    <x-site.menu
                        :menu="$navigation"
                        variant="desktop"
                        label="Main"
                        id-prefix="site-nav"
                        class="hidden flex-1 lg:block"
                    />
                @else
                    <div class="hidden flex-1 lg:block" aria-hidden="true"></div>
                @endif

                <div class="flex items-center gap-2">
                    <x-site.theme-toggle />

                    @if ($contactButton)
                        <x-site.button :link="$contactButton" size="sm" class="hidden xl:inline-flex" />
                    @endif

                    @if ($admissionButton)
                        <x-site.button :link="$admissionButton" size="sm" class="hidden xl:inline-flex" />
                    @endif

                    @if ($loginButton)
                        <x-site.button :link="$loginButton" size="sm" class="hidden lg:inline-flex" />
                    @endif

                    @if ($ctaButton)
                        <x-site.button :link="$ctaButton" size="sm" class="hidden sm:inline-flex" />
                    @endif

                    @if ($hasDrawer)
                        <button
                            type="button"
                            x-on:click="drawer = true"
                            x-bind:aria-expanded="drawer ? 'true' : 'false'"
                            aria-expanded="false"
                            aria-controls="site-drawer"
                            class="-mr-1 inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-700 transition duration-150 hover:bg-slate-100 hover:text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 lg:hidden dark:text-slate-200 dark:hover:bg-white/10 dark:hover:text-white"
                        >
                            <x-ui.icon name="bars-3" class="h-6 w-6" />
                            <span class="sr-only">Open the menu</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($hasDrawer)
        <template x-teleport="body">
            <div
                id="site-drawer"
                x-show="drawer"
                x-cloak
                class="fixed inset-0 z-modal lg:hidden"
                role="dialog"
                aria-modal="true"
                aria-label="Site menu"
                x-on:keydown.escape.window="drawer = false"
            >
                <div
                    x-show="drawer"
                    x-transition.opacity.duration.200ms
                    x-on:click="drawer = false"
                    class="absolute inset-0 bg-slate-950/50 backdrop-blur-sm"
                    aria-hidden="true"
                ></div>

                <div
                    x-show="drawer"
                    x-trap.noscroll="drawer"
                    x-transition:enter="transform transition ease-out duration-200"
                    x-transition:enter-start="translate-x-full"
                    x-transition:enter-end="translate-x-0"
                    x-transition:leave="transform transition ease-in duration-150"
                    x-transition:leave-start="translate-x-0"
                    x-transition:leave-end="translate-x-full"
                    x-on:click="if ($event.target.closest('a')) { drawer = false }"
                    class="absolute inset-y-0 right-0 flex w-full max-w-sm flex-col bg-white shadow-modal dark:bg-slate-900"
                >
                    <div class="flex h-16 shrink-0 items-center justify-between gap-4 border-b border-slate-200 px-4 dark:border-white/10">
                        <x-site.brand
                            :logo-light="data_get($media ?? [], 'logo_override_light')"
                            :logo-dark="data_get($media ?? [], 'logo_override_dark')"
                            :name="$companyName"
                            :show-name="$showName"
                            size="sm"
                        />

                        <button
                            type="button"
                            data-autofocus
                            x-on:click="drawer = false"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-slate-600 transition duration-150 hover:bg-slate-100 hover:text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white"
                        >
                            <x-ui.icon name="x-mark" class="h-6 w-6" />
                            <span class="sr-only">Close the menu</span>
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto overscroll-contain px-3 py-4">
                        <x-site.menu :menu="$navigation" variant="drawer" label="Main" id-prefix="site-drawer-nav" />

                        @if ($topLinks->isNotEmpty())
                            <ul class="mt-6 space-y-1 border-t border-slate-200 pt-6 dark:border-white/10">
                                @foreach ($topLinks as $link)
                                    <li>
                                        <a
                                            href="{{ $link['url'] }}"
                                            @if ($link['new_tab']) target="_blank" rel="noopener noreferrer" @endif
                                            class="flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm font-medium text-slate-600 transition duration-150 hover:bg-slate-100 hover:text-slate-950 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white"
                                        >
                                            @if (filled($link['icon']))
                                                <x-ui.icon :name="$link['icon']" class="h-4 w-4 text-slate-400" />
                                            @endif
                                            <span>{{ $link['label'] }}</span>
                                            @if ($link['new_tab'])
                                                <span class="sr-only">(opens in a new tab)</span>
                                            @endif
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    @if ($drawerButtons !== [])
                        <div class="shrink-0 space-y-2 border-t border-slate-200 p-4 dark:border-white/10">
                            @foreach ($drawerButtons as $drawerButton)
                                <x-site.button :link="$drawerButton" :block="true" />
                            @endforeach
                        </div>
                    @endif

                    @if ($themeToggle)
                        <div class="flex shrink-0 items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">
                            <span>Theme</span>
                            <x-site.theme-toggle variant="segmented" />
                        </div>
                    @endif
                </div>
            </div>
        </template>
    @endif
</header>
