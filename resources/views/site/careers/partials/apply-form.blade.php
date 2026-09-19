{{--
    The inline application form of a job opening (phase-04 §8.11 site/careers/show, §6.8, §6.9).

    @include('site.careers.partials.apply-form', ['job' => $job, 'form' => $form])

    Variables:
      $job     App\Models\Cms\JobOpening (open, deadline not passed)
      $form    array{action: string, cvMaxKb: int, cvMaxLabel: string ("5 MB"), cvExtensions: list<string>,
               cvAccept: string (".pdf,.doc,.docx"), spam: array{honeypot, token_field, token}} — from
               Site\CareerController@show; the CV limits are ApplicationCvService's own numbers
      $consentText  ?string   optional, shown above the submit button

    Posts (multipart) to form.action (site.careers.apply), validated by PublicJobApplicationRequest: applicant_name, email,
    phone, whatsapp, city, experience_years (0-40), expected_salary (decimal string), portfolio_url, linkedin_url,
    cover_letter (max 5000), cv (file), the honeypot, the signed render token, _token.
    The page is rendered per request (site.cache is off site.careers.show), so the tokens are printed inline. The size and
    type checks in the browser are a courtesy only; the server re-reads the file's real type.
--}}

@php
    $form = (array) ($form ?? []);
    $extensions = collect($form['cvExtensions'] ?? ['pdf', 'doc', 'docx'])->map(fn ($type) => strtolower((string) $type))->filter()->values();
    $accept = (string) ($form['cvAccept'] ?? $extensions->map(fn ($type) => '.'.$type)->implode(','));
    $maxKb = max(1, (int) ($form['cvMaxKb'] ?? 5120));
    $maxLabel = (string) ($form['cvMaxLabel'] ?? app_number((int) ceil($maxKb / 1024)).' MB');
    $typeLabel = $extensions->contains('pdf') && ($extensions->contains('doc') || $extensions->contains('docx'))
        ? 'PDF or Word'
        : $extensions->map(fn ($type) => strtoupper($type))->implode(' or ');
    $spam = (array) ($form['spam'] ?? []);
    $guard = [
        'mode' => filled($spam['token'] ?? null) ? 'inline' : 'fetch',
        'url' => \Illuminate\Support\Facades\Route::has('site.forms.token') ? route('site.forms.token') : null,
        'honeypot' => $spam['honeypot'] ?? 'website_url',
        'token_field' => $spam['token_field'] ?? 'form_token',
        'token' => $spam['token'] ?? null,
    ];
    $currency = \App\Support\Format::currencySymbol();
    $field = 'block w-full rounded-xl border-slate-300 bg-white px-3.5 py-2.5 text-base text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25 dark:border-white/10 dark:bg-slate-900 dark:text-white dark:placeholder:text-slate-500';
    $label = 'mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200';
    $errorClass = 'mt-1.5 text-sm text-rose-600 dark:text-rose-400';
@endphp

<form
    method="POST"
    action="{{ $form['action'] ?? route('site.careers.apply', $job->slug) }}"
    enctype="multipart/form-data"
    class="relative space-y-5"
    x-data="siteFormGuard(@js(['url' => $guard['mode'] === 'fetch' ? $guard['url'] : null]))"
    x-on:focusin.once="load()"
    x-on:submit="guard($event)"
>
    @include('site.marketing.partials.form-guard', ['guard' => $guard])

    @if ($errors->any())
        <div class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/25" role="alert" tabindex="-1" x-init="$el.focus()">
            <p class="font-semibold">Your application was not sent.</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                @foreach ($errors->all() as $errorMessage)
                    <li>{{ $errorMessage }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="apply-name" class="{{ $label }}">Full name <span class="text-rose-500">*</span></label>
            <input id="apply-name" type="text" name="applicant_name" value="{{ old('applicant_name') }}" required maxlength="150" autocomplete="name" class="{{ $field }}" @error('applicant_name') aria-invalid="true" @enderror>
            @error('applicant_name')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="apply-email" class="{{ $label }}">Email <span class="text-rose-500">*</span></label>
            <input id="apply-email" type="email" name="email" value="{{ old('email') }}" required maxlength="150" autocomplete="email" class="{{ $field }}" @error('email') aria-invalid="true" @enderror>
            @error('email')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="apply-phone" class="{{ $label }}">Phone <span class="text-rose-500">*</span></label>
            <input id="apply-phone" type="tel" name="phone" value="{{ old('phone') }}" required maxlength="32" autocomplete="tel" class="{{ $field }}" @error('phone') aria-invalid="true" @enderror>
            @error('phone')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="apply-whatsapp" class="{{ $label }}">WhatsApp <span class="font-normal text-slate-400">(optional)</span></label>
            <input id="apply-whatsapp" type="tel" name="whatsapp" value="{{ old('whatsapp') }}" maxlength="32" class="{{ $field }}">
            @error('whatsapp')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="apply-city" class="{{ $label }}">City <span class="font-normal text-slate-400">(optional)</span></label>
            <input id="apply-city" type="text" name="city" value="{{ old('city') }}" maxlength="100" autocomplete="address-level2" class="{{ $field }}">
            @error('city')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="apply-experience" class="{{ $label }}">Years of experience <span class="font-normal text-slate-400">(optional)</span></label>
            <input id="apply-experience" type="number" name="experience_years" value="{{ old('experience_years') }}" min="0" max="40" step="1" inputmode="numeric" class="{{ $field }}">
            @error('experience_years')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="apply-salary" class="{{ $label }}">Expected salary <span class="font-normal text-slate-400">(optional)</span></label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-sm text-slate-500">{{ $currency }}</span>
                <input id="apply-salary" type="text" name="expected_salary" value="{{ old('expected_salary') }}" inputmode="decimal" maxlength="16" class="{{ $field }} pl-12">
            </div>
            @error('expected_salary')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="apply-portfolio" class="{{ $label }}">Portfolio <span class="font-normal text-slate-400">(optional)</span></label>
            <input id="apply-portfolio" type="url" name="portfolio_url" value="{{ old('portfolio_url') }}" maxlength="255" placeholder="https://" class="{{ $field }}">
            @error('portfolio_url')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
        <div class="sm:col-span-2">
            <label for="apply-linkedin" class="{{ $label }}">LinkedIn <span class="font-normal text-slate-400">(optional)</span></label>
            <input id="apply-linkedin" type="url" name="linkedin_url" value="{{ old('linkedin_url') }}" maxlength="255" placeholder="https://www.linkedin.com/in/…" class="{{ $field }}">
            @error('linkedin_url')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
        </div>
    </div>

    <div>
        <label for="apply-cover-letter" class="{{ $label }}">Cover letter <span class="font-normal text-slate-400">(optional)</span></label>
        <textarea id="apply-cover-letter" name="cover_letter" rows="6" maxlength="5000" class="{{ $field }}" placeholder="Why this role, and what would you bring to it?">{{ old('cover_letter') }}</textarea>
        @error('cover_letter')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>

    <div x-data="{ name: '', warning: '', maxBytes: {{ $maxKb * 1024 }}, allowed: @js($extensions->all()) }">
        <span class="{{ $label }}">CV <span class="text-rose-500">*</span></span>
        <label for="apply-cv" class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-600 transition hover:border-brand-400 focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/25 dark:border-white/15 dark:text-slate-300">
            <x-ui.icon name="arrow-up-tray" class="h-6 w-6 text-slate-400" />
            <span x-show="! name"><span class="font-semibold text-brand-700 dark:text-brand-300">Choose a file</span> — {{ $typeLabel }}, max {{ $maxLabel }}</span>
            <span x-show="name" x-cloak class="font-medium text-slate-900 dark:text-white" x-text="name"></span>
            <input
                id="apply-cv"
                type="file"
                name="cv"
                accept="{{ $accept }}"
                required
                class="sr-only"
                x-on:change="
                    const file = $event.target.files[0];
                    name = file ? file.name : '';
                    const ext = file ? file.name.split('.').pop().toLowerCase() : '';
                    warning = ! file ? '' : (! allowed.includes(ext) ? 'That file type is not accepted.' : (file.size > maxBytes ? @js('That file is larger than '.$maxLabel.'.') : ''));
                "
                @error('cv') aria-invalid="true" @enderror
            >
        </label>
        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ $typeLabel }}, max {{ $maxLabel }}. Your CV is stored privately and seen only by the hiring team.</p>
        <p x-show="warning" x-cloak class="mt-1.5 text-sm font-medium text-amber-700 dark:text-amber-400" x-text="warning"></p>
        @error('cv')<p class="{{ $errorClass }}">{{ $message }}</p>@enderror
    </div>

    <p class="text-sm text-slate-500 dark:text-slate-400">
        {{ filled($consentText ?? null) ? $consentText : 'By applying you agree that we may store your details and CV to assess your application for this role.' }}
    </p>

    <button type="submit" class="{{ \App\Enums\Cms\ButtonStyle::Primary->classes() }} w-full sm:w-auto">
        <span>Send application</span>
        <x-ui.icon name="arrow-right" class="h-4 w-4" />
    </button>
</form>
