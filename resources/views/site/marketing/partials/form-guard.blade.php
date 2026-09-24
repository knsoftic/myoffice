{{--
    The anti-forgery and anti-spam fields of a public form (phase-04 §6.9 SpamGuard, §8.13; phase-03 "no request-forgery
    token in a cacheable public page").

    @include('site.marketing.partials.form-guard', [
        'guard' => $guard,   // array{mode: 'fetch'|'inline', url: ?string, honeypot: string, token_field: string, token: ?string}
    ])

    Two modes, chosen by the form that includes this partial (see <x-site.contact-form> and site/careers/partials/apply-form):

      fetch   the page may be served from Phase 3's public cache (a `contact` section on the home page). Nothing per-visitor
              is printed: the guard GETs `url` (route site.forms.token — JSON {"token": <CSRF token>, "form_token":
              SpamGuard::signedTimestamp()}, Cache-Control: no-store) shortly after render, again on first focus if that
              failed, and holds the submit until both have arrived.
      inline  the controller rendered this page per request and never caches it (site.contact.index, site.careers.show —
              C.4 keeps `site.cache` off both) and handed over a fresh SpamGuard token: the session's CSRF token and that
              signed timestamp are printed straight into the form.

    The host <form> carries  x-data="siteFormGuard(@js(['url' => $guard['mode'] === 'fetch' ? $guard['url'] : null]))"
    x-on:focusin.once="load()"  x-on:submit="guard($event)". Nothing here is a security control on its own: the server
    verifies the CSRF token, the signature, the elapsed time and the honeypot.
--}}

@php
    $guard = array_merge(['mode' => 'fetch', 'url' => null, 'honeypot' => 'website_url', 'token_field' => 'form_token', 'token' => null], (array) ($guard ?? []));
    $inline = $guard['mode'] === 'inline';
    $honeypotName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $guard['honeypot']) ?: 'website_url';
    $stampName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $guard['token_field']) ?: 'form_token';
    $honeypotId = 'hp-'.\Illuminate\Support\Str::random(8);
@endphp

<input type="hidden" name="_token" value="{{ $inline ? session()->token() : '' }}" data-guard-csrf>
<input type="hidden" name="{{ $stampName }}" value="{{ $inline ? (string) $guard['token'] : '' }}" data-guard-stamp>

{{-- Honeypot: invisible to people and to screen readers, irresistible to form-filling bots. --}}
<div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
    <label for="{{ $honeypotId }}">Leave this field empty</label>
    <input id="{{ $honeypotId }}" type="text" name="{{ $honeypotName }}" value="" tabindex="-1" autocomplete="off">
</div>

<p x-show="failed" x-cloak class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25" role="alert">
    The form could not be prepared. Check your connection and reload the page before sending.
</p>

@unless ($inline)
    <noscript>
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">This form needs JavaScript to protect it from spam. Please enable it, or contact us by email or phone.</p>
    </noscript>
@endunless

@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                window.Alpine.data('siteFormGuard', (config = {}) => ({
                    ready: false,
                    failed: false,
                    pending: null,

                    init() {
                        if (! config.url) {
                            // Inline mode: the tokens are already in the form.
                            this.ready = true;

                            return;
                        }

                        setTimeout(() => this.load(), 800);
                    },

                    load() {
                        if (this.ready || ! config.url) {
                            return Promise.resolve();
                        }

                        if (this.pending) {
                            return this.pending;
                        }

                        this.pending = fetch(config.url, {
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                            cache: 'no-store',
                        })
                            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('token'))))
                            .then((body) => {
                                const csrf = this.$root.querySelector('[data-guard-csrf]');
                                const stamp = this.$root.querySelector('[data-guard-stamp]');

                                if (csrf && body.token) csrf.value = body.token;
                                if (stamp && body.form_token) stamp.value = body.form_token;

                                this.ready = Boolean(body.token && body.form_token);
                                this.failed = ! this.ready;
                            })
                            .catch(() => {
                                this.failed = true;
                            })
                            .finally(() => {
                                this.pending = null;
                            });

                        return this.pending;
                    },

                    guard(event) {
                        if (this.ready) {
                            return;
                        }

                        event.preventDefault();
                        const form = event.target;

                        this.load().then(() => {
                            if (this.ready) {
                                form.submit();
                            }
                        });
                    },
                }));
            });
        </script>
    @endpush
@endonce
