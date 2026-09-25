{{--
    The request-a-quote page — site.quote.index renders view `site.quote.index`.

    Controller variables (Site\QuoteController@index, through ComposesContentPages::contentPage()):
      $site        the SitePayload (seo for route_key site.quote.index)
      $page        array{title, slug}
      $form        array{action, enabled, budgetOptions (list<string>), timelineOptions (list<string>),
                   spam {honeypot, token_field, token}}
      $submitted   bool   the `quote_submitted` flash — identical for spam and for a genuine brief
      $contact     array{email, phone, whatsapp} — resolved from settings by the controller (a view never
                   reads a setting); each line is omitted when it is not configured

    It is the contact page's twin, asked differently: the same inbox, the same submission path
    (ContactInquiryService::submit(), typed InquiryType::Service by PublicQuoteRequest), and four
    questions instead of eight — what they want built, a rough budget, a rough timeline, and how to
    reach them. Every extra field costs conversions, and anything else can be asked in the reply.

    This route is not behind `site.cache` on purpose, so the CSRF token and the SpamGuard render token
    are printed inline by site/marketing/partials/form-guard (mode 'inline'). The honeypot comes from
    the same partial. Nothing per-visitor would be safe to cache here.

    Scroll effects: the hero's heading and standfirst rise (page-hero owns those attributes) and the
    aside rises after them. **Nothing on the form** — somebody came here to fill it in, and a field that
    fades in as they reach for it is the effect getting in the way of the conversion it was meant to help.
--}}

@extends('site.layouts.public')

@php
    use Illuminate\Support\Facades\Route;

    $title = (string) data_get($page ?? null, 'title', 'Request a quote');

    $form = is_array($form ?? null) ? $form : [];
    $action = (string) ($form['action'] ?? (Route::has('site.quote.store') ? route('site.quote.store') : ''));
    $isEnabled = (bool) ($form['enabled'] ?? true);
    $budgetList = array_values(array_filter(array_map('strval', (array) ($form['budgetOptions'] ?? []))));
    $timelineList = array_values(array_filter(array_map('strval', (array) ($form['timelineOptions'] ?? []))));

    $spamFields = is_array($form['spam'] ?? null) ? $form['spam'] : [];
    $guard = [
        'mode' => filled($spamFields['token'] ?? null) ? 'inline' : 'fetch',
        'url' => Route::has('site.forms.token') ? route('site.forms.token') : null,
        'honeypot' => $spamFields['honeypot'] ?? 'website_url',
        'token_field' => $spamFields['token_field'] ?? 'form_token',
        'token' => $spamFields['token'] ?? null,
    ];
    // Without either token source the form could only ever fail; the page offers the contact form instead.
    $canProtect = $guard['mode'] === 'inline' || $guard['url'] !== null;

    $done = (bool) ($submitted ?? false);
    $contactPageUrl = Route::has('site.contact.index') ? route('site.contact.index') : null;

    $contact = array_merge(['email' => null, 'phone' => null, 'whatsapp' => null], (array) ($contact ?? []));
    $whatsappDigits = filled($contact['whatsapp']) ? ltrim((string) preg_replace('/[^\d+]/', '', (string) $contact['whatsapp']), '+') : null;

    $field = 'block w-full rounded-xl border-slate-300 bg-white px-3.5 py-2.5 text-base text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25 disabled:opacity-60 dark:border-white/10 dark:bg-slate-900 dark:text-white dark:placeholder:text-slate-500';
    $label = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200';
    $hint = 'mt-1.5 text-sm text-slate-500 dark:text-slate-400';
    $error = 'mt-1.5 text-sm text-rose-600 dark:text-rose-400';
    $optional = '<span class="font-normal text-slate-400 dark:text-slate-500">(optional)</span>';
@endphp

@section('title', $title)

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => $title,
        'subtitle' => 'Tell us what you want built, roughly what it is worth to you and roughly when you need it. A person reads every one of these.',
    ])

    <x-site.section background="surface">
        <div class="lg:grid lg:grid-cols-12 lg:gap-12">
            <div class="lg:col-span-7">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-card sm:p-8 dark:border-white/10 dark:bg-slate-900">
                    @if ($done)
                        <div class="rounded-2xl bg-emerald-50 p-6 text-emerald-900 ring-1 ring-emerald-200 sm:p-8 dark:bg-emerald-500/10 dark:text-emerald-100 dark:ring-emerald-500/25" role="status" tabindex="-1" x-data x-init="$el.focus()">
                            <x-ui.icon name="check-circle" class="h-9 w-9 text-emerald-600 dark:text-emerald-400" />
                            <p class="mt-4 text-xl font-semibold">Thank you — we have your brief.</p>
                            <p class="mt-2 text-sm leading-relaxed">Somebody will read it and come back to you, usually within one working day, with questions or a proposal. You do not need to send it again.</p>
                        </div>
                    @elseif (! $isEnabled)
                        <div class="rounded-2xl bg-slate-100 p-6 text-slate-700 ring-1 ring-slate-200 dark:bg-white/5 dark:text-slate-300 dark:ring-white/10" role="status">
                            <p class="font-semibold text-slate-900 dark:text-white">The enquiry form is closed for the moment.</p>
                            <p class="mt-1 text-sm">Please reach us by email or phone instead — the details are on this page.</p>
                        </div>
                    @elseif (! $canProtect || $action === '')
                        <div class="rounded-2xl bg-slate-50 p-6 text-center ring-1 ring-slate-200 dark:bg-white/[0.03] dark:ring-white/10">
                            <p class="text-slate-700 dark:text-slate-300">Tell us what you need on our contact page.</p>
                            @if ($contactPageUrl)
                                <div class="mt-4 flex justify-center">
                                    <x-site.button label="Open the contact form" :url="$contactPageUrl" style="primary" icon="arrow-right" />
                                </div>
                            @endif
                        </div>
                    @else
                        <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Your project, in your words</h2>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Four questions. Nothing here commits you to anything.</p>

                        <form
                            method="POST"
                            action="{{ $action }}"
                            class="relative mt-6"
                            x-data="siteFormGuard(@js(['url' => $guard['mode'] === 'fetch' ? $guard['url'] : null]))"
                            x-on:focusin.once="load()"
                            x-on:submit="guard($event)"
                        >
                            <div
                                class="space-y-5"
                                x-data
                                x-init="
                                    $refs.pageUrl.value = window.location.href.split('#')[0].slice(0, 255);
                                    $refs.referrer.value = (document.referrer || '').slice(0, 255);
                                "
                            >
                                {{-- _token, the signed render token and the honeypot — inline, because this page is never cached. --}}
                                @include('site.marketing.partials.form-guard', ['guard' => $guard])
                                <input type="hidden" name="page_url" x-ref="pageUrl" value="">
                                <input type="hidden" name="referrer_url" x-ref="referrer" value="">

                                @if ($errors->any())
                                    <div class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/25" role="alert" tabindex="-1" x-data x-init="$el.focus()">
                                        <p class="font-semibold">Your request was not sent yet.</p>
                                        <p class="mt-0.5">Please check the highlighted fields.</p>
                                    </div>
                                @endif

                                <div>
                                    <label for="quote-project" class="{{ $label }}">What would you like us to build? <span class="text-rose-500">*</span></label>
                                    <textarea
                                        id="quote-project"
                                        name="project"
                                        rows="6"
                                        required
                                        minlength="20"
                                        maxlength="5000"
                                        aria-describedby="quote-project-hint"
                                        placeholder="A booking site for two clinics, an Android app for our delivery riders, a system to replace the spreadsheets we run the office on…"
                                        class="{{ $field }}"
                                        @error('project') aria-invalid="true" @enderror
                                    >{{ old('project') }}</textarea>
                                    <p id="quote-project-hint" class="{{ $hint }}">A paragraph is plenty. What it should do, who it is for, and anything that already exists.</p>
                                    @error('project')<p class="{{ $error }}">{{ $message }}</p>@enderror
                                </div>

                                <div class="grid gap-5 sm:grid-cols-2">
                                    @if ($budgetList !== [])
                                        <div>
                                            <label for="quote-budget" class="{{ $label }}">Rough budget {!! $optional !!}</label>
                                            <select id="quote-budget" name="budget" class="{{ $field }} pr-10" @error('budget') aria-invalid="true" @enderror>
                                                <option value="">Prefer not to say</option>
                                                @foreach ($budgetList as $budgetOption)
                                                    <option value="{{ $budgetOption }}" @selected(old('budget') === $budgetOption)>{{ $budgetOption }}</option>
                                                @endforeach
                                            </select>
                                            @error('budget')<p class="{{ $error }}">{{ $message }}</p>@enderror
                                        </div>
                                    @endif

                                    @if ($timelineList !== [])
                                        <div>
                                            <label for="quote-timeline" class="{{ $label }}">When do you need it? {!! $optional !!}</label>
                                            <select id="quote-timeline" name="timeline" class="{{ $field }} pr-10" @error('timeline') aria-invalid="true" @enderror>
                                                <option value="">Prefer not to say</option>
                                                @foreach ($timelineList as $timelineOption)
                                                    <option value="{{ $timelineOption }}" @selected(old('timeline') === $timelineOption)>{{ $timelineOption }}</option>
                                                @endforeach
                                            </select>
                                            @error('timeline')<p class="{{ $error }}">{{ $message }}</p>@enderror
                                        </div>
                                    @endif

                                    <div>
                                        <label for="quote-name" class="{{ $label }}">Your name <span class="text-rose-500">*</span></label>
                                        <input id="quote-name" type="text" name="name" value="{{ old('name') }}" required maxlength="150" autocomplete="name" class="{{ $field }}" @error('name') aria-invalid="true" @enderror>
                                        @error('name')<p class="{{ $error }}">{{ $message }}</p>@enderror
                                    </div>

                                    <div>
                                        <label for="quote-email" class="{{ $label }}">Email <span class="text-rose-500">*</span></label>
                                        <input id="quote-email" type="email" name="email" value="{{ old('email') }}" required maxlength="150" autocomplete="email" class="{{ $field }}" @error('email') aria-invalid="true" @enderror>
                                        @error('email')<p class="{{ $error }}">{{ $message }}</p>@enderror
                                    </div>

                                    <div>
                                        <label for="quote-phone" class="{{ $label }}">Phone {!! $optional !!}</label>
                                        <input id="quote-phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="32" autocomplete="tel" class="{{ $field }}" @error('phone') aria-invalid="true" @enderror>
                                        @error('phone')<p class="{{ $error }}">{{ $message }}</p>@enderror
                                    </div>
                                </div>

                                <p class="text-sm text-slate-500 dark:text-slate-400">We use your details only to answer this request.</p>

                                <button type="submit" class="{{ \App\Enums\Cms\ButtonStyle::Primary->classes() }} w-full sm:w-auto">
                                    <span>Send my brief</span>
                                    <x-ui.icon name="arrow-right" class="h-4 w-4" />
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>

            <aside class="mt-12 lg:col-span-5 lg:mt-0" aria-labelledby="quote-next" data-fx="rise" data-fx-delay="2">
                <div class="space-y-8 lg:sticky lg:top-28">
                    <div>
                        <h2 id="quote-next" class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">What happens next</h2>
                        <ol role="list" class="mt-5 space-y-5">
                            <li class="flex items-start gap-3">
                                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">1</span>
                                <div>
                                    <p class="font-semibold text-slate-900 dark:text-white">A person reads it</p>
                                    <p class="mt-1 text-sm leading-relaxed text-slate-600 dark:text-slate-300">Not a bot and not an auto-responder. It lands in the same inbox as everything else you could send us.</p>
                                </div>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">2</span>
                                <div>
                                    <p class="font-semibold text-slate-900 dark:text-white">We reply within a working day</p>
                                    <p class="mt-1 text-sm leading-relaxed text-slate-600 dark:text-slate-300">Usually with two or three questions, because the answers change the number more than anything else would.</p>
                                </div>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">3</span>
                                <div>
                                    <p class="font-semibold text-slate-900 dark:text-white">You get a written proposal</p>
                                    <p class="mt-1 text-sm leading-relaxed text-slate-600 dark:text-slate-300">Scope, a price and a timeline you can hold us to. No obligation, and nothing to pay for the estimate.</p>
                                </div>
                            </li>
                        </ol>
                    </div>

                    @if (filled($contact['email']) || filled($contact['phone']) || filled($whatsappDigits))
                        <div class="rounded-2xl bg-slate-50 p-6 ring-1 ring-slate-200 dark:bg-white/[0.03] dark:ring-white/10">
                            <h3 class="text-base font-semibold text-slate-900 dark:text-white">Would rather talk it through?</h3>
                            <ul role="list" class="mt-4 space-y-3">
                                @if (filled($contact['email']))
                                    <li class="flex items-start gap-3">
                                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300"><x-ui.icon name="envelope" class="h-4 w-4" /></span>
                                        <div>
                                            <p class="text-sm text-slate-500 dark:text-slate-400">Email</p>
                                            <a href="mailto:{{ $contact['email'] }}" class="break-all font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $contact['email'] }}</a>
                                        </div>
                                    </li>
                                @endif
                                @if (filled($contact['phone']))
                                    <li class="flex items-start gap-3">
                                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300"><x-ui.icon name="phone" class="h-4 w-4" /></span>
                                        <div>
                                            <p class="text-sm text-slate-500 dark:text-slate-400">Phone</p>
                                            <a href="tel:{{ preg_replace('/[^\d+]/', '', (string) $contact['phone']) }}" class="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ $contact['phone'] }}</a>
                                        </div>
                                    </li>
                                @endif
                                @if (filled($whatsappDigits))
                                    <li class="flex items-start gap-3">
                                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><x-ui.icon name="whatsapp" class="h-4 w-4" /></span>
                                        <div>
                                            <p class="text-sm text-slate-500 dark:text-slate-400">WhatsApp</p>
                                            <a href="https://wa.me/{{ $whatsappDigits }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-slate-900 hover:text-emerald-700 dark:text-white dark:hover:text-emerald-300">{{ $contact['whatsapp'] }}</a>
                                        </div>
                                    </li>
                                @endif
                            </ul>
                        </div>
                    @endif
                </div>
            </aside>
        </div>
    </x-site.section>
@endsection
