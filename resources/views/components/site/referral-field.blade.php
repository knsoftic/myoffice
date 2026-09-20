@props([
    'showTyped' => true,
    'label' => 'Referral code',
])

@php
    /**
     * phase-08-09 §6.4 / INV-R2 — the two halves of a public form's referral.
     *
     * The hidden field carries a **visit token**, never a code: the server re-resolves the collaborator
     * from the row that token names, so a visitor who edits the value can at worst point at a visit that
     * does not exist. The visible field is the applicant *typing* a code they were given offline (§67),
     * which the resolver ranks above the token precisely because somebody said it out loud.
     */
    $links = app(\App\Services\Collaborator\ReferralLinkService::class);
    $token = $links->tokenForForm(request());
    $visit = $token !== null ? $links->currentVisit(request()) : null;
    $partner = $visit?->collaborator;
    $showPartner = $partner !== null && (bool) setting('collaborator.referral_public_name_visible', true);
@endphp

@if ($token !== null)
    <input type="hidden" name="{{ \App\Services\Collaborator\ReferralLinkService::FORM_FIELD }}" value="{{ $token }}">
@endif

@if ($showPartner)
    <p class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800 dark:bg-brand-900/30 dark:text-brand-200">
        Referred by <strong>{{ $partner->displayName() }}</strong>.
    </p>
@endif

@if ($showTyped)
    <div x-data="{ code: @js(old(\App\Services\Collaborator\ReferralLinkService::FORM_FIELD) ?? ''), state: null, partner: null,
        async check() {
            this.state = null; this.partner = null;
            if (! this.code.trim()) { return; }
            try {
                const response = await fetch(@js(route('site.referral.validate')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        // Rendered into the page rather than read from a meta tag: the public layout
                        // carries no csrf-token meta, and a validator that silently 419s would look to
                        // an applicant exactly like a code that does not exist.
                        'X-CSRF-TOKEN': @js(csrf_token()),
                    },
                    body: JSON.stringify({ code: this.code }),
                });
                const data = await response.json();
                this.state = data.valid ? 'valid' : 'invalid';
                this.partner = data.collaborator_name ?? null;
            } catch (e) {
                // A validator that cannot reach the server must not stop somebody applying.
                this.state = null;
            }
        } }">
        <label for="referral_code" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
            {{ $label }} <span class="font-normal text-slate-400">(optional)</span>
        </label>
        <input type="text" id="referral_code" name="referral_code" maxlength="32"
            x-model="code" x-on:blur="check()"
            class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
            placeholder="If somebody gave you one">
        <p class="mt-1 text-xs" x-show="state === 'valid'" x-cloak>
            <span class="text-emerald-600 dark:text-emerald-400" x-text="partner ? ('Referred by ' + partner + '.') : 'That code is recognised.'"></span>
        </p>
        <p class="mt-1 text-xs text-amber-600 dark:text-amber-400" x-show="state === 'invalid'" x-cloak>
            We could not find that code. You can leave it empty.
        </p>
    </div>
@endif
