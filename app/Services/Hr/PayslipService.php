<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\PayrollRun;
use App\Models\Hr\PayrollRunItem;
use App\Support\CsvWriter;
use App\Support\Money;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The printable salary slip and the payroll register (phase-07 §6.2, §8.20).
 *
 * **A slip is rendered from its stored rows, never recomputed.** If this class re-ran the calculator, a
 * settings change or a retired component could make a printed slip disagree with the one an employee
 * already has in their hand — and the version in their hand is the one that matters. Everything the slip
 * shows is on the item and its components, snapshotted at generation.
 *
 * PDF waits for dompdf in Phase 13 ([D-HR-16], §13). Until then the print view is a page the browser
 * prints, which is what a business actually does with a payslip anyway.
 */
class PayslipService
{
    /**
     * Everything the print view needs, read from the snapshot.
     *
     * @return array<string, mixed>
     */
    public function viewData(PayrollRunItem $item): array
    {
        $item->loadMissing(['run', 'employee.department', 'employee.designation', 'components']);

        $earnings = $item->components->filter(fn ($line): bool => $line->side->value === 'earning')
            ->sortBy('sort_order')->values();
        $deductions = $item->components->filter(fn ($line): bool => $line->side->value === 'deduction')
            ->sortBy('sort_order')->values();

        return [
            'item' => $item,
            'run' => $item->run,
            'employee' => $item->employee,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'showAttendance' => (bool) setting('hr.payslip_show_attendance', true),
            'footerNote' => setting('hr.payslip_footer_note'),
            'amountInWords' => $this->inWords((string) $item->net_salary),
        ];
    }

    /**
     * The payroll register as CSV — one row per slip, the handles of §6.6 step 11 as columns.
     *
     * The register is deliberately the **item's own columns**, not a re-derivation from the components:
     * it must agree with the slips, and the only way to guarantee that is to read the same numbers.
     */
    public function export(PayrollRun $run): StreamedResponse
    {
        $run->loadMissing(['items.employee']);

        $headers = [
            'Slip', 'Employee code', 'Employee', 'Department', 'Designation',
            'Basic', 'Allowances', 'Bonus', 'Commission', 'Overtime',
            'Gross earnings', 'Unpaid leave', 'Late', 'Tax', 'Advance recovery',
            'Total deductions', 'Net salary',
            'Payable days', 'Lost-pay days', 'Status', 'Paid on', 'Method', 'Reference',
        ];

        $rows = $run->items->map(fn (PayrollRunItem $item): array => [
            $item->slip_number,
            $item->employee?->employee_code,
            $item->employee?->name,
            $item->department_name,
            $item->designation_title,
            (string) $item->basic_salary,
            (string) $item->allowance_amount,
            (string) $item->bonus_amount,
            (string) $item->commission_amount,
            (string) $item->overtime_amount,
            (string) $item->gross_earnings,
            (string) $item->unpaid_leave_deduction,
            (string) $item->late_deduction,
            (string) $item->tax_amount,
            (string) $item->advance_recovery_amount,
            (string) $item->total_deductions,
            (string) $item->net_salary,
            (string) $item->payable_days,
            (string) $item->lop_days,
            $item->status->label(),
            $item->paid_at?->toDateString(),
            $item->payment_method?->label(),
            $item->payment_reference,
        ])->all();

        return (new CsvWriter)->download(
            sprintf('payroll-register-%s.csv', $run->run_number),
            $headers,
            $rows
        );
    }

    /**
     * The net in words, for the line every payslip in this part of the world carries.
     *
     * Built from the string, never from a float: casting 38848.37 to a float to read its parts is exactly
     * the bug `Money` exists to prevent.
     */
    public function inWords(string $amount): string
    {
        $amount = Money::round($amount);
        $negative = Money::isNegative($amount);
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-')), 2, '00');

        $words = $this->groupsToWords($whole);
        $text = $words === '' ? 'zero' : $words;

        if ((int) $fraction > 0) {
            $text .= ' and '.$this->groupsToWords($fraction).' paisa';
        }

        return ucfirst(trim(($negative ? 'minus ' : '').$text));
    }

    /**
     * The South Asian grouping — crore, lakh, thousand — because that is how a slip is read here.
     */
    private function groupsToWords(string $number): string
    {
        $value = (int) $number;

        if ($value === 0) {
            return '';
        }

        $units = [10000000 => 'crore', 100000 => 'lakh', 1000 => 'thousand', 100 => 'hundred'];
        $parts = [];

        foreach ($units as $size => $name) {
            if ($value >= $size) {
                $parts[] = $this->groupsToWords((string) intdiv($value, $size)).' '.$name;
                $value %= $size;
            }
        }

        if ($value > 0) {
            $parts[] = $this->belowHundred($value);
        }

        return trim(implode(' ', $parts));
    }

    private function belowHundred(int $value): string
    {
        $ones = [
            'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen',
            'nineteen',
        ];

        $tens = [
            2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty',
            6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety',
        ];

        if ($value < 20) {
            return $ones[$value];
        }

        $remainder = $value % 10;

        return $tens[intdiv($value, 10)].($remainder > 0 ? '-'.$ones[$remainder] : '');
    }
}
