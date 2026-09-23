@extends('layouts.admin')

@section('title', $certificate->certificate_number ?? 'Certificate draft')

@section('header')
    <x-ui.page-header :title="$certificate->certificate_number ?? 'Draft certificate'"
                      :subtitle="$certificate->student_name_snapshot.' · '.$certificate->course_name_snapshot"
                      icon="check-badge">
        <x-slot:actions>
            @if ($canPrint && $certificate->status->isPublic())
                <x-ui.button variant="ghost" icon="printer" target="_blank"
                             :href="route('admin.certificates.print', $certificate)">Print</x-ui.button>
                <x-ui.button variant="ghost" icon="document-arrow-down" target="_blank"
                             :href="route('admin.certificates.pdf', $certificate)">PDF</x-ui.button>
            @endif
            <x-ui.button variant="ghost" :href="route('admin.certificates.index')">Back</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($certificate->status === \App\Enums\CertificateStatus::Revoked)
        <div class="mb-4 rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-200">
            <p class="font-medium">
                Revoked {{ app_datetime($certificate->revoked_at) }}@if ($certificate->revoker), by {{ $certificate->revoker->name }}@endif.
            </p>
            <p class="mt-1">{{ $certificate->revocation_reason }}</p>
            <p class="mt-1 text-xs opacity-80">
                Anybody who scans the code reads this. The certificate still resolves — a code that
                stopped resolving would look like a forgery rather than a withdrawal.
            </p>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-ui.card>
                <x-ui.section-heading title="What it says"
                                      subtitle="Snapshots, taken when the certificate was drafted. They do not follow the student record — the printed copy has to stay reproducible." />

                <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    @foreach ([
                        'Student' => $certificate->student_name_snapshot,
                        'Roll number' => $certificate->student_code_snapshot,
                        'Father’s name' => $certificate->father_name_snapshot,
                        'Registration' => $certificate->registration_number_snapshot,
                        'Course' => $certificate->course_name_snapshot,
                        'Batch' => $certificate->batch_name_snapshot,
                        'Trainer' => $certificate->teacher_name_snapshot,
                        'Branch' => $certificate->branch_name_snapshot,
                    ] as $label => $value)
                        <div class="flex justify-between gap-3 border-b border-slate-100 py-1 dark:border-slate-800">
                            <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach

                    @foreach ([
                        'Course started' => app_date($certificate->course_start_date),
                        'Completed' => app_date($certificate->completion_date),
                        'Grade' => $certificate->grade,
                        'Grade points' => $certificate->grade_point === null ? null : app_number($certificate->grade_point, 2),
                        'Percentage' => $certificate->percentage === null ? null : app_number($certificate->percentage, 2).'%',
                        'Attendance' => $certificate->attendance_percentage === null ? null : app_number($certificate->attendance_percentage, 2).'%',
                    ] as $label => $value)
                        <div class="flex justify-between gap-3 border-b border-slate-100 py-1 dark:border-slate-800">
                            <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($certificate->notes)
                    <p class="mt-3 rounded-lg bg-slate-50 p-2 text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-300">
                        {{ $certificate->notes }}
                    </p>
                @endif
            </x-ui.card>

            <x-ui.card>
                <x-ui.section-heading title="Eligibility, as of right now"
                                      subtitle="Re-run on every visit rather than read back from the draft — a fee cleared in March can be outstanding again in June." />

                <div class="mt-3">
                    @include('admin.certificates._eligibility', ['report' => $report])
                </div>

                @php($snapshot = (array) ($certificate->eligibility_snapshot ?? []))

                @if (($snapshot['override_reason'] ?? null) !== null)
                    <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                        <p class="font-medium">Issued against a failing check.</p>
                        <p class="mt-1">{{ $snapshot['override_reason'] }}</p>
                    </div>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card>
                <x-ui.section-heading title="The record" />

                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Status</dt>
                        <dd><x-ui.badge :color="$certificate->status->color()" size="xs">{{ $certificate->status->label() }}</x-ui.badge></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Number</dt>
                        <dd class="font-mono text-xs text-slate-700 dark:text-slate-200">
                            {{ $certificate->certificate_number ?? 'Assigned when issued' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Verification code</dt>
                        <dd class="font-mono text-xs text-slate-700 dark:text-slate-200">
                            {{ $displayCode }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Issued</dt>
                        <dd class="text-slate-700 dark:text-slate-200">
                            {{ $certificate->issued_on ? app_date($certificate->issued_on) : '—' }}
                            @if ($certificate->issuer)
                                <div class="text-xs text-slate-400">by {{ $certificate->issuer->name }}</div>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Template</dt>
                        <dd class="text-slate-700 dark:text-slate-200">{{ $certificate->template?->name ?? 'The default' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Printed</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($certificate->print_count) }}×</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500 dark:text-slate-400">Scanned</dt>
                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">
                            {{ app_number($certificate->verification_count) }}×
                            @if ($canViewLogs)
                                <a href="{{ route('admin.certificates.verifications', $certificate) }}"
                                   class="ml-1 text-xs text-brand-600 hover:underline dark:text-brand-400">see them</a>
                            @endif
                        </dd>
                    </div>
                    @if ($certificate->reissueOf)
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500 dark:text-slate-400">Replaces</dt>
                            <dd class="font-mono text-xs text-slate-700 dark:text-slate-200">
                                {{ $certificate->reissueOf->certificate_number }}
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($certificate->status->isPublic() && filled($certificate->qr_payload))
                    <p class="mt-3 break-all rounded-lg bg-slate-50 p-2 font-mono text-2xs text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                        {{ $certificate->qr_payload }}
                    </p>
                @endif
            </x-ui.card>

            <x-ui.card>
                <x-ui.section-heading title="Actions" />

                <div class="mt-3 space-y-2">
                    @if ($canIssue && $certificate->status === \App\Enums\CertificateStatus::Draft)
                        <x-ui.button type="button" icon="check-badge" class="w-full"
                                     x-on:click="$dispatch('open-modal', 'issue-certificate')">Issue it</x-ui.button>
                    @endif

                    @if ($canIssue && $certificate->status === \App\Enums\CertificateStatus::Issued)
                        <x-ui.button type="button" variant="secondary" icon="x-circle" class="w-full"
                                     x-on:click="$dispatch('open-modal', 'revoke-certificate')">Revoke it</x-ui.button>
                    @endif

                    {{-- Only after a revocation. `reissue()` refuses an issued certificate outright —
                         a replacement exists because the one before it was withdrawn — so offering
                         the button any earlier would be a control that cannot do what it says. --}}
                    @if ($canIssue && $certificate->status === \App\Enums\CertificateStatus::Revoked)
                        <x-ui.button type="button" variant="secondary" icon="arrow-path" class="w-full"
                                     x-on:click="$dispatch('open-modal', 'reissue-certificate')">Issue a replacement</x-ui.button>
                    @endif

                    {{-- A rebuild cannot change what the document says: everything it renders from is
                         frozen. It is for a cleared disk or a moved logo file, not for a correction —
                         a correction is a revocation and a replacement. --}}
                    @if ($canEdit && $certificate->status->isPublic())
                        <form method="POST" action="{{ route('admin.certificates.pdf.regenerate', $certificate) }}">
                            @csrf
                            <x-ui.button type="submit" variant="ghost" icon="arrow-path-rounded-square" class="w-full">
                                Rebuild the PDF
                            </x-ui.button>
                        </form>
                    @endif

                    @if ($canDelete && $certificate->status === \App\Enums\CertificateStatus::Draft)
                        <x-ui.confirm :action="route('admin.certificates.destroy', $certificate)"
                                      title="Delete this draft?"
                                      message="It has no number and nobody has been given it, so there is nothing to preserve. An issued certificate can never be deleted."
                                      confirm-label="Delete the draft">
                            <x-slot:trigger>
                                <x-ui.button type="button" variant="danger" icon="trash" class="w-full">Delete the draft</x-ui.button>
                            </x-slot:trigger>
                        </x-ui.confirm>
                    @endif

                    @if (! $canIssue && ! $canDelete && ! $canEdit)
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            You can read this certificate but not change it.
                        </p>
                    @endif
                </div>
            </x-ui.card>
        </div>
    </div>

    @if ($canIssue && $certificate->status === \App\Enums\CertificateStatus::Draft)
        <x-ui.modal name="issue-certificate" title="Issue this certificate?" icon="check-badge">
            <form method="POST" action="{{ route('admin.certificates.issue', $certificate) }}">
                @csrf

                <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">
                    It gets its number, its QR payload and today's date, and the details above are frozen.
                    After this it can be revoked or reissued, but never edited.
                </p>

                @if ($report !== null && ! $report->eligible())
                    <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                        <p class="font-medium">
                            {{ count($report->failures()) === 1 ? 'One rule is not met.' : count($report->failures()).' rules are not met.' }}
                        </p>
                        <ul class="mt-1 list-inside list-disc">
                            @foreach ($report->failures() as $failure)
                                <li>{{ $failure->message }}</li>
                            @endforeach
                        </ul>
                        @if ($canApprove)
                            <p class="mt-2">Issuing anyway needs a reason, and the failing rules are kept in the record.</p>
                        @else
                            <p class="mt-2">Issuing anyway needs the approve permission, which you do not hold.</p>
                        @endif
                    </div>

                    @if ($canApprove)
                        <x-ui.form.textarea name="override_reason" label="Why issue it anyway?" rows="2" required
                                            help="At least ten characters. Recorded with the failing rules, never instead of them." />
                    @endif
                @endif

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost"
                                 x-on:click="$dispatch('close-modal', 'issue-certificate')">Not yet</x-ui.button>
                    <x-ui.button type="submit" icon="check-badge">Issue it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($canIssue && $certificate->status === \App\Enums\CertificateStatus::Issued)
        <x-ui.modal name="revoke-certificate" title="Revoke this certificate?" icon="x-circle">
            <form method="POST" action="{{ route('admin.certificates.revoke', $certificate) }}">
                @csrf

                <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">
                    The code keeps resolving and the page says it was withdrawn, with the reason you give here.
                    Write it for whoever scans it — an employer, usually — not for the file.
                </p>

                <x-ui.form.textarea name="reason" label="Why" rows="3" required
                                    help="At least ten characters. Published on the verification page." />

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost"
                                 x-on:click="$dispatch('close-modal', 'revoke-certificate')">Keep it</x-ui.button>
                    <x-ui.button type="submit" variant="danger" icon="x-circle">Revoke it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($canIssue && $certificate->status === \App\Enums\CertificateStatus::Revoked)
        <x-ui.modal name="reissue-certificate" title="Issue a replacement certificate?" icon="arrow-path">
            <form method="POST" action="{{ route('admin.certificates.reissue', $certificate) }}">
                @csrf

                <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">
                    A fresh draft is created, with its own code, pointing back at this one; it takes its
                    number when you issue it. Nothing here is altered — the withdrawn certificate and its
                    replacement both stay on the register, and both still resolve.
                </p>

                <x-ui.form.input name="reason" label="Why" required
                                 placeholder="Lost in the post" />

                <div class="mt-4 flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost"
                                 x-on:click="$dispatch('close-modal', 'reissue-certificate')">Cancel</x-ui.button>
                    <x-ui.button type="submit" icon="arrow-path">Create the replacement</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
