@props([
    'form' => null,
    'action' => null,
    'enabled' => true,
    'types' => null,
    'selectedType' => null,
    'services' => [],
    'selectedService' => null,
    'courses' => null,
    'budgetOptions' => [],
    'spam' => null,
    'tokenUrl' => null,
    'submitted' => null,
    'heading' => null,
    'submitLabel' => 'Send message',
    'consent' => null,
    'idPrefix' => 'contact',
])

{{--
    <x-site.contact-form> — the one public inquiry form, droppable on any CMS page (phase-04 §8.13, §8.11 site/contact,
    §6.9, §6.10.5; requirement §17).

    On the contact page, from Site\ContactController@index's `form` array:
        <x-site.contact-form :form="$form" :submitted="$submitted" />
          form = {action, enabled, types (value => label), selectedType, services (Collection<Service> | id => name |
                  list<{id, name}>), selectedService, courseField ('text' | 'select'), budgetOptions (list<string>),
                  spam {honeypot, token_field, token}}

    In the `contact` section, from ContactSectionProvider (a page that may be cached):
        <x-site.contact-form :action="$provider['action_url']" :enabled="$provider['form_enabled']"
                             :types="$provider['inquiry_types']" :services="$provider['services']"
                             :budget-options="$provider['budget_options']" />

    Posts to site.contact.store (PublicContactRequest): inquiry_type, name, email, phone, whatsapp, company, service_id
    (service type), course_name (course type), budget (one of the configured labels, stored verbatim), subject, message
    (min 15, max 5000), the honeypot, the signed render token, _token, page_url, referrer_url. A collaborator id is never
    posted — the ?ref= code travels in the cookie/session Phase 3 preserved (§2.20 rule 3).

    Tokens (site/marketing/partials/form-guard): `spam.token` present means the controller rendered this page per request →
    printed inline; otherwise they are fetched from route site.forms.token after render. With neither available the
    component does not render a form that could only fail — it links to the contact page instead.

    The type-dependent fields are shown and hidden by Alpine and disabled while hidden, so they never post. After the POST
    the controller redirects back with `contact_submitted` flashed — the SAME confirmation for spam and for a real
    inquiry, so a bot learns nothing. Nothing in this component reads a setting: every option list is handed in.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $form = is_array($form) ? $form : [];

    $action = (string) ($form['action'] ?? $action ?? (Route::has('site.contact.store') ? route('site.contact.store') : ''));
    $isEnabled = (bool) ($form['enabled'] ?? $enabled ?? true);

    // Types: a value => label map, or a list of {value, label}.
    $typeSource = $form['types'] ?? $types ?? ['service' => 'A project or service', 'course' => 'A course', 'general' => 'Something else'];
    $typeList = [];
    foreach ((array) $typeSource as $key => $typeEntry) {
        if (is_array($typeEntry) && isset($typeEntry['value'])) {
            $typeList[(string) $typeEntry['value']] = (string) ($typeEntry['label'] ?? $typeEntry['value']);
        } elseif (is_string($typeEntry) && is_string($key)) {
            $typeList[$key] = $typeEntry;
        }
    }

    if ($typeList === []) {
        $typeList = ['service' => 'A project or service', 'course' => 'A course', 'general' => 'Something else'];
    }

    // Services: Collection<Model>, id => name, or list<{id, name}>.
    $serviceSource = $form['services'] ?? $services ?? [];
    $serviceList = [];
    foreach (collect($serviceSource) as $key => $serviceEntry) {
        if ($serviceEntry instanceof \Illuminate\Database\Eloquent\Model) {
            $serviceList[(int) $serviceEntry->getKey()] = (string) $serviceEntry->getAttribute('name');
        } elseif (is_array($serviceEntry) && isset($serviceEntry['id'])) {
            $serviceList[(int) $serviceEntry['id']] = (string) ($serviceEntry['name'] ?? '');
        } elseif (is_scalar($serviceEntry) && is_numeric($key)) {
            $serviceList[(int) $key] = (string) $serviceEntry;
        }
    }
    $serviceList = array_filter($serviceList, static fn (string $name): bool => $name !== '');

    if ($serviceList === []) {
        unset($typeList['service']);
    }

    $budgetList = array_values(array_filter(array_map('strval', (array) ($form['budgetOptions'] ?? $budgetOptions ?? []))));
    $courseList = is_iterable($courses) ? array_values(array_filter(array_map('strval', collect($courses)->all()))) : null;

    $initialType = old('inquiry_type', $form['selectedType'] ?? $selectedType);
    $initialType = is_string($initialType) && array_key_exists($initialType, $typeList) ? $initialType : (array_key_exists('general', $typeList) ? 'general' : (string) array_key_first($typeList));
    $initialService = old('service_id', $form['selectedService'] ?? $selectedService);
    $initialService = is_numeric($initialService) && array_key_exists((int) $initialService, $serviceList) ? (int) $initialService : null;
    $serverPreselected = filled($form['selectedType'] ?? $selectedType) || $initialService !== null;

    $spamFields = is_array($form['spam'] ?? null) ? $form['spam'] : (is_array($spam) ? $spam : []);
    $fetchUrl = $tokenUrl ?? (Route::has('site.forms.token') ? route('site.forms.token') : null);
    $guard = [
        'mode' => filled($spamFields['token'] ?? null) ? 'inline' : 'fetch',
        'url' => $fetchUrl,
        'honeypot' => $spamFields['honeypot'] ?? 'website_url',
        'token_field' => $spamFields['token_field'] ?? 'form_token',
        'token' => $spamFields['token'] ?? null,
    ];
    $canProtect = $guard['mode'] === 'inline' || $guard['url'] !== null;

    $done = (bool) ($submitted ?? (session('contact_submitted') || session('contact_inquiry_submitted')));
    $p = preg_replace('/[^a-z0-9-]/i', '', (string) $idPrefix) ?: 'contact';
    $contactPageUrl = Route::has('site.contact.index') ? route('site.contact.index') : null;

    $field = 'block w-full rounded-xl border-slate-300 bg-white px-3.5 py-2.5 text-base text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25 disabled:opacity-60 dark:border-white/10 dark:bg-slate-900 dark:text-white dark:placeholder:text-slate-500';
    $label = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200';
    $error = 'mt-1.5 text-sm text-rose-600 dark:text-rose-400';
@endphp

<div {{ $attributes->class('w-full') }}>
    @if ($done)
        <div class="rounded-2xl bg-emerald-50 p-6 text-emerald-900 ring-1 ring-emerald-200 sm:p-8 dark:bg-emerald-500/10 dark:text-emerald-100 dark:ring-emerald-500/25" role="status" tabindex="-1" x-data x-init="$el.focus()">
            <x-ui.icon name="check-circle" class="h-9 w-9 text-emerald-600 dark:text-emerald-400" />
            <p class="mt-4 text-xl font-semibold">Thank you — we have your message.</p>
            <p class="mt-2 text-sm leading-relaxed">A member of our team will get back to you, usually within one working day. You do not need to send it again.</p>
        </div>
    @elseif (! $isEnabled)
        <div class="rounded-2xl bg-slate-100 p-6 text-slate-700 ring-1 ring-slate-200 dark:bg-white/5 dark:text-slate-300 dark:ring-white/10" role="status">
            <p class="font-semibold text-slate-900 dark:text-white">The contact form is closed for the moment.</p>
            <p class="mt-1 text-sm">Please reach us by email or phone instead.</p>
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
        @if (filled($heading))
            <h2 class="mb-6 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ $heading }}</h2>
        @endif

        <form
            method="POST"
            action="{{ $action }}"
            class="relative"
            x-data="siteFormGuard(@js(['url' => $guard['mode'] === 'fetch' ? $guard['url'] : null]))"
            x-on:focusin.once="load()"
            x-on:submit="guard($event)"
        >
            <div
                class="space-y-5"
                x-data="{ type: @js($initialType), service: @js($initialService !== null ? (string) $initialService : '') }"
                x-init="
                    const params = new URLSearchParams(window.location.search);
                    if (! @js($errors->any() || $serverPreselected)) {
                        const wanted = params.get('type');
                        if (wanted && @js(array_keys($typeList)).includes(wanted)) { type = wanted; }
                        const wantedService = params.get('service');
                        if (wantedService && @js(array_map('strval', array_keys($serviceList))).includes(wantedService)) { service = wantedService; type = 'service'; }
                    }
                    if (params.get('subject') && $refs.subject && ! $refs.subject.value) { $refs.subject.value = params.get('subject').slice(0, 200); }
                    $refs.pageUrl.value = window.location.href.split('#')[0].slice(0, 255);
                    $refs.referrer.value = (document.referrer || '').slice(0, 255);
                "
            >
                @include('site.marketing.partials.form-guard', ['guard' => $guard])
                <input type="hidden" name="page_url" x-ref="pageUrl" value="">
                <input type="hidden" name="referrer_url" x-ref="referrer" value="">

                @if ($errors->any())
                    <div class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/25" role="alert" tabindex="-1" x-init="$el.focus()">
                        <p class="font-semibold">Your message was not sent yet.</p>
                        <p class="mt-0.5">Please check the highlighted fields.</p>
                    </div>
                @endif

                @if (count($typeList) > 1)
                    <fieldset>
                        <legend class="{{ $label }}">What is it about? <span class="text-rose-500">*</span></legend>
                        <div @class(['grid gap-2', 'sm:grid-cols-3' => count($typeList) >= 3, 'sm:grid-cols-2' => count($typeList) === 2])>
                            @foreach ($typeList as $typeValue => $typeLabel)
                                <label class="flex cursor-pointer items-center gap-2.5 rounded-xl px-3.5 py-3 text-sm font-medium ring-1 ring-inset transition focus-within:ring-2 focus-within:ring-brand-500/60" x-bind:class="type === @js($typeValue) ? 'bg-brand-50 text-brand-800 ring-brand-300 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/40' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-200 dark:ring-white/10 dark:hover:bg-slate-800'">
                                    <input type="radio" name="inquiry_type" value="{{ $typeValue }}" x-model="type" @checked($initialType === $typeValue) class="h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800">
                                    {{ $typeLabel }}
                                </label>
                            @endforeach
                        </div>
                        @error('inquiry_type')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </fieldset>
                @else
                    <input type="hidden" name="inquiry_type" value="{{ $initialType }}">
                @endif

                @if ($serviceList !== [])
                    <div x-show="type === 'service'" x-cloak @if ($initialType !== 'service') style="display: none" @endif>
                        <label for="{{ $p }}-service" class="{{ $label }}">Which service? <span class="text-rose-500">*</span></label>
                        <select id="{{ $p }}-service" name="service_id" x-model="service" x-bind:disabled="type !== 'service'" x-bind:required="type === 'service'" @disabled($initialType !== 'service') class="{{ $field }} pr-10" @error('service_id') aria-invalid="true" @enderror>
                            <option value="">Choose a service…</option>
                            @foreach ($serviceList as $serviceId => $serviceName)
                                <option value="{{ $serviceId }}" @selected($initialService === $serviceId)>{{ $serviceName }}</option>
                            @endforeach
                        </select>
                        @error('service_id')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                @endif

                @if (array_key_exists('course', $typeList))
                    <div x-show="type === 'course'" x-cloak @if ($initialType !== 'course') style="display: none" @endif>
                        <label for="{{ $p }}-course" class="{{ $label }}">Which course? <span class="text-rose-500">*</span></label>
                        @if ($courseList !== null && $courseList !== [] && ($form['courseField'] ?? 'select') !== 'text')
                            <select id="{{ $p }}-course" name="course_name" x-bind:disabled="type !== 'course'" x-bind:required="type === 'course'" @disabled($initialType !== 'course') class="{{ $field }} pr-10" @error('course_name') aria-invalid="true" @enderror>
                                <option value="">Choose a course…</option>
                                @foreach ($courseList as $courseName)
                                    <option value="{{ $courseName }}" @selected(old('course_name') === $courseName)>{{ $courseName }}</option>
                                @endforeach
                            </select>
                        @else
                            <input id="{{ $p }}-course" type="text" name="course_name" value="{{ old('course_name') }}" maxlength="150" placeholder="e.g. Full-stack web development" x-bind:disabled="type !== 'course'" x-bind:required="type === 'course'" @disabled($initialType !== 'course') class="{{ $field }}" @error('course_name') aria-invalid="true" @enderror>
                        @endif
                        @error('course_name')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                @endif

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="{{ $p }}-name" class="{{ $label }}">Your name <span class="text-rose-500">*</span></label>
                        <input id="{{ $p }}-name" type="text" name="name" value="{{ old('name') }}" required maxlength="150" autocomplete="name" class="{{ $field }}" @error('name') aria-invalid="true" @enderror>
                        @error('name')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="{{ $p }}-email" class="{{ $label }}">Email <span class="text-rose-500">*</span></label>
                        <input id="{{ $p }}-email" type="email" name="email" value="{{ old('email') }}" required maxlength="150" autocomplete="email" class="{{ $field }}" @error('email') aria-invalid="true" @enderror>
                        @error('email')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="{{ $p }}-phone" class="{{ $label }}">Phone <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="{{ $p }}-phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="32" autocomplete="tel" class="{{ $field }}">
                        @error('phone')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="{{ $p }}-whatsapp" class="{{ $label }}">WhatsApp <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="{{ $p }}-whatsapp" type="tel" name="whatsapp" value="{{ old('whatsapp') }}" maxlength="32" class="{{ $field }}">
                        @error('whatsapp')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                    <div x-show="type !== 'course'">
                        <label for="{{ $p }}-company" class="{{ $label }}">Company <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="{{ $p }}-company" type="text" name="company" value="{{ old('company') }}" maxlength="150" autocomplete="organization" class="{{ $field }}">
                        @error('company')<p class="{{ $error }}">{{ $message }}</p>@enderror
                    </div>
                    @if ($budgetList !== [] && array_key_exists('service', $typeList))
                        <div x-show="type === 'service'" @if ($initialType !== 'service') style="display: none" @endif>
                            <label for="{{ $p }}-budget" class="{{ $label }}">Budget <span class="font-normal text-slate-400">(optional)</span></label>
                            <select id="{{ $p }}-budget" name="budget" x-bind:disabled="type !== 'service'" @disabled($initialType !== 'service') class="{{ $field }} pr-10">
                                <option value="">Prefer not to say</option>
                                @foreach ($budgetList as $budgetOption)
                                    <option value="{{ $budgetOption }}" @selected(old('budget') === $budgetOption)>{{ $budgetOption }}</option>
                                @endforeach
                            </select>
                            @error('budget')<p class="{{ $error }}">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </div>

                <div>
                    <label for="{{ $p }}-subject" class="{{ $label }}">Subject <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="{{ $p }}-subject" x-ref="subject" type="text" name="subject" value="{{ old('subject') }}" maxlength="200" class="{{ $field }}">
                    @error('subject')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="{{ $p }}-message" class="{{ $label }}">Message <span class="text-rose-500">*</span></label>
                    <textarea id="{{ $p }}-message" name="message" rows="6" required minlength="15" maxlength="5000" placeholder="What do you need, and by when?" class="{{ $field }}" @error('message') aria-invalid="true" @enderror>{{ old('message') }}</textarea>
                    @error('message')<p class="{{ $error }}">{{ $message }}</p>@enderror
                </div>

                <p class="text-sm text-slate-500 dark:text-slate-400">{{ filled($consent) ? $consent : 'We use your details only to reply to this message.' }}</p>

                <button type="submit" class="{{ \App\Enums\Cms\ButtonStyle::Primary->classes() }} w-full sm:w-auto">
                    <span>{{ $submitLabel }}</span>
                    <x-ui.icon name="arrow-right" class="h-4 w-4" />
                </button>
            </div>
        </form>
    @endif
</div>
