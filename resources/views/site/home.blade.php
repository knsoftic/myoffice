{{--
    The public home page (route `site.home`, phase-03 §7.6, §8.14) — entirely section-driven.

    Receives:
      $site            App\Support\SitePayload: `sections` = the published, enabled `home` sections in sort
                       order (one indexed read, §6.9), plus header, footer, seo and isPreview for the layout.
      $previewSection  optional (Site\PreviewController@section): the id of the one section previewed
      $previewOmitted  optional bool: that section's draft could not be rendered (missing content)

    Nothing on this page is hard-wired: the editor enables, disables and reorders every block from
    /admin/website. The hero owns the page's one <h1>; when the hero is switched off, a visually hidden
    <h1> with the company name keeps the document outline valid.

    With no published section at all (a brand-new install before the seeder, or every section
    switched off) the page does not render blank: it shows the company name, the tagline and the real
    contact details from settings — honest, and still useful to a visitor.
--}}

@extends('site.layouts.public')

@section('content')
    @php
        $homeSections = collect(data_get($site ?? null, 'sections', []))->values();

        $hasHero = $homeSections->contains(static fn ($row): bool => (data_get($row, 'section_key') ?? data_get($row, 'published_content.section_key')) === 'hero');

        $companyName = trim((string) site_setting('company.name', ''));
        $tagline = trim((string) site_setting('company.tagline', ''));
        $email = trim((string) site_setting('contact.email', ''));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
        $phone = trim((string) site_setting('contact.phone', ''));
        $phoneHref = $phone !== '' ? 'tel:'.preg_replace('/[^\d+]/', '', $phone) : null;
    @endphp

    @if (isset($previewSection) && ($previewOmitted ?? false))
        <x-site.section background="muted" padding="loose">
            <div class="mx-auto max-w-xl text-center">
                <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-600 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30" aria-hidden="true">
                    <x-ui.icon name="exclamation-triangle" class="h-6 w-6" />
                </span>
                <h1 class="mt-6 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">This draft cannot be previewed yet</h1>
                <p class="mt-3 text-base text-slate-600 dark:text-slate-400">Section #{{ $previewSection }} is missing content it needs to render. Fill in its required fields in the editor, then refresh this preview.</p>
            </div>
        </x-site.section>
    @elseif (isset($previewSection) && $homeSections->isEmpty())
        <h1 class="sr-only">{{ $companyName }}</h1>
        <x-site.section background="muted" padding="loose">
            <p class="mx-auto max-w-xl text-center text-sm text-slate-500 dark:text-slate-400">Previewing the site header and footer. Page sections are not shown here.</p>
        </x-site.section>
    @elseif ($homeSections->isEmpty())
        <x-site.section background="brand" padding="loose" :label="$companyName !== '' ? $companyName : null">
            <div class="mx-auto max-w-3xl text-center">
                <span class="mx-auto inline-flex items-center gap-2 rounded-full bg-white/80 px-3 py-1 text-xs font-semibold text-brand-700 ring-1 ring-inset ring-brand-200 dark:bg-white/5 dark:text-brand-300 dark:ring-brand-500/30">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-500" aria-hidden="true"></span>
                    Our website is being updated
                </span>

                <h1 class="mt-6 text-balance text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl dark:text-white">{{ $companyName }}</h1>

                @if ($tagline !== '')
                    <p class="mx-auto mt-6 max-w-2xl text-pretty text-lg text-slate-600 sm:text-xl dark:text-slate-300">{{ $tagline }}</p>
                @endif

                @if ($email !== null || $phoneHref !== null)
                    <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        @if ($email !== null)
                            <x-site.button :label="$email" :url="'mailto:'.$email" style="primary" icon="envelope" size="lg" />
                        @endif
                        @if ($phoneHref !== null)
                            <x-site.button :label="$phone" :url="$phoneHref" style="outline" icon="phone" size="lg" />
                        @endif
                    </div>
                @endif
            </div>
        </x-site.section>
    @else
        @unless ($hasHero)
            <h1 class="sr-only">{{ $companyName }}</h1>
        @endunless

        @include('site.partials.sections', ['sections' => $homeSections])
    @endif
@endsection
