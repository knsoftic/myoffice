{{--
    §68's pipeline as a stepper — admin.admissions.show (phase-14-17 §8.8).

    The stage column is the only source of "where are we", so every step here reads it rather than
    inferring progress from whether a field happens to be filled. A completed step collapses; the
    current one is open; a later one is locked and says which step has to happen first.

    The money panel goes read-only the moment `figures_locked_at` is set — a commission has been
    computed from those numbers by then, and a correction is a fee adjustment that leaves its own row
    (INV-I2). The policy refuses the route too, so this is the explanation rather than the guard.
--}}

@extends('layouts.admin')

@section('title', $admission->admission_number)

@php
    $current = $admission->stage;
    $steps = [
        ['stage' => \App\Enums\AdmissionStage::Application, 'label' => 'Student & figures', 'icon' => 'user'],
        ['stage' => \App\Enums\AdmissionStage::Registration, 'label' => 'Registration', 'icon' => 'identification'],
        ['stage' => \App\Enums\AdmissionStage::FeeCollection, 'label' => 'Fees', 'icon' => 'banknotes'],
        ['stage' => \App\Enums\AdmissionStage::BatchAssignment, 'label' => 'Batch', 'icon' => 'squares-2x2'],
        ['stage' => \App\Enums\AdmissionStage::Active, 'label' => 'Active', 'icon' => 'bolt'],
    ];

    $feeRuleLabel = [
        'none' => 'nothing — a batch seat is enough',
        'any_payment' => 'at least one payment received',
        'full_first_installment' => 'the first installment paid in full',
    ][$feeRule] ?? $feeRule;
@endphp

@section('header')
    <x-ui.page-header :title="$admission->student?->name ?? $admission->admission_number"
                      :subtitle="$admission->admission_number . ' · ' . ($admission->course?->name ?? '')"
                      icon="user-plus"
                      :back="route('admin.admissions.index')">
        <x-slot:actions>
            <x-ui.badge :color="$current->color()" size="lg">{{ $current->label() }}</x-ui.badge>

            @can('print', $admission)
                <x-ui.button variant="ghost" icon="printer" :href="route('admin.admissions.print', $admission)">Print</x-ui.button>
            @endcan

            @can('withdraw', $admission)
                <x-ui.button variant="ghost" icon="arrow-uturn-left"
                             x-on:click.prevent="$dispatch('open-modal', 'withdraw-admission')">Withdraw</x-ui.button>
            @endcan

            @can('cancel', $admission)
                <x-ui.button variant="ghost" icon="x-mark"
                             x-on:click.prevent="$dispatch('open-modal', 'cancel-admission')">Cancel</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    {{-- The stepper. A stage that is behind us is done; the rest are locked until their turn. --}}
    <x-ui.card class="mb-4">
        <ol class="flex flex-wrap items-center gap-2">
            @foreach ($steps as $index => $step)
                @php
                    $done = $current->order() > $step['stage']->order();
                    $here = $current === $step['stage'];
                @endphp
                <li class="flex items-center gap-2">
                    <span @class([
                        'flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold',
                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400' => $done,
                        'bg-brand-600 text-white' => $here,
                        'bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500' => ! $done && ! $here,
                    ])>
                        @if ($done)
                            <x-ui.icon name="check" class="h-4 w-4" />
                        @else
                            {{ $index + 1 }}
                        @endif
                    </span>
                    <span @class([
                        'text-sm',
                        'font-medium text-slate-700 dark:text-slate-200' => $here,
                        'text-slate-500' => ! $here,
                    ])>{{ $step['label'] }}</span>

                    @if (! $loop->last)
                        <x-ui.icon name="chevron-right" class="h-4 w-4 text-slate-300 dark:text-slate-600" />
                    @endif
                </li>
            @endforeach
        </ol>

        @if ($current->isTerminal())
            <p class="mt-3 border-t border-slate-100 pt-3 text-sm text-slate-500 dark:border-slate-800">
                This admission is <strong>{{ strtolower($current->label()) }}</strong>. It keeps every row it
                ever had, and the student may be re-admitted to this course with a new admission.
            </p>
        @endif
    </x-ui.card>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4">
            <x-ui.card title="Student">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Name</dt>
                        <dd>
                            <a href="{{ route('admin.students.show', $admission->student) }}"
                               class="text-sky-700 hover:underline dark:text-sky-400">{{ $admission->student?->name }}</a>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Student code</dt>
                        <dd class="font-mono text-xs text-slate-700 dark:text-slate-200">{{ $admission->student?->student_code }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Registration #</dt>
                        <dd class="font-mono text-xs text-slate-700 dark:text-slate-200">
                            {{ $admission->student?->registration_number ?: 'not issued yet' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Phone</dt>
                        <dd><a href="tel:{{ $admission->student?->phone }}" class="hover:underline">{{ $admission->student?->phone }}</a></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Counsellor</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $admission->counselor?->name ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($admission->application)
                    <p class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800">
                        From application
                        <a href="{{ route('admin.student-applications.show', $admission->application) }}"
                           class="text-sky-700 hover:underline dark:text-sky-400">{{ $admission->application->application_number }}</a>
                    </p>
                @endif
            </x-ui.card>

            @if ($canSeeMoney)
                <x-ui.card title="Agreed figures"
                           :subtitle="$admission->figuresAreLocked() ? 'Locked — a correction is a fee adjustment.' : null">
                    <dl class="space-y-2 text-sm">
                        @foreach ([
                            'Course fee' => $admission->course_fee,
                            'Admission fee' => $admission->admission_fee,
                            'Registration fee' => $admission->registration_fee,
                        ] as $label => $value)
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500">{{ $label }}</dt>
                                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ money($value) }}</dd>
                            </div>
                        @endforeach

                        <div class="flex justify-between gap-3 border-t border-slate-100 pt-2 dark:border-slate-800">
                            <dt class="text-slate-500">Total</dt>
                            <dd class="tabular-nums font-medium text-slate-700 dark:text-slate-200">{{ money($admission->total_amount) }}</dd>
                        </div>

                        @foreach (['Discount' => $admission->discount_amount, 'Scholarship' => $admission->scholarship_amount] as $label => $value)
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500">{{ $label }}</dt>
                                <dd class="tabular-nums text-slate-700 dark:text-slate-200">− {{ money($value) }}</dd>
                            </div>
                        @endforeach

                        <div class="flex justify-between gap-3 border-t border-slate-100 pt-2 dark:border-slate-800">
                            <dt class="font-medium text-slate-600 dark:text-slate-300">Net payable</dt>
                            <dd class="tabular-nums text-base font-semibold text-slate-800 dark:text-slate-100">{{ money($admission->net_payable) }}</dd>
                        </div>
                    </dl>

                    @if (filled($admission->discount_reason))
                        <p class="mt-3 text-xs text-slate-500">Discount reason: {{ $admission->discount_reason }}</p>
                    @endif
                </x-ui.card>

                <x-ui.card title="Money so far" subtitle="Written by the fee service, never on this screen.">
                    <dl class="space-y-2 text-sm">
                        @foreach ([
                            'Charged' => $admission->charged_amount,
                            'Paid' => $admission->paid_amount,
                            'Refunded' => $admission->refunded_amount,
                            'Balance' => $admission->balance_amount,
                        ] as $label => $value)
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500">{{ $label }}</dt>
                                <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ money($value) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-4 lg:col-span-2">
            @if ($canSeeMoney)
                @can('updateFigures', $admission)
                    <x-ui.card title="Change the agreed figures"
                               subtitle="Only before the first charge. After that a correction is a fee adjustment, which leaves its own row.">
                        <form method="POST" action="{{ route('admin.admissions.figures', $admission) }}" class="space-y-3">
                            @csrf
                            @method('PUT')

                            <div class="grid gap-3 sm:grid-cols-3">
                                <x-ui.form.input name="course_fee" label="Course fee" type="number" step="0.01" required
                                                 :value="$admission->course_fee" />
                                <x-ui.form.input name="admission_fee" label="Admission fee" type="number" step="0.01" required
                                                 :value="$admission->admission_fee" />
                                <x-ui.form.input name="registration_fee" label="Registration fee" type="number" step="0.01" required
                                                 :value="$admission->registration_fee" />
                                <x-ui.form.input name="discount_amount" label="Discount" type="number" step="0.01"
                                                 :value="$admission->discount_amount" />
                                <x-ui.form.input name="scholarship_amount" label="Scholarship" type="number" step="0.01"
                                                 :value="$admission->scholarship_amount" />
                                <x-ui.form.input name="monthly_fee" label="Monthly fee" type="number" step="0.01"
                                                 :value="$admission->monthly_fee" />
                            </div>

                            <x-ui.form.input name="discount_reason" label="Discount reason"
                                             :value="$admission->discount_reason" />
                            <x-ui.form.input name="reason" label="Why is this changing?" required
                                             help="The answer to «why is this student paying less than that one»." />

                            <x-ui.button type="submit" variant="primary" icon="check">Save figures</x-ui.button>
                        </form>
                    </x-ui.card>
                @elsecan('view', $admission)
                    @if ($admission->figuresAreLocked())
                        <x-ui.card>
                            <div class="flex items-start gap-3">
                                <x-ui.icon name="lock-closed" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />
                                <p class="text-sm text-slate-600 dark:text-slate-300">
                                    The figures were locked when the first charge was issued
                                    ({{ app_datetime($admission->figures_locked_at) }}). A commission has been
                                    computed from them and money may already have moved, so a correction is a
                                    fee adjustment on the charge rather than an edit here.
                                </p>
                            </div>
                        </x-ui.card>
                    @endif
                @endcan
            @endif

            <x-ui.card title="Next step">
                @if ($current === \App\Enums\AdmissionStage::Application)
                    @can('changeStatus', $admission)
                        <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                            Registering issues the student's registration number. It is issued once per
                            student, not once per admission — a second course does not make them a second
                            person.
                        </p>
                        <form method="POST" action="{{ route('admin.admissions.register', $admission) }}">
                            @csrf
                            <x-ui.button type="submit" variant="primary" icon="identification">Register the student</x-ui.button>
                        </form>
                    @endcan
                @elseif ($current === \App\Enums\AdmissionStage::Registration || $current === \App\Enums\AdmissionStage::FeeCollection)
                    <div class="grid gap-4 sm:grid-cols-2">
                        @can('student_fees.create')
                            <div>
                                <h4 class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">Fees</h4>
                                <form method="POST" action="{{ route('admin.admissions.fees', $admission) }}" class="space-y-3">
                                    @csrf
                                    <x-ui.form.input name="installments" label="Installments" type="number" min="0"
                                                     :max="$admission->course?->max_installments ?? 0"
                                                     :value="$admission->requested_installments"
                                                     :help="$admission->course?->installment_available
                                                         ? 'Up to ' . $admission->course->max_installments . ' for this course.'
                                                         : 'This course is not sold in installments.'" />
                                    <x-ui.form.input name="first_due_date" label="First due date" type="date" />
                                    <x-ui.button type="submit" variant="primary" icon="banknotes">Build the fee structure</x-ui.button>
                                </form>
                            </div>
                        @endcan

                        @can('batches.assign')
                            <div>
                                <h4 class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">Batch</h4>
                                <form method="POST" action="{{ route('admin.admissions.batch', $admission) }}" class="space-y-3">
                                    @csrf
                                    <x-ui.form.input name="batch_id" label="Batch" type="number" required
                                                     help="Batches, their capacity meters and their weekly slots arrive with Phase 16." />
                                    <x-ui.button type="submit" variant="secondary" icon="squares-2x2">Assign the seat</x-ui.button>
                                </form>
                            </div>
                        @endcan
                    </div>

                    <p class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800">
                        These two may happen in either order — institutes seat a student before the first
                        installment clears every day. The rule is applied once, at activation.
                    </p>
                @elseif ($current === \App\Enums\AdmissionStage::BatchAssignment)
                    @can('changeStatus', $admission)
                        <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                            Before a student becomes active this institute requires: <strong>{{ $feeRuleLabel }}</strong>.
                        </p>

                        @if ($activationGaps === [])
                            <form method="POST" action="{{ route('admin.admissions.activate', $admission) }}">
                                @csrf
                                <x-ui.button type="submit" variant="primary" icon="bolt">Activate the student</x-ui.button>
                            </form>
                        @else
                            <ul class="mb-3 space-y-1">
                                @foreach ($activationGaps as $gap)
                                    <li class="flex items-start gap-2 text-sm text-amber-700 dark:text-amber-400">
                                        <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" />
                                        <span>{{ ucfirst($gap) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <x-ui.button variant="primary" icon="bolt" :disabled="true">Activate the student</x-ui.button>
                        @endif
                    @endcan
                @elseif ($current === \App\Enums\AdmissionStage::Active)
                    @can('changeStatus', $admission)
                        <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">
                            The student is active. Completing this admission marks the course finished for
                            them; if it is their only live one, their own status follows.
                        </p>
                        <form method="POST" action="{{ route('admin.admissions.complete', $admission) }}">
                            @csrf
                            <x-ui.button type="submit" variant="primary" icon="check-badge">Mark complete</x-ui.button>
                        </form>
                    @endcan
                @else
                    <x-ui.empty-state icon="check-badge"
                                      :title="$current->label()"
                                      description="There is nothing further to do on this admission." />
                @endif
            </x-ui.card>
        </div>
    </div>

    @can('withdraw', $admission)
        <x-ui.modal name="withdraw-admission" title="Record a withdrawal" icon="arrow-uturn-left">
            <form method="POST" action="{{ route('admin.admissions.withdraw', $admission) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    The student walked away. The admission keeps every row it ever had, and the reason goes
                    on the record — it is the first thing anybody asks about afterwards.
                </p>
                <x-ui.form.textarea name="reason" label="Why are they withdrawing?" required rows="3" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost"
                                 x-on:click="$dispatch('close-modal', 'withdraw-admission')">Keep it</x-ui.button>
                    <x-ui.button type="submit" variant="secondary">Record the withdrawal</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endcan

    @can('cancel', $admission)
        <x-ui.modal name="cancel-admission" title="Cancel this admission" icon="x-mark">
            <form method="POST" action="{{ route('admin.admissions.cancel', $admission) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    The institute is calling it off. This is refused while any money has cleared against it:
                    cancelling over the top of a receipt would leave that money attached to an admission the
                    business says never happened. Refund or transfer it first.
                </p>
                <x-ui.form.textarea name="reason" label="Why?" required rows="3" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost"
                                 x-on:click="$dispatch('close-modal', 'cancel-admission')">Keep it</x-ui.button>
                    <x-ui.button type="submit" variant="danger">Cancel the admission</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endcan
@endsection
