{{--
    Section type `footer` (placement global_footer) — requirements §8 and §100, phase-03 §6.1, §8.14.

    Receives:
      $section  the published footer snapshot, or null when none is published yet
      $content  about_text, column_1_heading, column_2_heading, show_contact, show_social,
                show_newsletter (read-only until a newsletter module exists — ignored here),
                copyright_override
      $items    link => badges: [content: label, url, new_tab; media: logo-profile image]
      $media    logo_override, badge_1, badge_2
      $menus    menu_ref (column 1), menu_ref_2 (column 2), legal_menu_ref (the legal row)

    Always a deep slate band in both themes (a forced-dark scope), so its contrast never depends on the
    visitor's theme. With no published footer section it still renders what Phase 2's settings already
    know — the brand, the contact block, the social profiles and the copyright line — so an unfinished
    CMS never produces a page with no way to reach the company.

    Contact block (Phase 2 `contact.*`): address with city and country, phone, WhatsApp, email and the
    business hours, each only when set. Business hours are wall-clock "HH:MM" strings, not timestamps,
    so they are printed as entered (never shifted through a timezone) and consecutive days with the same
    hours are grouped ("Mon–Fri 09:00–18:00").

    Copyright: `copyright_override`, else `company.copyright_text`, else "© {year} {company}";
    `{year}` (and `{company}`) are interpolated, the year through app_date() in the display timezone.
--}}

@php
    $fields = (array) ($content ?? []);
    $menus = (array) ($menus ?? []);

    $companyName = trim((string) site_setting('company.name', ''));
    $aboutText = trim((string) (data_get($fields, 'about_text') ?: site_setting('company.tagline', '')));

    $columnOne = data_get($menus, 'menu_ref');
    $columnTwo = data_get($menus, 'menu_ref_2');
    $legal = data_get($menus, 'legal_menu_ref');

    $columns = collect([
        ['heading' => trim((string) data_get($fields, 'column_1_heading', '')), 'menu' => $columnOne, 'id' => 'footer-col-1'],
        ['heading' => trim((string) data_get($fields, 'column_2_heading', '')), 'menu' => $columnTwo, 'id' => 'footer-col-2'],
    ])->filter(static fn (array $column): bool => filled(data_get($column['menu'], 'items')))->values();

    $showContact = (bool) data_get($fields, 'show_contact', true);
    $showSocial = (bool) data_get($fields, 'show_social', true);

    // Contact details.
    $address = collect([site_setting('contact.address'), site_setting('contact.city'), site_setting('contact.country')])
        ->map(static fn ($part): string => trim((string) $part))
        ->filter()
        ->implode(', ');

    $phone = trim((string) site_setting('contact.phone', ''));
    $phoneHref = $phone !== '' ? 'tel:'.preg_replace('/[^\d+]/', '', $phone) : null;

    $whatsapp = trim((string) site_setting('contact.whatsapp', ''));
    $whatsappDigits = preg_replace('/\D/', '', $whatsapp);
    $whatsappHref = $whatsappDigits !== '' && strlen($whatsappDigits) >= 8 ? 'https://wa.me/'.$whatsappDigits : null;

    $email = trim((string) site_setting('contact.email', ''));
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';

    $hoursRaw = site_setting('contact.business_hours');
    $hoursRaw = is_string($hoursRaw) ? json_decode($hoursRaw, true) : $hoursRaw;
    $hoursRows = [];

    if (is_array($hoursRaw)) {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $groups = [];

        foreach ($days as $day) {
            $entry = $hoursRaw[$day] ?? null;

            if (! is_array($entry)) {
                continue;
            }

            $open = (string) ($entry['open'] ?? '');
            $close = (string) ($entry['close'] ?? '');
            $closed = filter_var($entry['closed'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $value = $closed
                ? 'Closed'
                : (preg_match('/^\d{2}:\d{2}$/', $open) === 1 && preg_match('/^\d{2}:\d{2}$/', $close) === 1 ? $open.'–'.$close : null);

            if ($value === null) {
                continue;
            }

            $last = array_key_last($groups);

            if ($last !== null && $groups[$last]['value'] === $value && $groups[$last]['to_index'] === array_search($day, $days, true) - 1) {
                $groups[$last]['to'] = $day;
                $groups[$last]['to_index']++;
            } else {
                $groups[] = ['from' => $day, 'to' => $day, 'to_index' => array_search($day, $days, true), 'value' => $value];
            }
        }

        foreach ($groups as $group) {
            $from = ucfirst(substr($group['from'], 0, 3));
            $to = ucfirst(substr($group['to'], 0, 3));
            $hoursRows[] = ['days' => $from === $to ? $from : $from.'–'.$to, 'value' => $group['value']];
        }
    }

    $hasContact = $showContact && ($address !== '' || $phone !== '' || $whatsappHref !== null || $email !== '' || $hoursRows !== []);

    // Badges: repeater items first, then the two fixed badge slots.
    $badges = collect(data_get($items ?? [], 'link', []))
        ->map(static fn ($item): array => [
            'media' => data_get($item, 'media'),
            'label' => trim((string) data_get($item, 'content.label', '')),
            'url' => data_get($item, 'content.url'),
            'new_tab' => (bool) data_get($item, 'content.new_tab', false),
        ])
        ->merge(collect([data_get($media ?? [], 'badge_1'), data_get($media ?? [], 'badge_2')])->map(static fn ($badge): array => [
            'media' => $badge,
            'label' => trim((string) data_get($badge, 'alt', '')),
            'url' => null,
            'new_tab' => false,
        ]))
        ->filter(static fn (array $badge): bool => filled(data_get($badge['media'], 'url')))
        ->map(static function (array $badge): array {
            $badge['media'] = array_merge((array) $badge['media'], [
                'alt' => filled(data_get($badge['media'], 'alt')) ? data_get($badge['media'], 'alt') : $badge['label'],
            ]);
            $badge['url'] = is_string($badge['url']) && preg_match('/^(https?:\/\/|\/)/i', $badge['url']) === 1 ? $badge['url'] : null;

            return $badge;
        })
        ->values();

    $copyright = trim((string) (data_get($fields, 'copyright_override') ?: site_setting('company.copyright_text', '')));
    $year = app_date(now(), 'Y');

    $copyright = $copyright === ''
        ? '© '.$year.($companyName !== '' ? ' '.$companyName : '')
        : strtr($copyright, ['{year}' => $year, '{company}' => $companyName]);

    $logo = data_get($media ?? [], 'logo_override');
@endphp

<footer class="dark" aria-labelledby="site-footer-heading">
    <div class="relative overflow-hidden bg-slate-950 text-slate-400">
        <div class="pointer-events-none absolute -top-40 left-1/2 h-80 w-[56rem] -translate-x-1/2 rounded-full bg-brand-600/10 blur-3xl" aria-hidden="true"></div>

        <h2 id="site-footer-heading" class="sr-only">Site footer</h2>

        <div class="relative mx-auto max-w-screen-xl px-4 pb-10 pt-16 sm:px-6 lg:px-8 lg:pt-20">
            <div class="grid gap-12 lg:grid-cols-12 lg:gap-10">
                <div class="lg:col-span-4">
                    <x-site.brand
                        :logo-light="filled(data_get($logo, 'url')) ? $logo : null"
                        :name="$companyName"
                        :prefer-dark="true"
                    />

                    @if ($aboutText !== '')
                        <p class="mt-5 max-w-sm whitespace-pre-line text-sm leading-relaxed text-slate-400">{{ $aboutText }}</p>
                    @endif

                    @if ($showSocial)
                        <x-site.social-links class="mt-6" />
                    @endif

                    @if ($badges->isNotEmpty())
                        <ul role="list" class="mt-8 flex flex-wrap items-center gap-3">
                            @foreach ($badges as $badge)
                                <li class="rounded-lg bg-white/5 px-3 py-2 ring-1 ring-inset ring-white/10">
                                    @if ($badge['url'] !== null)
                                        <a
                                            href="{{ $badge['url'] }}"
                                            @if ($badge['new_tab']) target="_blank" rel="noopener noreferrer" @endif
                                            class="block rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
                                        >
                                            <x-site.image :media="$badge['media']" profile="logo" img-class="h-7 w-auto object-contain opacity-80 transition hover:opacity-100" />
                                        </a>
                                    @else
                                        <x-site.image :media="$badge['media']" profile="logo" img-class="h-7 w-auto object-contain opacity-80" />
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div @class([
                    'grid gap-10 sm:grid-cols-2 lg:col-span-8',
                    'lg:grid-cols-3' => $columns->count() + ($hasContact ? 1 : 0) >= 3,
                ])>
                    @foreach ($columns as $column)
                        <div>
                            @if ($column['heading'] !== '')
                                <h3 id="{{ $column['id'] }}" class="text-sm font-semibold uppercase tracking-[0.12em] text-white">{{ $column['heading'] }}</h3>
                            @endif
                            <x-site.menu
                                :menu="$column['menu']"
                                variant="footer"
                                :label="$column['heading'] !== '' ? $column['heading'] : 'Footer'"
                                :id-prefix="$column['id']"
                                @class(['mt-5' => $column['heading'] !== ''])
                            />
                        </div>
                    @endforeach

                    @if ($hasContact)
                        <div @class(['sm:col-span-2 lg:col-span-1' => $columns->count() === 1])>
                            <h3 class="text-sm font-semibold uppercase tracking-[0.12em] text-white">Contact</h3>

                            <address class="mt-5 space-y-4 text-sm not-italic">
                                @if ($address !== '')
                                    <p class="flex gap-3">
                                        <x-ui.icon name="building-office" class="mt-0.5 h-4 w-4 shrink-0 text-brand-400" />
                                        <span class="whitespace-pre-line leading-relaxed">{{ $address }}</span>
                                    </p>
                                @endif

                                @if ($phone !== '')
                                    <p class="flex gap-3">
                                        <x-ui.icon name="phone" class="mt-0.5 h-4 w-4 shrink-0 text-brand-400" />
                                        <a href="{{ $phoneHref }}" class="rounded transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">{{ $phone }}</a>
                                    </p>
                                @endif

                                @if ($whatsappHref !== null)
                                    <p class="flex gap-3">
                                        <x-ui.icon name="whatsapp" class="mt-0.5 h-4 w-4 shrink-0 text-brand-400" />
                                        <a href="{{ $whatsappHref }}" target="_blank" rel="noopener noreferrer" class="rounded transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                                            {{ $whatsapp }}
                                            <span class="sr-only">(WhatsApp, opens in a new tab)</span>
                                        </a>
                                    </p>
                                @endif

                                @if ($email !== '')
                                    <p class="flex gap-3">
                                        <x-ui.icon name="envelope" class="mt-0.5 h-4 w-4 shrink-0 text-brand-400" />
                                        <a href="mailto:{{ $email }}" class="break-all rounded transition hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">{{ $email }}</a>
                                    </p>
                                @endif

                                @if ($hoursRows !== [])
                                    <div class="flex gap-3">
                                        <x-ui.icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-brand-400" />
                                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                                            @foreach ($hoursRows as $row)
                                                <dt class="text-slate-500">{{ $row['days'] }}</dt>
                                                <dd class="tabular-nums text-slate-300">{{ $row['value'] }}</dd>
                                            @endforeach
                                        </dl>
                                    </div>
                                @endif
                            </address>
                        </div>
                    @endif
                </div>
            </div>

            <div class="mt-14 flex flex-col gap-6 border-t border-white/10 pt-8 md:flex-row md:items-center md:justify-between md:pr-16">
                <p class="text-sm text-slate-500">{{ $copyright }}</p>

                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-8">
                    @if (filled(data_get($legal, 'items')))
                        <x-site.menu :menu="$legal" variant="legal" label="Legal" id-prefix="footer-legal" />
                    @endif

                    <x-site.theme-toggle variant="segmented" />
                </div>
            </div>
        </div>
    </div>
</footer>
