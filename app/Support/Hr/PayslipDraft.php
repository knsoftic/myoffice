<?php

declare(strict_types=1);

namespace App\Support\Hr;

use App\Enums\SalaryComponentGroup;
use App\Support\Money;

/**
 * What `PayrollCalculator::build()` returns: the lines, the totals and the snapshot (phase-07 §6.1, §6.6).
 *
 * **Nothing here is persisted by anything but `PayrollRunService`.** The calculator is a pure function —
 * no writes, no events, no `now()` — so the same inputs always produce the same draft. That is what makes
 * the preview on screen and the slip that gets generated provably the same figures, and what makes a
 * test able to assert a payroll to the paisa.
 *
 * A draft can also be a **skip**: an employee with no salary structure, or no attendance summary, is
 * reported with a named reason and no item is written. Paying somebody zero because a row was missing is
 * the one outcome this design refuses to allow.
 */
final readonly class PayslipDraft
{
    /**
     * @param  list<PayslipLine>  $lines
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public int $employeeId,
        public array $lines,
        public string $grossEarnings,
        public string $totalDeductions,
        public string $netSalary,
        public string $taxableGross,
        public string $dayDivisor,
        public string $perDayAmount,
        public array $snapshot,
        public ?string $skipReason = null,
        public ?string $holdReason = null,
    ) {}

    /**
     * The employee was not paid, and this is why (§6.6 step 0).
     */
    public static function skipped(int $employeeId, string $reason): self
    {
        return new self(
            employeeId: $employeeId,
            lines: [],
            grossEarnings: Money::zero(),
            totalDeductions: Money::zero(),
            netSalary: Money::zero(),
            taxableGross: Money::zero(),
            dayDivisor: '0.0000',
            perDayAmount: Money::zero(),
            snapshot: ['skipped' => $reason],
            skipReason: $reason,
        );
    }

    public function wasSkipped(): bool
    {
        return $this->skipReason !== null;
    }

    /**
     * The sum of one component group — how every reporting handle on the item is filled (§6.6 step 11), so
     * a report never has to know the component codes and can never disagree with the slip.
     */
    public function groupTotal(SalaryComponentGroup $group): string
    {
        $amounts = [];

        foreach ($this->lines as $line) {
            if ($line->group === $group) {
                $amounts[] = $line->amount;
            }
        }

        return $amounts === [] ? Money::zero() : Money::round(Money::sum($amounts));
    }

    /**
     * `gross - deductions = net`, checked rather than assumed (HR-13).
     *
     * The lock asserts this for every item before a run becomes untouchable; a slip whose lines do not
     * add up to its own total is the kind of thing nobody finds until an employee does.
     */
    public function totalsAgree(): bool
    {
        return Money::equals(
            $this->netSalary,
            Money::sub($this->grossEarnings, $this->totalDeductions)
        );
    }
}
