<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\PaymentMethod;
use App\Enums\PayrollItemStatus;
use App\Enums\SalaryComponentCalculation;
use App\Enums\SalaryComponentGroup;
use App\Enums\SalaryComponentType;
use App\Http\Controllers\Controller;
use App\Models\Hr\PayrollRunItem;
use App\Models\Hr\SalaryComponent;
use App\Services\Hr\PayrollRunService;
use App\Support\Hr\PayslipLine;
use App\Support\Money;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * One salary slip's actions — `admin.payroll-items.*` (phase-07 §7.6, §8.20), `module:payroll`.
 *
 * Paying a slip is what posts its advance recovery (§6.6 step 8): an unpaid slip has never reduced
 * anybody's advance, because the money has not moved.
 *
 * A correction never touches the original. It writes a **new** item on a correction run with
 * `corrects_item_id`, lines that may be negative and a mandatory reason (§6.8).
 */
final class PayrollRunItemController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly PayrollRunService $runs) {}

    public function pay(Request $request, PayrollRunItem $item): RedirectResponse
    {
        $this->authorize('pay', $item);

        $data = $request->validate([
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:64'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $this->runs->markItemPaid(
            $item,
            PaymentMethod::from($data['payment_method']),
            $data['payment_reference'] ?? null,
            $request->user(),
            isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s marked paid; any advance recovery on it was posted.', $item->slip_number),
        ]);
    }

    public function hold(Request $request, PayrollRunItem $item): RedirectResponse
    {
        $this->authorize('hold', $item);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $this->runs->holdItem($item, $data['reason'], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Held. It is excluded from the run being "fully paid" and is named on the run screen.',
        ]);
    }

    /**
     * Release a hold: the slip goes back to locked and can be paid.
     */
    public function release(Request $request, PayrollRunItem $item): RedirectResponse
    {
        $this->authorize('hold', $item);

        abort_unless($item->status === PayrollItemStatus::OnHold, 422);

        $item->forceFill([
            'status' => PayrollItemStatus::Locked,
            'hold_reason' => null,
        ])->save();

        $this->runs->recomputeTotals($item->run);

        return back()->with('toast', ['type' => 'success', 'message' => 'Hold released.']);
    }

    /**
     * Issue a correction against a locked slip (§6.8).
     */
    public function correction(Request $request, PayrollRunItem $item): RedirectResponse
    {
        $this->authorize('correct', $item);

        $data = $request->validate([
            'salary_component_id' => ['required', 'integer', Rule::exists('salary_components', 'id')],
            'side' => ['required', Rule::enum(SalaryComponentType::class)],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $component = SalaryComponent::query()->findOrFail($data['salary_component_id']);
        $side = SalaryComponentType::from($data['side']);

        $line = new PayslipLine(
            componentCode: $component->code,
            componentName: $component->print_label ?: $component->name,
            group: $side === SalaryComponentType::Earning
                ? $component->component_group
                : $this->deductionGroupFor($component),
            side: $side,
            calculation: SalaryComponentCalculation::Fixed,
            amount: Money::round((string) $data['amount']),
            isTaxable: false,
            salaryComponentId: (int) $component->getKey(),
            calculationNote: $data['reason'],
        );

        $correction = $this->runs->issueCorrection($item, [$line], $data['reason'], $request->user());

        return redirect()
            ->route('admin.payroll-runs.show', $correction->payroll_run_id)
            ->with('toast', [
                'type' => 'success',
                'message' => sprintf('%s issued against %s. The original is untouched.', $correction->slip_number, $item->slip_number),
            ]);
    }

    /**
     * A correction that **takes money back** is a deduction, whatever group the component belongs to —
     * recovering an overpaid bonus is not a bonus.
     */
    private function deductionGroupFor(SalaryComponent $component): SalaryComponentGroup
    {
        return $component->component_group->side() === SalaryComponentType::Deduction
            ? $component->component_group
            : SalaryComponentGroup::OtherDeduction;
    }
}
