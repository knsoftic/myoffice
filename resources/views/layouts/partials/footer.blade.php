{{--
    Shell footer. Company name and support details come from settings, never from config.

    Settings read: company.name, company.website, and the contact details from `contact.*` —
    phase-02 §2 moved email and phone out of the `company` group into `contact`, which is where
    the Contact settings screen writes them. The Phase 1 `company.support_email` /
    `company.email` / `company.phone` rows are superseded (see
    `2026_09_12_060400_supersede_relocated_setting_keys`) and must not be read here: while they
    were, an administrator changing the support address on the Contact screen saw the footer keep
    the old one.
--}}

@php
    $footerCompany = setting('company.name', config('app.name', 'My Office'));
    $footerEmail = setting('contact.support_email') ?: setting('contact.email');
    $footerPhone = setting('contact.phone');
    $footerSite = setting('company.website');
@endphp

<footer class="mt-auto border-t border-slate-200 bg-white/60 dark:border-slate-800 dark:bg-slate-900/40">
    <div class="mx-auto flex max-w-screen-2xl flex-col gap-2 px-4 py-4 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8 dark:text-slate-400">
        <p>
            &copy; {{ now()->year }}
            <span class="font-medium text-slate-700 dark:text-slate-300">{{ $footerCompany }}</span>.
            All rights reserved.
        </p>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
            @if (filled($footerEmail))
                <a href="mailto:{{ $footerEmail }}" class="inline-flex items-center gap-1.5 transition-colors hover:text-slate-900 dark:hover:text-white">
                    <x-ui.icon name="mail" class="h-3.5 w-3.5" />
                    {{ $footerEmail }}
                </a>
            @endif

            @if (filled($footerPhone))
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $footerPhone) }}" class="inline-flex items-center gap-1.5 transition-colors hover:text-slate-900 dark:hover:text-white">
                    <x-ui.icon name="phone" class="h-3.5 w-3.5" />
                    {{ $footerPhone }}
                </a>
            @endif

            @if (filled($footerSite))
                <a href="{{ $footerSite }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 transition-colors hover:text-slate-900 dark:hover:text-white">
                    <x-ui.icon name="globe" class="h-3.5 w-3.5" />
                    Website
                </a>
            @endif
        </div>
    </div>
</footer>
