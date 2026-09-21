@extends('layouts.admin')

@section('title', $application->application_number)

@section('header')
    <x-ui.page-header :title="$application->name"
                      :subtitle="$application->application_number . ' · submitted ' . app_datetime($application->created_at)"
                      icon="inbox-arrow-down"
                      :back="route('admin.student-applications.index')">
        <x-slot:actions>
            <x-ui.badge :color="$application->status->color()" size="lg">{{ $application->status->label() }}</x-ui.badge>

            @can('claim', $application)
                @if ($application->status === \App\Enums\StudentApplicationStatus::Submitted)
                    <form method="POST" action="{{ route('admin.student-applications.claim', $application) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" icon="hand-raised">I am reviewing this</x-ui.button>
                    </form>
                @endif
            @endcan
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($duplicates->isNotEmpty())
        <x-ui.card class="mb-4 border-amber-200 dark:border-amber-500/30">
            <div class="flex items-start gap-3">
                <x-ui.icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-slate-700 dark:text-slate-200">
                        Same person? {{ $duplicates->count() }} other
                        {{ \Illuminate\Support\Str::plural('application', $duplicates->count()) }}
                        share this phone, name and course within {{ $duplicateWindow }} days.
                    </p>
                    <p class="mt-1 text-sm text-slate-500">
                        This is a flag, never a refusal — the same person really does re-apply. Decide, and
                        the decision is recorded either way.
                    </p>

                    <div class="mt-3 space-y-2">
                        @foreach ($duplicates as $other)
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 p-2 dark:bg-slate-800/60">
                                <div class="text-sm">
                                    <a href="{{ route('admin.student-applications.show', $other) }}"
                                       class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $other->application_number }}</a>
                                    <span class="text-slate-500">· {{ app_datetime($other->created_at) }} · {{ $other->course?->name }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <x-ui.badge :color="$other->status->color()" size="xs">{{ $other->status->label() }}</x-ui.badge>
                                    @can('markDuplicate', $application)
                                        <form method="POST" action="{{ route('admin.student-applications.duplicate', $application) }}"
                                              class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="duplicate_of_application_id" value="{{ $other->id }}">
                                            <input type="hidden" name="reason" value="Same applicant as {{ $other->application_number }}.">
                                            <x-ui.button type="submit" variant="ghost" size="sm">Mark this a duplicate of it</x-ui.button>
                                        </form>
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-ui.card>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card title="What they submitted" subtitle="Read-only: this is the record of what was asked for.">
            <dl class="space-y-2 text-sm">
                @foreach ([
                    'Name' => $application->name,
                    "Father's name" => $application->father_name,
                    'Phone' => $application->phone,
                    'WhatsApp' => $application->whatsapp,
                    'Email' => $application->email,
                    'City' => $application->city,
                    'Education' => $application->education,
                    'Course' => $application->course?->name,
                    'Preferred timing' => $application->preferred_timing?->label(),
                    'Preferred mode' => $application->preferred_delivery_mode?->label(),
                ] as $label => $value)
                    @if (filled($value))
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">{{ $label }}</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>

            @if (filled($application->message))
                <div class="mt-4 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                    <p class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $application->message }}</p>
                </div>
            @endif

            <div class="mt-4 rounded-lg border p-3 text-sm
                        {{ $referralVerdict['tone'] === 'emerald' ? 'border-emerald-200 dark:border-emerald-500/30' : '' }}
                        {{ $referralVerdict['tone'] === 'amber' ? 'border-amber-200 dark:border-amber-500/30' : '' }}
                        {{ $referralVerdict['tone'] === 'slate' ? 'border-slate-200 dark:border-slate-700' : '' }}">
                <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Referral</div>
                <p class="text-slate-700 dark:text-slate-200">{{ $referralVerdict['message'] }}</p>
            </div>
        </x-ui.card>

        @can('convert', $application)
            <x-ui.card title="Convert" subtitle="Student, admission and attribution in one transaction, or none of them.">
                <form method="POST" action="{{ route('admin.student-applications.convert', $application) }}" class="space-y-4">
                    @csrf

                    @if ($existingStudents->isNotEmpty())
                        <div class="rounded-lg border border-amber-200 p-3 dark:border-amber-500/30">
                            <p class="mb-2 text-sm text-slate-600 dark:text-slate-300">
                                There {{ $existingStudents->count() === 1 ? 'is' : 'are' }}
                                {{ $existingStudents->count() }} existing
                                {{ \Illuminate\Support\Str::plural('student', $existingStudents->count()) }}
                                with this phone number. Link to one rather than creating a second record.
                            </p>
                            <x-ui.form.select name="existing_student_id" label="Link to an existing student"
                                              placeholder="No — create a new student">
                                @foreach ($existingStudents as $existing)
                                    <option value="{{ $existing->id }}">
                                        {{ $existing->name }} ({{ $existing->student_code }}) — {{ $existing->status->label() }}
                                    </option>
                                @endforeach
                            </x-ui.form.select>
                        </div>
                    @endif

                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.form.input name="admission_date" label="Admission date" type="date"
                                         :value="now()->toDateString()" />
                        <x-ui.form.input name="course_fee" label="Course fee" type="number" step="0.01"
                                         :value="$application->course?->course_fee" />
                        <x-ui.form.input name="admission_fee" label="Admission fee" type="number" step="0.01"
                                         :value="$application->course?->admission_fee" />
                        <x-ui.form.input name="registration_fee" label="Registration fee" type="number" step="0.01"
                                         :value="$application->course?->registration_fee" />
                        <x-ui.form.input name="discount_amount" label="Discount" type="number" step="0.01" value="0" />
                        <x-ui.form.input name="scholarship_amount" label="Scholarship" type="number" step="0.01" value="0" />
                        <div class="sm:col-span-2">
                            <x-ui.form.input name="discount_reason" label="Reason for the discount"
                                             help="Required by the institute's own discipline whenever one is given." />
                        </div>
                    </div>

                    <x-ui.button type="submit" variant="primary" icon="user-plus" class="w-full">Convert to a student</x-ui.button>
                </form>
            </x-ui.card>
        @endcan
    </div>

    @if ($application->status->isOpen())
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            @can('reject', $application)
                <x-ui.card title="Reject">
                    <form method="POST" action="{{ route('admin.student-applications.reject', $application) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.input name="reason" label="Reason" required
                                         help="Somebody filled this in and is waiting to hear why the answer is no." />
                        <x-ui.button type="submit" variant="danger" icon="x-mark">Reject</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan

            @can('withdraw', $application)
                <x-ui.card title="Withdrawn by the applicant">
                    <form method="POST" action="{{ route('admin.student-applications.withdraw', $application) }}" class="space-y-3">
                        @csrf
                        <x-ui.form.input name="reason" label="Reason" required />
                        <x-ui.button type="submit" variant="secondary" icon="arrow-uturn-left">Record withdrawal</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan
        </div>
    @endif
@endsection
