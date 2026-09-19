{{--
    Section type `contact` — the inquiry form as a section, declared and registered by Phase 4 (phase-04 §2.20 "Phase 4
    also owns the `contact` section type", §8.13, §13 Phase 3 block; requirement §17).

    Receives:
      $section   the published snapshot (id, anchor, provider)
      $content   heading, description, submit_label (optional)
      $section['provider']  App\Support\Cms\Sections\ContactSectionProvider (`is_live`):
                   available: bool
                   form_enabled: bool                   maintenance.contact_form_enabled
                   action_url: ?string                  route('site.contact.store')
                   inquiry_types: list<array{value, label}>
                   services: list<array{id: int, name: string}>   published services
                   budget_options: list<string>         website.contact_budget_options

    This section may sit on a page stored in Phase 3's public cache, so <x-site.contact-form> prints no token here: it
    fetches the CSRF token and the SpamGuard render token from route site.forms.token after render. Without that route
    the component links to the contact page instead of rendering a form that could only fail. The confirmation after the
    POST is identical for spam and non-spam.
--}}

@php
    $fields = (array) ($content ?? []);
    $provider = (array) (data_get($section ?? null, 'provider') ?? []);
    $heading = trim((string) data_get($fields, 'heading', ''));
    $heading = $heading !== '' ? $heading : 'Tell us about your project';
    $description = trim((string) data_get($fields, 'description', ''));
    $sectionId = (int) data_get($section ?? null, 'id', 0);
    $available = (bool) data_get($provider, 'available', true);
@endphp

@if ($available)
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="muted" :label="$heading">
        <div class="mx-auto max-w-3xl">
            <x-site.heading :title="$heading" :subtitle="$description !== '' ? $description : null" align="center" />

            <div class="mt-10 rounded-2xl border border-slate-200 bg-white p-6 shadow-card sm:p-8 dark:border-white/10 dark:bg-slate-900">
                <x-site.contact-form
                    :action="data_get($provider, 'action_url')"
                    :enabled="(bool) data_get($provider, 'form_enabled', true)"
                    :types="(array) data_get($provider, 'inquiry_types', [])"
                    :services="(array) data_get($provider, 'services', [])"
                    :budget-options="(array) data_get($provider, 'budget_options', [])"
                    :submit-label="filled(data_get($fields, 'submit_label')) ? (string) data_get($fields, 'submit_label') : 'Send message'"
                    :id-prefix="'contact-section-'.$sectionId"
                />
            </div>
        </div>
    </x-site.section>
@endif
