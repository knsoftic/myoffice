<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\DataObjects\Institute\FeeSlipOptions;
use App\DataObjects\Institute\IssueFeeData;
use App\Enums\StudentFeeStatus;
use App\Enums\StudentFeeType;
use App\Http\Controllers\Controller;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\Institute\StudentFee;
use App\Services\Institute\FeeSlipBuilder;
use App\Services\Institute\StudentFeeService;
use App\Support\CsvWriter;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The institute's money-owed list — `admin.student-fees.*` (phase-18 §8.1, §8.2).
 *
 * **The controller decides nothing about money.** It validates the shape of a submission and hands it
 * to `StudentFeeService`, which owns the numbering, the caches, `deriveStatus()` and PI-1. A second
 * opinion about any of those is how a screen comes to disagree with a report.
 *
 * **There is no `update` for an amount and no `destroy` that can reach a charge holding a receipt.**
 * A gross that moved after a commission was computed from it would silently change what a partner
 * earned, so a correction is a discount row; and a charge with money against it is cancelled, never
 * deleted, because the receipt is evidence that cash changed hands.
 */
final class StudentFeeController extends Controller
{
    public function __construct(
        private readonly StudentFeeService $fees,
        private readonly FeeSlipBuilder $slips,
    ) {}

    public function index(Request $request): View
    {
        $charges = $this->query($request)->paginate(per_page())->withQueryString();

        return view('admin.student-fees.index', [
            'charges' => $charges,
            'totals' => $this->totals($request),
            'statuses' => StudentFeeStatus::cases(),
            'feeTypes' => StudentFeeType::cases(),
            'pickers' => $this->pickers($request),
            'filters' => $request->only([
                'q', 'status', 'fee_type', 'course_id', 'batch_id', 'branch_id',
                'has_plan', 'has_discount', 'overdue_bucket', 'from', 'to', 'date_field',
            ]),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.student-fees.create', [
            'feeTypes' => StudentFeeType::cases(),
            'pickers' => $this->pickers($request),
            'dueDays' => (int) setting('institute.fee_due_days', 7),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'fee_type' => ['required', Rule::enum(StudentFeeType::class)],
            'gross_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'title' => ['nullable', 'string', 'max:150'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'student_admission_id' => ['nullable', 'integer', 'exists:student_admissions,id'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'gross_amount.gt' => 'A charge of zero is not a charge. Raise the head and record a discount, so the reason the student owes nothing is on the record.',
        ]);

        $charge = $this->fees->issue(new IssueFeeData(
            studentId: (int) $validated['student_id'],
            feeType: StudentFeeType::from((string) $validated['fee_type']),
            grossAmount: (string) $validated['gross_amount'],
            studentAdmissionId: $validated['student_admission_id'] ?? null,
            courseId: $validated['course_id'] ?? null,
            batchId: $validated['batch_id'] ?? null,
            title: $validated['title'] ?? null,
            dueDate: isset($validated['due_date']) ? Carbon::parse((string) $validated['due_date']) : null,
            notes: $validated['notes'] ?? null,
        ), $request->user());

        return redirect()
            ->route('admin.student-fees.show', $charge)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('%s raised for %s.', (string) $charge->fee_number, Money::format((string) $charge->gross_amount)),
            ]);
    }

    public function show(Request $request, StudentFee $fee): View
    {
        $fee->load([
            'student:id,name,student_code,registration_number,email,phone',
            'course:id,name', 'batch:id,code,name', 'branch:id,name',
            'collaborator:id,name,collaborator_code',
            'admission:id,admission_number,net_payable',
        ]);

        return view('admin.student-fees.show', [
            'charge' => $fee,
            'installments' => $fee->installments()->with('payments')->get(),
            'payments' => $fee->payments()->with('receivedBy:id,name')->orderByDesc('paid_on')->get(),
            'discounts' => $fee->discounts()->with(['approver:id,name', 'reversedBy'])->get(),
            'reminders' => $fee->reminders()->with('sender:id,name')->limit(50)->get(),
            // The whole tab is absent without the permission, not blank: a heading with nothing under
            // it tells somebody there is something to see.
            'commissionEntries' => $request->user()?->can('collaborator_commissions.view_financial')
                ? $fee->commissionEntries()->with('collaborator:id,name')->get()
                : null,
        ]);
    }

    public function cancel(Request $request, StudentFee $fee): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => 'Say why this charge is being cancelled — it is the sentence the student is owed if they ask.',
        ]);

        $this->fees->cancel($fee, (string) $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s cancelled, and its unpaid installments with it.', (string) $fee->fee_number),
        ]);
    }

    public function reopen(Request $request, StudentFee $fee): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $reopened = $this->fees->reopen($fee, (string) $validated['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            // The status is worth saying out loud: a charge whose due date passed while it was
            // cancelled comes back straight to `overdue`, which surprises people otherwise.
            'message' => sprintf('%s reopened — it is %s.', (string) $reopened->fee_number, $reopened->status->label()),
        ]);
    }

    public function slip(Request $request, StudentFee $fee): View
    {
        $printCount = (int) $request->integer('print_count', 1);

        return view('admin.student-fees.slip', [
            'slip' => $this->slips->forCharge($fee, FeeSlipOptions::forStaff(
                viewerMaySeeCommission: (bool) $request->user()?->can('collaborator_commissions.view_financial'),
                isReprint: $printCount > 1,
                printCount: $printCount,
            )),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse|RedirectResponse
    {
        if ($format !== 'csv') {
            return back()->with('toast', ['type' => 'error', 'message' => 'Only CSV is available for now.']);
        }

        $charges = $this->query($request)->limit(5000)->get();

        return (new CsvWriter)->download('student-fees-'.app_date(now(), 'Y-m-d').'.csv', [
            'Fee #', 'Student', 'Student code', 'Head', 'Course', 'Batch', 'Gross', 'Discount',
            'Scholarship', 'Net', 'Paid', 'Refunded', 'Balance', 'Due date', 'Status', 'Plan',
        ], $charges->map(static fn (StudentFee $c): array => [
            $c->fee_number,
            $c->student?->name,
            $c->student?->student_code,
            $c->fee_type->label(),
            $c->course?->name,
            $c->batch?->code,
            (string) $c->gross_amount,
            (string) $c->discount_amount,
            (string) $c->scholarship_amount,
            (string) $c->net_amount,
            (string) $c->paid_amount,
            (string) $c->refunded_amount,
            (string) $c->balance_amount,
            $c->due_date?->toDateString(),
            $c->status->label(),
            $c->has_installment_plan ? $c->installment_count.' installments' : 'payable in full',
        ])->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The one query the index, the totals and the export all read, so a filtered total can never
     * describe a different set of rows from the table above it.
     *
     * @return Builder<StudentFee>
     */
    private function query(Request $request): Builder
    {
        $branch = $request->user()?->branch_id === null ? null : (int) $request->user()->branch_id;
        $dateField = $request->input('date_field') === 'due' ? 'due_date' : 'created_at';

        return StudentFee::query()
            ->with(['student:id,name,student_code', 'course:id,name', 'batch:id,code'])
            // D11: a row with no branch belongs to everyone, and a user with no branch sees every row.
            ->when($branch !== null, fn ($q) => $q->where(
                fn ($inner) => $inner->whereNull('branch_id')->orWhere('branch_id', $branch),
            ))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = '%'.$request->string('q')->trim().'%';
                $q->where(function ($inner) use ($term): void {
                    $inner->where('fee_number', 'like', $term)
                        ->orWhereIn('student_id', Student::query()
                            ->where('name', 'like', $term)
                            ->orWhere('student_code', 'like', $term)
                            ->orWhere('registration_number', 'like', $term)
                            ->select('id'));
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('fee_type'), fn ($q) => $q->where('fee_type', $request->string('fee_type')))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->filled('has_plan'), fn ($q) => $q->where('has_installment_plan', $request->boolean('has_plan')))
            ->when($request->filled('has_discount'), fn ($q) => $q->where('discount_amount', '>', 0))
            ->when($request->filled('from'), fn ($q) => $q->whereDate($dateField, '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate($dateField, '<=', $request->date('to')))
            ->when($request->filled('overdue_bucket'), function ($q) use ($request): void {
                [$min, $max] = match ($request->string('overdue_bucket')->value()) {
                    '1-7' => [1, 7],
                    '8-30' => [8, 30],
                    default => [31, 36500],
                };

                $q->where('status', StudentFeeStatus::Overdue->value)
                    ->whereDate('due_date', '<=', Carbon::today()->subDays($min))
                    ->whereDate('due_date', '>=', Carbon::today()->subDays($max));
            })
            ->orderByDesc('id');
    }

    /**
     * The footer sum row — over the filtered set, labelled with the filter in force (§8.1).
     *
     * @return array<string, string>
     */
    private function totals(Request $request): array
    {
        // `without()` because this clones the filtered builder, and that builder eager-loads the
        // student, the course and the batch for the table above. The sum row renders no names, so
        // those were three extra queries per page load - and, since the select below lists only the
        // money columns, the loader had no foreign key to match them on either.
        $rows = (clone $this->query($request))
            ->without(['student', 'course', 'batch'])
            ->reorder()
            ->get([
                'gross_amount', 'discount_amount', 'scholarship_amount',
                'net_amount', 'paid_amount', 'balance_amount',
            ]);

        $sum = static fn (string $column): string => Money::sum(
            $rows->map(static fn (StudentFee $c): string => (string) $c->{$column})->all(),
        );

        return [
            'gross' => $sum('gross_amount'),
            'discount' => $sum('discount_amount'),
            'scholarship' => $sum('scholarship_amount'),
            'net' => $sum('net_amount'),
            'paid' => $sum('paid_amount'),
            'balance' => $sum('balance_amount'),
            'count' => (string) $rows->count(),
        ];
    }

    /**
     * Filter dropdowns, branch-scoped.
     *
     * Phase 17 shipped a leak here: a dropdown listing every batch code in the institute, sitting above
     * a table that WAS correctly scoped. The list is the leak (D-P17), so it is scoped too.
     *
     * @return array<string, Collection<int, string>>
     */
    private function pickers(Request $request): array
    {
        $branch = $request->user()?->branch_id === null ? null : (int) $request->user()->branch_id;

        return [
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'batches' => Batch::query()->forBranch($branch)->orderBy('code')->pluck('code', 'id'),
        ];
    }
}
