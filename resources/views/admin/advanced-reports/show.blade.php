@extends('layouts.admin')

@section('title', $student->name.' — Advanced Reports')

{{--
    One student — admin.advanced-reports.students.show (D177).

    Everything here is about **one admission** (a student on one course), picked with `?admission=`;
    a student on several courses gets a tab per admission. The admissions come from the same query as
    the report's rows, so the status and payment badges are the ones the row showed.

    Money is shown only with `advanced_reports.view_financial`. Without it the fee cards and the
    payment history are not computed at all and a note says why — the page itself never 403s for it.
    The running balance starts at the final fee and walks forward through every receipt and reversal;
    it must end at the admission's cached balance, and when it does not, the page says so (and the
    service has logged it) rather than quietly showing two different numbers.

    Controller variables (AdvancedReportController@show, from AdvancedStudentReportService::studentDetail()):
      $student      Student
      $admissions   list<AdvancedReportRow>      this student's admissions the viewer may see
      $selected     ?AdvancedReportRow
      $canSeeMoney  bool  (also present as $can_see_money, the service's key)
      $enrollment, $progress, $batch, $fees, $history   ?array — see studentDetail()
      $backUrl      the report, with the filters the viewer came from
--}}

@php
    use App\Enums\ProgressStatus;
    use App\Support\Format;
    use App\Support\Money;

    $canSeeMoney = (bool) $canSeeMoney;

    // The service fills these three together; a selected admission without them is one that vanished
    // between two queries, and is shown as "no admission" rather than half a page.
    $hasAdmission = $selected !== null && $enrollment !== null && $progress !== null;

    $tabs = [];

    if (count($admissions) > 1) {
        foreach ($admissions as $admissionRow) {
            $tabs[] = [
                'label' => $admissionRow->courseName.' · '.$admissionRow->admissionNumber,
                'url' => route('admin.advanced-reports.students.show', ['student' => $student->getKey(), 'admission' => $admissionRow->admissionId]),
                'active' => $selected !== null && $selected->admissionId === $admissionRow->admissionId,
            ];
        }
    }

    $personal = [
        'Student ID' => $student->student_code,
        'Registration No' => $student->registration_number,
        'Full Name' => $student->name,
        'Father Name' => $student->father_name,
        'Phone' => $student->phone,
        'Email' => $student->email,
        'Address' => collect([$student->address, $student->city])->filter(fn ($part) => filled($part))->implode(', '),
        'Date of Birth' => $student->date_of_birth ? app_date($student->date_of_birth) : null,
    ];

    // A signed balance, in words: "Rs 500.00 in advance" is not a debt with a minus sign.
    $balanceText = static fn (string $amount): string => Money::isNegative($amount)
        ? money(Money::abs($amount)).' in advance'
        : money($amount);

    $dlRow = 'flex justify-between gap-3';
    $dt = 'text-slate-500 dark:text-slate-400';
    $dd = 'text-right text-slate-700 dark:text-slate-200';
@endphp

@section('header')
    <x-ui.page-header :title="$student->name"
                      :subtitle="collect([$student->student_code, $selected?->courseName])->filter()->implode(' · ')"
                      icon="user"
                      :back="$backUrl"
                      :badge="$selected?->status->label()"
                      :badge-color="$selected?->status->color() ?? 'slate'">
        <x-slot:actions>
            {{-- The way back is the header's own back control (`:back`), which keeps the filters. --}}

            {{-- The profile route asks students.view (and the branch, which this page has already
                 checked); offered only when it would open. --}}
            @module('students')
                @can('students.view')
                    <x-ui.button variant="secondary" icon="user-circle" :href="route('admin.students.show', $student)">
                        Open student profile
                    </x-ui.button>
                @endcan
            @endmodule
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($tabs !== [])
        <x-ui.tabs class="mb-4" :tabs="$tabs" />
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Personal information --------------------------------------------------------------- --}}
        <x-ui.card title="Personal Information" icon="identification">
            <dl class="space-y-2 text-sm">
                @foreach ($personal as $label => $value)
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">{{ $label }}</dt>
                        <dd class="{{ $dd }} break-words">{{ filled($value) ? $value : '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>

        @if (! $hasAdmission)
            <x-ui.card class="lg:col-span-2">
                <x-ui.empty-state icon="academic-cap" title="No admission on record"
                                  message="This student has no admission you can see yet, so there is no course, batch or fee to report on." />
            </x-ui.card>
        @else
            {{-- Enrollment information ---------------------------------------------------------- --}}
            <x-ui.card title="Enrollment Information" icon="clipboard-document-list">
                <dl class="space-y-2 text-sm">
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">Admission No</dt>
                        <dd class="{{ $dd }} font-mono text-xs">{{ $enrollment['admission_number'] }}</dd>
                    </div>
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">Joining Date</dt>
                        <dd class="{{ $dd }}">{{ app_date($enrollment['joining_date']) }}</dd>
                    </div>
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">Course</dt>
                        <dd class="{{ $dd }}">{{ $enrollment['course'] }}</dd>
                    </div>
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">Course Duration</dt>
                        <dd class="{{ $dd }}">{{ $enrollment['course_duration'] ?? '—' }}</dd>
                    </div>
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">Batch</dt>
                        <dd class="{{ $dd }}">{{ $enrollment['batch'] ?? 'Not assigned' }}</dd>
                    </div>
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">Course Start Date</dt>
                        <dd class="{{ $dd }}">{{ $enrollment['course_start'] ? app_date($enrollment['course_start']) : '—' }}</dd>
                    </div>
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">Expected Completion</dt>
                        <dd class="{{ $dd }}">{{ $enrollment['expected_completion'] ? app_date($enrollment['expected_completion']) : '—' }}</dd>
                    </div>
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">Actual Completion</dt>
                        <dd class="{{ $dd }}">{{ $enrollment['actual_completion'] ? app_date($enrollment['actual_completion']) : 'Not completed yet' }}</dd>
                    </div>
                    <div class="{{ $dlRow }} items-center">
                        <dt class="{{ $dt }}">Student Status</dt>
                        <dd class="{{ $dd }}">
                            <x-ui.badge :color="$enrollment['status']->color()" size="sm">{{ $enrollment['status']->label() }}</x-ui.badge>
                            <div class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">Stage: {{ $enrollment['stage']->label() }}</div>
                        </dd>
                    </div>
                    <div class="{{ $dlRow }} items-center">
                        <dt class="{{ $dt }}">Course Completion</dt>
                        <dd class="{{ $dd }}">
                            <x-ui.badge :color="$enrollment['completion_status']->color()" size="sm">{{ $enrollment['completion_status']->label() }}</x-ui.badge>
                        </dd>
                    </div>
                    <div class="{{ $dlRow }} items-center">
                        <dt class="{{ $dt }}">Payment Status</dt>
                        <dd class="{{ $dd }}">
                            <x-ui.badge :color="$selected->payment->color()" size="sm">{{ $selected->payment->label() }}</x-ui.badge>
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            {{-- Course progress --------------------------------------------------------------------- --}}
            <x-ui.card title="Course Progress" icon="chart-bar">
                <div class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $progress['course'] }}</div>

                <div class="mt-3 flex items-baseline justify-between gap-3">
                    <span class="text-3xl font-semibold text-slate-900 dark:text-white">{{ Format::percentage($progress['percentage'], 2) }}</span>
                    <x-ui.badge :color="$progress['status']->color()">{{ $progress['status']->label() }}</x-ui.badge>
                </div>

                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
                     role="progressbar" aria-valuemin="0" aria-valuemax="100"
                     aria-valuenow="{{ (int) min(100, (float) $progress['percentage']) }}"
                     aria-label="Course progress">
                    <div @class([
                            'h-full rounded-full',
                            'bg-emerald-500 dark:bg-emerald-400' => $progress['status'] === ProgressStatus::Completed,
                            'bg-brand-500 dark:bg-brand-400' => $progress['status'] !== ProgressStatus::Completed,
                         ])
                         style="width: {{ min(100, max(0, (float) $progress['percentage'])) }}%"></div>
                </div>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">Course Start Date</dt>
                        <dd class="{{ $dd }}">{{ $progress['start'] ? app_date($progress['start']) : '—' }}</dd>
                    </div>
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">{{ $progress['end_is_expected'] ? 'Expected to end' : 'Ended' }}</dt>
                        <dd class="{{ $dd }}">{{ $progress['end'] ? app_date($progress['end']) : '—' }}</dd>
                    </div>
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">Topics</dt>
                        <dd class="{{ $dd }}">{{ $progress['topics'] ?? 'Not tracked' }}</dd>
                    </div>
                    <div class="{{ $dlRow }}">
                        <dt class="{{ $dt }}">Remaining</dt>
                        <dd class="{{ $dd }}">{{ Format::percentage($progress['remaining'], 2) }}</dd>
                    </div>
                    @if ($progress['completed_on'])
                        <div class="{{ $dlRow }}">
                            <dt class="{{ $dt }}">Completed on</dt>
                            <dd class="{{ $dd }}">{{ app_date($progress['completed_on']) }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            {{-- Batch information ------------------------------------------------------------------- --}}
            <x-ui.card title="Batch Information" icon="user-group">
                @if ($batch === null)
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Not assigned to a batch yet — the schedule and instructor appear once a seat is given.
                    </p>
                @else
                    <dl class="space-y-2 text-sm">
                        <div class="{{ $dlRow }}">
                            <dt class="{{ $dt }}">Batch</dt>
                            <dd class="{{ $dd }}">
                                <span class="font-medium">{{ $batch['code'] }}</span>
                                <div class="text-xs text-slate-400 dark:text-slate-500">{{ $batch['name'] }}</div>
                            </dd>
                        </div>
                        <div class="{{ $dlRow }}">
                            <dt class="{{ $dt }}">Starts</dt>
                            <dd class="{{ $dd }}">{{ $batch['start_date'] ? app_date($batch['start_date']) : '—' }}</dd>
                        </div>
                        <div class="{{ $dlRow }}">
                            <dt class="{{ $dt }}">Ends</dt>
                            <dd class="{{ $dd }}">{{ $batch['end_date'] ? app_date($batch['end_date']) : 'Open-ended' }}</dd>
                        </div>
                        <div class="{{ $dlRow }}">
                            <dt class="{{ $dt }}">Instructor</dt>
                            <dd class="{{ $dd }}">{{ $batch['instructor'] ?? 'Not assigned' }}</dd>
                        </div>
                        <div class="{{ $dlRow }} items-center">
                            <dt class="{{ $dt }}">Enrolment</dt>
                            <dd class="{{ $dd }}">
                                @if ($batch['enrollment_status'])
                                    <x-ui.badge :color="$batch['enrollment_status']->color()" size="sm">{{ $batch['enrollment_status']->label() }}</x-ui.badge>
                                @else
                                    —
                                @endif
                                @if ($batch['roll_number'])
                                    <div class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">Roll {{ $batch['roll_number'] }}</div>
                                @endif
                            </dd>
                        </div>
                        @if ($batch['enrolled_on'])
                            <div class="{{ $dlRow }}">
                                <dt class="{{ $dt }}">Enrolled on</dt>
                                <dd class="{{ $dd }}">{{ app_date($batch['enrolled_on']) }}</dd>
                            </div>
                        @endif
                    </dl>

                    <h3 class="mt-4 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Schedule</h3>
                    @if ($batch['schedule'] === [])
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No class days are recorded for this batch.</p>
                    @else
                        <ul class="mt-2 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                            {{-- Not `$slot`: the admin layout echoes `$slot` and inherits this view's variables. --}}
                            @foreach ($batch['schedule'] as $classSlot)
                                <li class="flex justify-between gap-3 py-2">
                                    <span class="text-slate-700 dark:text-slate-200">{{ $classSlot['label'] }}</span>
                                    <span class="text-right text-slate-500 dark:text-slate-400">{{ $classSlot['teacher'] ?? '—' }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">
                            {{ $batch['schedule_source'] === 'timetable'
                                ? 'From the timetable in force today.'
                                : 'From the batch\'s weekly pattern — no timetable slot is in force today.' }}
                        </p>
                    @endif
                @endif
            </x-ui.card>

            {{-- Fees ------------------------------------------------------------------------------ --}}
            @if ($canSeeMoney && $fees !== null)
                <div class="lg:col-span-2">
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <x-ui.stat-card label="Total Course Fee" :value="money($fees['total_course_fee'])" icon="document-text" color="slate"
                                        delta-label="before discounts" />
                        <x-ui.stat-card label="Discount" :value="money($fees['discount'])" icon="receipt-percent" color="violet"
                                        delta-label="discounts and scholarships" />
                        <x-ui.stat-card label="Final Fee" :value="money($fees['final_fee'])" icon="calculator" color="sky" />
                        <x-ui.stat-card label="Total Paid" :value="money($fees['total_paid'])" icon="banknotes" color="emerald"
                                        delta-label="received, less refunds" />
                        <x-ui.stat-card label="Remaining" :value="money(Money::abs($fees['remaining']))" icon="clock"
                                        :color="Money::isPositive($fees['remaining']) ? 'rose' : ($fees['in_advance'] ? 'emerald' : 'slate')"
                                        :delta-label="Money::isPositive($fees['remaining']) ? 'outstanding' : ($fees['in_advance'] ? 'in advance' : 'nothing outstanding')" />
                        <x-ui.stat-card label="Payment Status" :value="$fees['status']->label()" icon="check-badge" :color="$fees['status']->color()"
                                        :delta-label="$fees['next_due_date'] ? 'next due '.app_date($fees['next_due_date']) : null" />
                    </div>
                </div>
            @elseif (! $canSeeMoney)
                <x-ui.card class="lg:col-span-2" title="Fees" icon="banknotes">
                    <div class="flex items-start gap-3">
                        <x-ui.icon name="lock-closed" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400 dark:text-slate-500" />
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            You do not have permission to view fee amounts. The payment status above is shown to
                            everybody who can open this report; the figures behind it are not.
                        </p>
                    </div>
                </x-ui.card>
            @endif
        @endif
    </div>

    {{-- Payment history ----------------------------------------------------------------------------- --}}
    @if ($hasAdmission && $canSeeMoney && $history !== null)
        <x-ui.card class="mt-4" title="Payment History" icon="banknotes" :padded="false"
                   subtitle="Starts at the final fee; each receipt lowers the remaining balance and each refund or reversal raises it again, oldest first.">
            @unless ($history['reconciles'])
                <div class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300 sm:px-5">
                    This history ends at {{ $balanceText($history['closing']) }}, but the admission's recorded balance is
                    {{ $balanceText($history['expected_closing']) }}. The difference has been logged for the accounts team to look at.
                </div>
            @endunless

            <x-ui.table :is-empty="false" flush caption="Payment history with the remaining balance after each entry">
                <x-slot:head>
                    <th scope="col" class="px-4 py-3">Payment Date</th>
                    <th scope="col" class="px-4 py-3 text-right">Amount Paid</th>
                    <th scope="col" class="px-4 py-3">Payment Method</th>
                    <th scope="col" class="px-4 py-3">Transaction/Receipt ID</th>
                    <th scope="col" class="px-4 py-3">Notes</th>
                    <th scope="col" class="px-4 py-3 text-right">Remaining Balance</th>
                </x-slot:head>

                {{-- The opening line is what is owed, not a payment: its figure is in the balance column. --}}
                <tr>
                    <td class="whitespace-nowrap text-sm text-slate-400 dark:text-slate-500">—</td>
                    <td class="whitespace-nowrap text-right text-sm text-slate-400 dark:text-slate-500">—</td>
                    <td class="text-sm text-slate-400 dark:text-slate-500">—</td>
                    <td class="text-sm text-slate-400 dark:text-slate-500">—</td>
                    <td class="text-sm text-slate-500 dark:text-slate-400">
                        <span class="font-medium text-slate-700 dark:text-slate-200">Final fee</span>
                        — after discounts, across every live charge of this admission
                    </td>
                    <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ $balanceText($history['opening']) }}</td>
                </tr>

                @forelse ($history['rows'] as $entry)
                    <tr>
                        <td class="whitespace-nowrap text-sm">{{ $entry['date'] ? app_date($entry['date']) : '—' }}</td>
                        {{-- A receipt is money paid, so it reads as a plain, positive amount. A refund or a
                             reversal takes money paid back: a minus sign and its own type underneath, so
                             it is never read as a payment. --}}
                        <td class="whitespace-nowrap text-right tabular-nums">
                            @if ($entry['kind'] === 'payment')
                                <span class="text-emerald-600 dark:text-emerald-400">{{ money($entry['magnitude']) }}</span>
                            @else
                                <span class="text-rose-600 dark:text-rose-400">−{{ money($entry['magnitude']) }}</span>
                                <div class="text-xs text-rose-600 dark:text-rose-400">{{ $entry['label'] }}</div>
                            @endif
                            @if ($entry['status'])
                                <div class="mt-0.5">
                                    <x-ui.badge :color="$entry['status_color'] ?? 'slate'" size="xs">{{ $entry['status'] }}</x-ui.badge>
                                </div>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-sm">{{ $entry['method'] ?? '—' }}</td>
                        <td class="whitespace-nowrap font-mono text-xs text-slate-500 dark:text-slate-400">{{ filled($entry['reference']) ? $entry['reference'] : '—' }}</td>
                        <td class="max-w-[16rem] truncate text-sm text-slate-500 dark:text-slate-400" @if ($entry['notes']) title="{{ $entry['notes'] }}" @endif>{{ $entry['notes'] ?? '—' }}</td>
                        <td @class([
                                'whitespace-nowrap text-right font-medium tabular-nums',
                                'text-emerald-600 dark:text-emerald-400' => Money::isNegative($entry['balance']),
                            ])>{{ $balanceText($entry['balance']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-sm text-slate-500 dark:text-slate-400">
                            No payment has been received against this admission yet.
                        </td>
                    </tr>
                @endforelse

                <x-slot:foot>
                    <tr>
                        <td colspan="5">Remaining balance</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ $balanceText($history['closing']) }}</td>
                    </tr>
                </x-slot:foot>
            </x-ui.table>
        </x-ui.card>
    @endif
@endsection
