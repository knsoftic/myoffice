{{--
    The public verification page — one view for the empty form and for every result (§84,
    phase-19-23 §6.14, INV-21-3).

    **One view, deliberately.** A draft, a privacy opt-out, a soft-deleted row and a code that never
    existed all arrive here as `not_found` and render the same block with the same words.
    `VerificationResult` fixes the shared 404; rendering them through one branch is what stops the
    shared *message* drifting apart the first time somebody improves one of them.

    **Nothing here decides what to show.** `$payload` arrives already filtered by whitelist from
    `institute.certificate_verification_reveals`, so this loops over what it was given. A view that
    reached for `$certificate->student->phone` would be the leak the whitelist exists to prevent —
    which is why the model is not passed in at all.

    Controller variables (Site\VerificationController):
      $outcome    VerificationOutcome|null   null on the empty form
      $result     VerificationResult|null
      $payload    array<string, mixed>       already whitelisted; safe to render
      $subject    'certificate'|'id_card'|null
      $retryAfter int|null                   seconds, when throttled
      $submitted  string                     what they typed, for the form
--}}

@extends('site.layouts.public')

@php
    use App\Enums\VerificationResult;

    $result = $result ?? null;
    $payload = (array) ($payload ?? []);

    // The labels the payload's keys print as. A key with no label here still renders — humanised —
    // so adding one to the reveal list is never silently invisible.
    $labels = [
        'certificate_number'    => 'Certificate number',
        'card_number'           => 'Card number',
        'student_name'          => 'Student',
        'father_name'           => 'Father’s name',
        'student_code'          => 'Roll number',
        'course_name'           => 'Course',
        'batch_name'            => 'Batch',
        'completion_date'       => 'Completed',
        'grade'                 => 'Grade',
        'percentage'            => 'Percentage',
        'trainer_name'          => 'Trainer',
        'attendance_percentage' => 'Attendance',
        'issued_on'             => 'Issued',
        'valid_until'           => 'Valid until',
        'revoked_at'            => 'Revoked on',
        'revocation_reason'     => 'Reason',
    ];

    // Rendered separately, so they are not repeated in the detail list.
    $handled = ['status', 'status_label', 'usable', 'revocation_reason', 'revoked_at'];

    $details = collect($payload)
        ->except($handled)
        ->reject(fn ($value) => $value === null || $value === '')
        ->mapWithKeys(fn ($value, $key) => [($labels[$key] ?? ucfirst(str_replace('_', ' ', $key))) => $value]);
@endphp

@section('title', 'Verify a document')

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => 'Verify a document',
        'subtitle' => 'Type the code printed on a certificate or student card, or scan its QR code, to check that it is genuine.',
    ])

    <x-site.section background="surface">
        <div class="mx-auto max-w-2xl">

            {{-- ------------------------------------------------------------------ the form --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-card sm:p-8 dark:border-white/10 dark:bg-slate-900">
                <form method="POST" action="{{ route('site.verify.submit') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    @csrf

                    <div class="flex-1">
                        <label for="verify-code" class="block text-sm font-medium text-slate-700 dark:text-slate-200">Verification code</label>
                        <input id="verify-code" name="code" type="text" required autocomplete="off"
                               spellcheck="false" maxlength="64"
                               value="{{ old('code', $submitted ?? '') }}"
                               placeholder="K7M2 P9X4 T6B8 N3QD"
                               class="mt-2 w-full rounded-xl border-slate-300 font-mono uppercase tracking-widest text-slate-900 placeholder:tracking-normal placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-slate-800 dark:text-white">
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                            Spaces and dashes are fine — type it however it is printed.
                        </p>
                        @error('code')
                            <p class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- A plain <button>, not <x-site.button>: that component takes a `link` and
                         renders an anchor, so it would have looked right and never submitted. The
                         classes come from the same ButtonStyle the contact form uses. --}}
                    <button type="submit" class="{{ \App\Enums\Cms\ButtonStyle::Primary->classes() }} sm:mb-6">
                        <span>Verify</span>
                        <x-ui.icon name="arrow-right" class="h-4 w-4" />
                    </button>
                </form>
            </div>

            {{-- ------------------------------------------------------------------ the answer --}}
            @if ($result !== null)
                @php
                    // **Literal classes, never interpolated.** Tailwind's scanner reads source files
                    // as text: `bg-{{ $colour }}-50` is invisible to it and the class is never
                    // generated, so the panel would render unstyled. That is D130 — the same reason
                    // `x-ui.badge` resolves colours from a map rather than building class names.
                    $tone = match ($result) {
                        VerificationResult::Valid => [
                            'icon' => 'check-badge',
                            'frame' => 'border-emerald-200 dark:border-emerald-500/25',
                            'band' => 'bg-emerald-50 dark:bg-emerald-500/10',
                            'mark' => 'text-emerald-600 dark:text-emerald-300',
                        ],
                        VerificationResult::Revoked => [
                            'icon' => 'x-circle',
                            'frame' => 'border-rose-200 dark:border-rose-500/25',
                            'band' => 'bg-rose-50 dark:bg-rose-500/10',
                            'mark' => 'text-rose-600 dark:text-rose-300',
                        ],
                        VerificationResult::Throttled => [
                            'icon' => 'clock',
                            'frame' => 'border-amber-200 dark:border-amber-500/25',
                            'band' => 'bg-amber-50 dark:bg-amber-500/10',
                            'mark' => 'text-amber-600 dark:text-amber-300',
                        ],
                        default => [
                            'icon' => 'question-mark-circle',
                            'frame' => 'border-slate-200 dark:border-white/10',
                            'band' => 'bg-slate-50 dark:bg-white/5',
                            'mark' => 'text-slate-500 dark:text-slate-300',
                        ],
                    };
                @endphp

                <div class="mt-8 overflow-hidden rounded-2xl border bg-white shadow-card dark:bg-slate-900 {{ $tone['frame'] }}"
                     role="status" aria-live="polite">

                    <div class="flex items-start gap-4 border-b border-slate-100 p-6 dark:border-white/5 {{ $tone['band'] }}">
                        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white dark:bg-slate-900 {{ $tone['mark'] }}">
                            <x-ui.icon :name="$tone['icon']" class="h-6 w-6" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-lg font-bold text-slate-900 dark:text-white">{{ $result->label() }}</h2>
                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $result->message() }}</p>

                            @if ($result === VerificationResult::Throttled && ($retryAfter ?? null))
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                    Try again in {{ $retryAfter }} second{{ $retryAfter === 1 ? '' : 's' }}.
                                </p>
                            @endif
                        </div>
                    </div>

                    {{-- A revoked document still resolves, and the reason is the whole point of it
                         doing so — somebody holding one needs to be told why. --}}
                    @if (($payload['revocation_reason'] ?? null))
                        <div class="border-b border-slate-100 bg-rose-50/60 p-6 dark:border-white/5 dark:bg-rose-500/5">
                            <p class="text-sm font-semibold text-rose-800 dark:text-rose-300">
                                Withdrawn{{ ($payload['revoked_at'] ?? null) ? ' on '.$payload['revoked_at'] : '' }}
                            </p>
                            <p class="mt-1 text-sm text-rose-700 dark:text-rose-200">{{ $payload['revocation_reason'] }}</p>
                        </div>
                    @endif

                    @if ($details->isNotEmpty())
                        <dl class="divide-y divide-slate-100 dark:divide-white/5">
                            @foreach ($details as $label => $value)
                                <div class="flex flex-wrap items-baseline justify-between gap-2 px-6 py-3">
                                    <dt class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                                    <dd class="font-medium text-slate-900 dark:text-white">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    @if ($subject === 'id_card' && array_key_exists('usable', $payload))
                        <div class="border-t border-slate-100 px-6 py-4 text-sm dark:border-white/5">
                            <span class="text-slate-500 dark:text-slate-400">This card is</span>
                            <span class="font-semibold {{ $payload['usable'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                                {{ $payload['usable'] ? 'valid and in use' : 'not valid' }}</span>.
                        </div>
                    @endif
                </div>

                <p class="mt-6 text-center text-xs text-slate-500 dark:text-slate-400">
                    This page shows only what the institute has chosen to publish. It is not a student record.
                </p>
            @endif
        </div>
    </x-site.section>
@endsection
