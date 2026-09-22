<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\DataObjects\Institute\FeeSlipOptions;
use App\DataObjects\Institute\FeeStructureData;
use App\Enums\StudentFeeType;
use App\Http\Controllers\Controller;
use App\Models\Institute\StudentAdmission;
use App\Models\Institute\StudentFee;
use App\Services\Institute\FeeSlipBuilder;
use App\Services\Institute\StudentFeeService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Turning an admission into its charges — `admin.fee-structures.*` (phase-18 §6.1, §8.3, §69).
 *
 * **`preview()` writes nothing, and that is the whole reason it exists separately.** The wizard's
 * review step shows the proof line `SUM(net) = admission net payable` before anybody commits, because
 * a structure that does not add up to the figure the student agreed is not a rounding annoyance — that
 * figure is the spine's collectible denominator, so a wrong one is a wrong commission waiting to be
 * paid. The service asserts it again inside the transaction and aborts the whole thing if it fails.
 *
 * **`store()` is safe to double-click** (§6.1.3, F-3.15). The generator composes a `generation_key`
 * per head, INSERTs, and treats a 1062 on `uq_sf_generation` as "already generated". There is no
 * SELECT-then-insert in the path, so two admins on the wizard at once produce one set of charges and
 * the loser is told so rather than shown an error.
 */
final class FeeStructureController extends Controller
{
    public function __construct(
        private readonly StudentFeeService $fees,
        private readonly FeeSlipBuilder $slips,
    ) {}

    /**
     * The wizard's review step: what would be raised, and whether it balances. Writes nothing.
     */
    public function preview(Request $request, StudentAdmission $admission): JsonResponse
    {
        $validated = $request->validate([
            'amounts' => ['required', 'array', 'min:1'],
            'amounts.*' => ['numeric', 'gte:0'],
        ]);

        /** @var array<string, string> $amounts */
        $amounts = array_map(static fn ($v): string => Money::of((string) $v), (array) $validated['amounts']);
        $amounts = array_filter($amounts, static fn (string $v): bool => Money::isPositive($v));

        $heads = [];
        $total = Money::ZERO;

        foreach ($amounts as $head => $amount) {
            if (StudentFeeType::tryFrom((string) $head) === null) {
                continue;
            }

            $heads[] = [
                'type' => (string) $head,
                'label' => StudentFeeType::from((string) $head)->label(),
                'amount' => $amount,
                'existing' => StudentFee::query()
                    ->where('generation_key', sprintf('structure:%d:%s', (int) $admission->getKey(), (string) $head))
                    ->exists(),
            ];

            $total = Money::add($total, $amount);
        }

        // The discount and scholarship the admission already agreed come off the total, in the fixed
        // cascade order of §6.1.2 — so the proof line the user sees is the one the service will assert.
        $reductions = Money::add(
            Money::of((string) $admission->discount_amount),
            Money::of((string) $admission->scholarship_amount),
        );

        $net = Money::sub($total, $reductions);
        $netPayable = Money::of((string) $admission->net_payable);

        return response()->json([
            'heads' => $heads,
            'gross_total' => $total,
            'reductions' => $reductions,
            'net_total' => $net,
            'net_payable' => $netPayable,
            'balances' => Money::compare($net, $netPayable) === 0,
            'difference' => Money::sub($net, $netPayable),
        ]);
    }

    public function store(Request $request, StudentAdmission $admission): RedirectResponse
    {
        $validated = $request->validate([
            'amounts' => ['required', 'array', 'min:1'],
            'amounts.*' => ['numeric', 'gte:0'],
            'due_dates' => ['nullable', 'array'],
            'due_dates.*' => ['nullable', 'date'],
            'copy_admission_discount' => ['sometimes', 'boolean'],
            'replace_unpaid' => ['sometimes', 'boolean'],
            'approved_by' => ['nullable', 'integer', 'exists:users,id'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ], [
            'idempotency_key.required' => 'This wizard is missing its duplicate guard. Reload it rather than retrying.',
        ]);

        $dueDates = [];

        foreach ((array) ($validated['due_dates'] ?? []) as $head => $date) {
            if ($date !== null && $date !== '') {
                $dueDates[(string) $head] = Carbon::parse((string) $date);
            }
        }

        $result = $this->fees->generateStructure($admission, new FeeStructureData(
            amounts: array_map(static fn ($v): string => (string) $v, (array) $validated['amounts']),
            dueDates: $dueDates,
            copyAdmissionDiscount: (bool) ($validated['copy_admission_discount'] ?? true),
            replaceUnpaid: (bool) ($validated['replace_unpaid'] ?? false),
            idempotencyKey: (string) $validated['idempotency_key'],
            approvedBy: $validated['approved_by'] ?? null,
        ), $request->user());

        return redirect()
            ->route('admin.fee-structures.slip', $admission)
            ->with('toast', [
                // `created` false after a double-click is the normal answer, not an error — and the
                // caption says which of the two happened, because "8 charges created" after doing
                // nothing is how somebody generates a second set by hand.
                'type' => $result->created ? 'success' : 'info',
                'message' => $result->caption(),
            ]);
    }

    public function slip(Request $request, StudentAdmission $admission): View
    {
        return view('admin.student-fees.structure-slip', [
            'structure' => $this->slips->forAdmission($admission, FeeSlipOptions::forStaff(
                viewerMaySeeCommission: (bool) $request->user()?->can('collaborator_commissions.view_financial'),
            )),
            'admission' => $admission->load(['student:id,name,student_code,registration_number', 'course:id,name']),
        ]);
    }

    /**
     * §10.4's `fees:generate-monthly`, run by hand from the charges index for one month.
     */
    public function generateMonthly(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'student_admission_id' => ['required', 'integer', 'exists:student_admissions,id'],
            'month' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'due_day' => ['nullable', 'integer', Rule::in(range(1, 28))],
        ]);

        $admission = StudentAdmission::query()->findOrFail((int) $validated['student_admission_id']);

        $charge = $this->fees->generateMonthlyCharge(
            $admission,
            Carbon::parse((string) $validated['month'].'-01'),
            (string) $validated['amount'],
            $validated['due_day'] ?? null,
            $request->user(),
        );

        return back()->with('toast', $charge === null
            // Null, not an exception: a month already charged is the common case, and an error here
            // would train people to ignore the toast.
            ? ['type' => 'info', 'message' => 'That month already has a monthly fee. Nothing was changed.']
            : ['type' => 'success', 'message' => sprintf('%s raised for %s.', (string) $charge->fee_number, (string) $charge->title)]);
    }
}
