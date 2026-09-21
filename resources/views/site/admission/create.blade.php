{{--
    §67's public admission form — site.admission.create (phase-14-17 §7.10, §8.x).

    **This form creates one application row and nothing else** ([D-IN-7]). No student, no login, no
    fee: somebody filling in a form is making a request, and turning that into a student record would
    hand the student directory to the internet.

    Three fields carry no visible label and are not decoration:
      · `idempotency_key`  one ULID per rendered form. A double-tapped submit is the same application.
      · `form_rendered_at` the timestamp the request checks against — nobody completes this in under
                           two seconds, and a bot does.
      · `website`          the honeypot. Hidden from a person, irresistible to a form-filler.

    The referral field is Phase 9's component and carries a hashed visit token, never a code and never
    a collaborator id (INV-R2). Whatever it sends is a claim the server resolves; a `collaborator_id`
    in the body is ignored entirely (INV-I4).

    SEO comes from Phase 3's pipeline through the layout, like every other public page (D23).
--}}

@extends('layouts.site')

@section('content')
    @if ($isPreview)
        <div class="border-b border-amber-200 bg-amber-50 py-2 dark:border-amber-500/30 dark:bg-amber-500/10">
            <div class="mx-auto flex max-w-3xl items-center gap-2 px-4 text-sm text-amber-800 dark:text-amber-300">
                <x-ui.icon name="eye" class="h-4 w-4 shrink-0" />
                <span>
                    Admissions are <strong>closed</strong> to the public right now. You are seeing this form
                    because you can open them — a visitor gets the closed page instead.
                </span>
            </div>
        </div>
    @endif

    <section class="bg-slate-50 py-12 dark:bg-slate-900/40">
        <div class="mx-auto max-w-3xl px-4 text-center">
            <h1 class="text-3xl font-semibold text-slate-800 dark:text-slate-100">Apply for admission</h1>
            <p class="mx-auto mt-3 max-w-xl text-slate-600 dark:text-slate-300">
                Tell us who you are and what you want to study. Somebody will call you back — there is
                nothing to pay here and no account to create.
            </p>
        </div>
    </section>

    <section class="py-12">
        <div class="mx-auto max-w-3xl px-4">
            @if ($errors->any())
                <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-500/30 dark:bg-rose-500/10">
                    <p class="font-medium text-rose-800 dark:text-rose-300">Please check the form</p>
                    <ul class="mt-2 list-inside list-disc text-sm text-rose-700 dark:text-rose-400">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('site.admission.store') }}" class="space-y-8">
                @csrf

                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                <input type="hidden" name="form_rendered_at" value="{{ $renderedAt }}">

                {{-- The honeypot. aria-hidden and off the tab order so nobody using a screen reader or a
                     keyboard ever lands in it; a bot fills every field it can find. --}}
                <div class="absolute h-px w-px overflow-hidden" aria-hidden="true">
                    <label for="website">Leave this empty</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
                </div>

                <div>
                    <h2 class="mb-4 text-lg font-semibold text-slate-800 dark:text-slate-100">About you</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.form.input name="name" label="Full name" required :value="old('name')" />
                        <x-ui.form.input name="father_name" label="Father's name" :value="old('father_name')" />
                        <x-ui.form.input name="phone" label="Phone" required :value="old('phone')"
                                         help="We will call you on this." />
                        <x-ui.form.input name="whatsapp" label="WhatsApp" :value="old('whatsapp')" />
                        <x-ui.form.input name="email" label="Email" type="email" :value="old('email')" />
                        <x-ui.form.input name="city" label="City" :value="old('city')" />
                        <div class="sm:col-span-2">
                            <x-ui.form.input name="education" label="Last qualification" :value="old('education')"
                                             help="In your own words — «BSc Computer Science, 3rd semester» is fine." />
                        </div>
                    </div>
                </div>

                <div>
                    <h2 class="mb-4 text-lg font-semibold text-slate-800 dark:text-slate-100">What you want to study</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <x-ui.form.select name="course_id" label="Course" required placeholder="Choose a course">
                                @foreach ($courses as $course)
                                    <option value="{{ $course->id }}"
                                            @selected(old('course_id') == $course->id || $selected === $course->slug)>
                                        {{ $course->name }}
                                    </option>
                                @endforeach
                            </x-ui.form.select>
                        </div>

                        @if ($requiresBatch)
                            <div class="sm:col-span-2">
                                <x-ui.form.input name="batch_id" label="Batch" type="number" required
                                                 :value="old('batch_id')"
                                                 help="Ask us which batches are open if you are not sure." />
                            </div>
                        @endif

                        <x-ui.form.select name="preferred_timing" label="When suits you" placeholder="Any time">
                            @foreach ($timings as $value => $label)
                                <option value="{{ $value }}" @selected(old('preferred_timing') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.form.select>

                        <x-ui.form.select name="preferred_delivery_mode" label="How you want to study" placeholder="Either">
                            @foreach ($modes as $value => $label)
                                <option value="{{ $value }}" @selected(old('preferred_delivery_mode') === $value)>{{ $label }}</option>
                            @endforeach
                        </x-ui.form.select>

                        <div class="sm:col-span-2">
                            <x-ui.form.textarea name="message" label="Anything else we should know" rows="3"
                                                :value="old('message')" />
                        </div>
                    </div>
                </div>

                <div>
                    <h2 class="mb-4 text-lg font-semibold text-slate-800 dark:text-slate-100">Referral</h2>
                    {{-- Phase 9's component: renders the partner's name once a code validates, and posts a
                         token rather than the code itself. --}}
                    <x-site.referral-field />
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    {{-- A submit, so not x-site.button (which renders an anchor): the classes come from the
                         same ButtonStyle enum the CMS buttons use, so it cannot drift from them. --}}
                    <button type="submit"
                            class="{{ \App\Enums\Cms\ButtonStyle::Primary->classes() }} inline-flex items-center gap-2 px-6 py-3 text-base">
                        <x-ui.icon name="paper-airplane" class="h-5 w-5" />
                        Submit the application
                    </button>
                    <p class="text-xs text-slate-500">
                        Nothing is charged here. We will call you before anything is confirmed.
                    </p>
                </div>
            </form>
        </div>
    </section>
@endsection
