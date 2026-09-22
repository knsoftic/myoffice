<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\FeeReminderType;
use App\Http\Controllers\Controller;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeeInstallment;
use App\Models\Institute\StudentFeeReminder;
use App\Services\Institute\FeeReminderService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Chasing a student for money — `admin.fee-reminders.*` (phase-18 §4.1, §6.8, §97).
 *
 * **The 1062 is caught here and reported as news, not as an error.** `uq_sfr_dedupe` is what stops a
 * student being chased three times in a morning by a retried job, a second scheduler tick and somebody
 * pressing the button. When the button loses that race the honest answer is "they have already been
 * told today", which is a perfectly good outcome — showing a constraint violation would teach people
 * that the button is broken and that pressing it twice more might help.
 *
 * `fee_reminders` is its own module for a reason: a Receptionist may tell a student their installment
 * is due and may not edit the fee, add a discount or take a refund.
 */
final class FeeReminderController extends Controller
{
    public function __construct(
        private readonly FeeReminderService $reminders,
    ) {}

    public function index(Request $request): View
    {
        $branch = $request->user()?->branch_id === null ? null : (int) $request->user()->branch_id;

        $rows = StudentFeeReminder::query()
            ->with(['student:id,name,student_code', 'fee:id,fee_number', 'installment:id,installment_no', 'sender:id,name'])
            ->when($branch !== null, fn ($q) => $q->where(
                fn ($inner) => $inner->whereNull('branch_id')->orWhere('branch_id', $branch),
            ))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('run_uuid'), fn ($q) => $q->where('run_uuid', $request->string('run_uuid')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('sent_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('sent_at', '<=', $request->date('to')))
            ->when($request->filled('source'), fn ($q) => $q->where('is_manual', $request->input('source') === 'manual'))
            ->orderByDesc('sent_at')
            ->paginate(per_page())
            ->withQueryString();

        return view('admin.fee-reminders.index', [
            'reminders' => $rows,
            'types' => FeeReminderType::cases(),
            'filters' => $request->only(['type', 'run_uuid', 'from', 'to', 'source']),
        ]);
    }

    public function store(Request $request, StudentFee $fee): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(FeeReminderType::class)],
            'student_fee_installment_id' => ['nullable', 'integer', 'exists:student_fee_installments,id'],
        ]);

        $subject = $fee;

        if (($validated['student_fee_installment_id'] ?? null) !== null) {
            $line = StudentFeeInstallment::query()->findOrFail((int) $validated['student_fee_installment_id']);

            // Belonging is checked here rather than trusted from the form: an installment id posted
            // against somebody else's charge would otherwise send that student's figures.
            abort_unless((int) $line->student_fee_id === (int) $fee->getKey(), 404);

            $subject = $line;
        }

        try {
            $reminder = $this->reminders->sendNow(
                $subject,
                FeeReminderType::from((string) $validated['type']),
                $request->user(),
            );
        } catch (UniqueConstraintViolationException) {
            return back()->with('toast', [
                'type' => 'info',
                'message' => 'This student has already been told about that today. Nothing was sent twice.',
            ]);
        }

        return back()->with('toast', $reminder === null
            ? [
                'type' => 'warning',
                'message' => 'There is nobody to write to: this student has no login and no email address on file.',
            ]
            : [
                'type' => 'success',
                'message' => sprintf('%s reminder sent.', $reminder->type->label()),
            ]);
    }
}
