@extends('layouts.admin')

@section('title', 'Can this student be certified?')

@section('header')
    <x-ui.page-header :title="$enrollment->student?->name ?? 'This enrolment'"
                      subtitle="Every rule, with the number behind it. This page writes nothing."
                      icon="check-badge">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('admin.certificates.eligible')">Everyone else</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @php($eligible = $report->eligible())

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <x-ui.section-heading title="Eligibility"
                                          subtitle="Run just now. It is run again when somebody presses issue, so a fee cleared in March being outstanding in June is caught then too." />

                    <x-ui.badge :color="$eligible ? 'emerald' : 'amber'">
                        {{ $eligible ? 'Eligible' : 'Not yet' }}
                    </x-ui.badge>
                </div>

                <div class="mt-3">
                    @include('admin.certificates._eligibility', ['report' => $report])
                </div>

                <div class="mt-4 flex justify-end">
                    <x-ui.button icon="document-plus"
                                 :variant="$eligible ? 'primary' : 'secondary'"
                                 :href="route('admin.certificates.create', ['enrollment' => $enrollment->getKey()])">
                        Draft a certificate
                    </x-ui.button>
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card>
                <x-ui.section-heading title="The enrolment" />

                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Student</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $enrollment->student?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Roll number</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $enrollment->student?->student_code ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Course</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $enrollment->batch?->course?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Batch</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $enrollment->batch?->code ?? '—' }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card>
                <x-ui.section-heading title="Certificates already on this enrolment"
                                      subtitle="A revoked one and its replacement both stay here." />

                @forelse ($existing as $certificate)
                    <a href="{{ route('admin.certificates.show', $certificate) }}"
                       class="mt-2 flex items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">
                        <span class="font-mono text-xs text-slate-700 dark:text-slate-200">
                            {{ $certificate->certificate_number ?? 'Draft' }}
                        </span>
                        <x-ui.badge :color="$certificate->status->color()" size="xs">{{ $certificate->status->label() }}</x-ui.badge>
                    </a>
                @empty
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">None yet.</p>
                @endforelse
            </x-ui.card>
        </div>
    </div>
@endsection
