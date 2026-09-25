{{--
    Shared body of the catalogue teaser sections — `services` (Phase 4) and `courses` (Phase 14).
    Included by site/sections/services.blade.php and site/sections/courses.blade.php only.

    Receives:
      $section    the published snapshot (anchor, provider)
      $content    heading, description (optional), view_all_link (optional link)
      $typeKey    'services' | 'courses'
      $emptyIcon  the icon of the empty state
      $emptyText  one honest sentence for the empty state

    Cards come from the owning phase's SectionDataProvider, folded into the snapshot as `provider`:
    either a list of cards or `['items' => [...]]`, each card
    `['title', 'excerpt', 'url', 'media' (card-profile media array), 'icon', 'meta' (short text)]`.

    Until that data exists the section says so plainly and offers a real way to ask — the contact
    email and phone from settings — instead of inventing services or courses the company does not
    offer yet.
--}}

@php
    use App\Support\Cms\SectionRegistry;

    $fields = (array) ($content ?? []);
    $provider = data_get($section ?? null, 'provider');
    $cards = collect(is_array(data_get($provider, 'items')) ? data_get($provider, 'items') : (is_array($provider) && array_is_list($provider) ? $provider : []))
        ->filter(static fn ($card): bool => filled(data_get($card, 'title')))
        ->take(12)
        ->values();

    $heading = trim((string) (data_get($fields, 'heading') ?: (SectionRegistry::exists($typeKey) ? SectionRegistry::label($typeKey) : '')));
    $description = trim((string) data_get($fields, 'description', ''));
    $viewAll = data_get($fields, 'view_all_link');

    $safeUrl = static fn (mixed $url): ?string => is_string($url) && preg_match('/^(https?:\/\/|\/|#)/i', trim($url)) === 1 ? trim($url) : null;

    $email = trim((string) site_setting('contact.email', ''));
    $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    $phone = trim((string) site_setting('contact.phone', ''));
    $phoneHref = $phone !== '' ? 'tel:'.preg_replace('/[^\d+]/', '', $phone) : null;

    $lazy = filter_var(site_setting('website.image_lazy_loading', true), FILTER_VALIDATE_BOOLEAN);
@endphp

<x-site.section :anchor="data_get($section ?? null, 'anchor')" background="muted" :label="$heading !== '' ? $heading : null">
    <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
        <x-site.heading data-fx="rise" :title="$heading" :subtitle="$description !== '' ? $description : null" align="left" />

        @if ($cards->isNotEmpty() && is_array($viewAll) && filled($viewAll['label'] ?? null) && filled($viewAll['url'] ?? null))
            <x-site.button data-fx="rise" data-fx-delay="1" :link="$viewAll" icon="arrow-right" class="shrink-0" />
        @endif
    </div>

    @if ($cards->isNotEmpty())
        <ul role="list" class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($cards as $card)
                @php
                    $cardUrl = $safeUrl(data_get($card, 'url'));
                    $cardMedia = data_get($card, 'media');
                @endphp
                <li data-fx="tilt" data-fx-delay="{{ ($loop->index % 4) + 1 }}" class="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-card-hover focus-within:ring-2 focus-within:ring-brand-500/50 dark:border-white/10 dark:bg-slate-900">
                    @if (filled(data_get($cardMedia, 'url')))
                        <div class="aspect-video overflow-hidden bg-slate-100 dark:bg-slate-800">
                            <x-site.image :media="$cardMedia" profile="card" :lazy="$lazy" class="h-full w-full transition duration-300 group-hover:scale-[1.02]" />
                        </div>
                    @endif

                    <div class="flex flex-1 flex-col p-6">
                        @if (! filled(data_get($cardMedia, 'url')) && filled(data_get($card, 'icon')))
                            <span class="mb-5 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20">
                                <x-ui.icon :name="data_get($card, 'icon')" class="h-5 w-5" />
                            </span>
                        @endif

                        @if (filled(data_get($card, 'meta')))
                            <p class="text-xs font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-400">{{ data_get($card, 'meta') }}</p>
                        @endif

                        <h3 class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">
                            @if ($cardUrl !== null)
                                <a href="{{ $cardUrl }}" class="focus-visible:outline-none">
                                    <span class="absolute inset-0" aria-hidden="true"></span>
                                    {{ data_get($card, 'title') }}
                                </a>
                            @else
                                {{ data_get($card, 'title') }}
                            @endif
                        </h3>

                        @if (filled(data_get($card, 'excerpt')))
                            <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{{ data_get($card, 'excerpt') }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <div class="mt-12 rounded-2xl border border-dashed border-slate-300 bg-white/60 px-6 py-14 text-center dark:border-white/15 dark:bg-white/[0.02]">
            <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20" aria-hidden="true">
                <x-ui.icon :name="$emptyIcon" class="h-6 w-6" />
            </span>
            <p class="mx-auto mt-5 max-w-md text-base text-slate-700 dark:text-slate-300">{{ $emptyText }}</p>

            @if ($email !== null || $phoneHref !== null)
                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    @if ($email !== null)
                        <x-site.button :label="$email" :url="'mailto:'.$email" style="primary" icon="envelope" />
                    @endif
                    @if ($phoneHref !== null)
                        <x-site.button :label="$phone" :url="$phoneHref" style="outline" icon="phone" />
                    @endif
                </div>
            @endif
        </div>
    @endif
</x-site.section>
