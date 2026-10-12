{{--
    Section type `signup_cta` (LandingSectionTypes) — the closing banner: a heading on an aurora panel and
    an email box.

    Receives:
      $section  the published snapshot (anchor)
      $content  eyebrow, heading, description, email_placeholder, button_label, note, secondary_button

    **The email box does not send anything on its own.** There is no mailing list behind it; it hands the
    address to the contact form, which is the one public intake (stored in Contact inquiries, spam-guarded,
    rate-limited). The address travels in sessionStorage, never in the URL, so it does not land in a
    server log or a referrer: on submit the browser keeps it and opens the contact page, whose email field
    picks it up. Without JavaScript the button simply opens the contact page. With no contact page (the
    module switched off) the form is not rendered, and the banner keeps its heading and second button.

    The panel is always dark, so its copy sits in a forced-dark scope and reads the same in both themes.
--}}

@php
    use Illuminate\Support\Facades\Route;

    $fields = (array) ($content ?? []);
    $eyebrow = trim((string) data_get($fields, 'eyebrow', ''));
    $heading = trim((string) data_get($fields, 'heading', ''));
    $description = trim((string) data_get($fields, 'description', ''));
    $placeholder = trim((string) data_get($fields, 'email_placeholder', '')) ?: 'you@company.com';
    $buttonLabel = trim((string) data_get($fields, 'button_label', '')) ?: 'Get started';
    $note = trim((string) data_get($fields, 'note', ''));
    $secondary = data_get($fields, 'secondary_button');
    $secondary = is_array($secondary) && filled($secondary['label'] ?? null) && filled($secondary['url'] ?? null) ? $secondary : null;

    $contactUrl = Route::has('site.contact.index') ? route('site.contact.index') : null;
    $inputId = 'signup-email-'.(data_get($section ?? null, 'id') ?? 'x');
@endphp

@if ($heading !== '')
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="surface" :label="$heading">
        <div class="dark">
            <div data-fx="zoom" class="relative isolate overflow-hidden rounded-[2rem] border border-white/10 bg-slate-950 px-6 py-16 text-center shadow-2xl shadow-brand-950/40 sm:px-12 sm:py-20 lg:px-20">
                <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
                    <div class="absolute -left-20 -top-24 h-80 w-[36rem] -rotate-12 rounded-full bg-brand-600/40 blur-3xl"></div>
                    <div class="absolute -bottom-32 right-0 h-80 w-[32rem] rotate-12 rounded-full bg-violet-600/30 blur-3xl"></div>
                    <div class="absolute left-1/2 top-1/2 h-48 w-96 -translate-x-1/2 -translate-y-1/2 rounded-full bg-cyan-500/15 blur-3xl"></div>
                    <div class="absolute inset-0 opacity-40 [mask-image:radial-gradient(ellipse_at_center,black_10%,transparent_70%)]" style="background-image: linear-gradient(to right, rgb(255 255 255 / 0.07) 1px, transparent 1px), linear-gradient(to bottom, rgb(255 255 255 / 0.07) 1px, transparent 1px); background-size: 2.5rem 2.5rem;"></div>
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/40 to-transparent"></div>
                </div>

                <div class="mx-auto max-w-2xl">
                    @if ($eyebrow !== '')
                        <p class="mx-auto mb-6 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-brand-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-cyan-300" aria-hidden="true"></span>
                            {{ $eyebrow }}
                        </p>
                    @endif

                    <h2 class="text-balance bg-gradient-to-br from-white via-white to-brand-200 bg-clip-text text-3xl font-extrabold tracking-tight text-transparent sm:text-4xl lg:text-5xl lg:leading-[1.1]">{{ $heading }}</h2>

                    @if ($description !== '')
                        <p class="mx-auto mt-5 max-w-xl text-pretty text-lg text-slate-300">{{ $description }}</p>
                    @endif

                    @if ($contactUrl !== null)
                        <form
                            method="GET"
                            action="{{ $contactUrl }}"
                            class="mx-auto mt-10 flex max-w-lg flex-col gap-3 sm:flex-row"
                            x-data="{
                                carry() {
                                    try { sessionStorage.setItem('site.signup.email', this.$refs.email.value.trim()); } catch (e) {}
                                    window.location.href = this.$el.action;
                                },
                            }"
                            x-on:submit.prevent="carry()"
                        >
                            <label for="{{ $inputId }}" class="sr-only">{{ $placeholder }}</label>
                            {{-- No `name`: the address is never put in a query string, with or without JavaScript. --}}
                            <input
                                id="{{ $inputId }}"
                                x-ref="email"
                                type="email"
                                required
                                maxlength="150"
                                autocomplete="email"
                                placeholder="{{ $placeholder }}"
                                class="h-12 min-w-0 flex-1 rounded-xl border border-white/15 bg-white/10 px-4 text-base text-white placeholder:text-slate-400 shadow-inner backdrop-blur transition focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-400/40"
                            >
                            <button
                                type="submit"
                                class="inline-flex h-12 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-gradient-to-r from-brand-500 to-violet-500 px-6 text-base font-semibold text-white shadow-lg shadow-brand-600/30 transition duration-200 hover:-translate-y-px hover:from-brand-400 hover:to-violet-400 hover:shadow-brand-500/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950"
                            >
                                {{ $buttonLabel }}
                                <x-ui.icon name="arrow-right" class="h-4 w-4" />
                            </button>
                        </form>
                    @endif

                    @if ($secondary !== null)
                        <div class="mt-5 flex justify-center">
                            <x-site.button :link="$secondary" />
                        </div>
                    @endif

                    @if ($note !== '')
                        <p class="mt-5 text-sm text-slate-400">{{ $note }}</p>
                    @endif
                </div>
            </div>
        </div>
    </x-site.section>
@endif
