{{--
    The contact page — site.contact.index renders view `site.contact` (phase-04 §7.1, §8.11 site/contact; requirement §17).
    Phase 3 declares no /contact route, so Phase 4 owns this page; it embeds the same <x-site.contact-form> the `contact`
    section drops onto any CMS page.

    Controller variables (Site\ContactController@index, through ComposesContentPages::contentPage()):
      $site        the SitePayload (seo for route_key site.contact.index)
      $page        array{title, slug}
      $form        array{action, enabled, types (value => label), selectedType, services (Collection<Service>),
                   selectedService, courseField ('text'), budgetOptions (list<string>), spam {honeypot, token_field, token}}
      $submitted   bool   the `contact_submitted` flash — identical for spam and non-spam
      $contact     optional array{email, phone, whatsapp, address} — read from settings by the controller when it wants the
                   aside (a view never reads a setting); the aside is omitted without it

    This route is not behind `site.cache` (C.4), so the form's CSRF and SpamGuard tokens are printed inline.
--}}

@extends('site.layouts.public')

@php
    $contact = array_merge(['email' => null, 'phone' => null, 'whatsapp' => null, 'address' => null], (array) ($contact ?? []));
    $email = filter_var((string) $contact['email'], FILTER_VALIDATE_EMAIL) !== false ? (string) $contact['email'] : null;
    $phone = filled($contact['phone']) ? (string) $contact['phone'] : null;
    $whatsappDigits = filled($contact['whatsapp']) ? ltrim((string) preg_replace('/[^\d+]/', '', (string) $contact['whatsapp']), '+') : null;
    $hasAside = $email || $phone || $whatsappDigits || filled($contact['address']);
    $title = (string) data_get($page ?? null, 'title', 'Contact us');
@endphp

@section('title', $title)

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => $title,
        'subtitle' => 'Tell us about your project, a course you are interested in, or anything else. A real person replies.',
    ])

    <x-site.section background="surface">
        <div @class(['lg:grid lg:grid-cols-12 lg:gap-12' => $hasAside])>
            <div @class(['lg:col-span-7' => $hasAside, 'mx-auto max-w-3xl' => ! $hasAside])>
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-card sm:p-8 dark:border-white/10 dark:bg-slate-900">
                    <x-site.contact-form :form="$form ?? []" :submitted="$submitted ?? null" id-prefix="contact-page" />
                </div>
            </div>

            @if ($hasAside)
                <aside class="mt-12 lg:col-span-5 lg:mt-0" aria-labelledby="contact-direct">
                    <div class="space-y-6 lg:sticky lg:top-28">
                        <h2 id="contact-direct" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Prefer to reach us directly?</h2>
                        <ul role="list" class="space-y-4">
                            @if ($email)
                                <li class="flex items-start gap-3">
                                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300"><x-ui.icon name="envelope" class="h-5 w-5" /></span>
                                    <div><p class="text-sm text-slate-500 dark:text-slate-400">Email</p><a href="mailto:{{ $email }}" class="break-all font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $email }}</a></div>
                                </li>
                            @endif
                            @if ($phone)
                                <li class="flex items-start gap-3">
                                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300"><x-ui.icon name="phone" class="h-5 w-5" /></span>
                                    <div><p class="text-sm text-slate-500 dark:text-slate-400">Phone</p><a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}" class="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $phone }}</a></div>
                                </li>
                            @endif
                            @if ($whatsappDigits)
                                <li class="flex items-start gap-3">
                                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><x-ui.icon name="whatsapp" class="h-5 w-5" /></span>
                                    <div><p class="text-sm text-slate-500 dark:text-slate-400">WhatsApp</p><a href="https://wa.me/{{ $whatsappDigits }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-slate-900 hover:text-emerald-700 dark:text-white dark:hover:text-emerald-300">{{ $contact['whatsapp'] }}</a></div>
                                </li>
                            @endif
                            @if (filled($contact['address']))
                                <li class="flex items-start gap-3">
                                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-300"><x-ui.icon name="building-office" class="h-5 w-5" /></span>
                                    <div><p class="text-sm text-slate-500 dark:text-slate-400">Office</p><p class="whitespace-pre-line font-semibold text-slate-900 dark:text-white">{{ $contact['address'] }}</p></div>
                                </li>
                            @endif
                        </ul>
                    </div>
                </aside>
            @endif
        </div>
    </x-site.section>
@endsection
