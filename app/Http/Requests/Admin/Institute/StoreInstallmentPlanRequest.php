<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\DataObjects\Institute\InstallmentLine;
use App\Services\Institute\InstallmentPlanCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Build or rebuild an installment plan (phase-18 §8.4).
 *
 * **The server re-asserts that the lines sum to the net fee, and the wizard does too.** The screen
 * disables its submit button until the banner turns emerald, and that is a courtesy, not a guard: a
 * posted body is a posted body. `StudentFeeService` asserts PI-1 again inside the transaction, so the
 * same rule is stated three times on purpose — once for the person, once at the door, once where it
 * cannot be skipped.
 *
 * **Dates must strictly ascend.** Two lines due the same day are one line the student will pay once,
 * and a plan that runs backwards makes "the next installment" unanswerable — which is precisely the
 * question a reminder has to answer every morning.
 *
 * A rebuild needs a reason; building the first plan does not. Cancelling somebody's existing schedule
 * is a thing they may ring up about.
 */
final class StoreInstallmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:'.InstallmentPlanCalculator::MIN_LINES, 'max:'.InstallmentPlanCalculator::MAX_LINES],
            'lines.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'lines.*.due_date' => ['required', 'date'],
            'lines.*.installment_no' => ['nullable', 'integer', 'min:1'],
            // Required only on a rebuild; `withValidator` decides, because whether this is a rebuild is
            // a fact about the charge rather than about the form.
            'reason' => ['nullable', 'string', 'min:3', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $lines */
            $lines = (array) $this->input('lines', []);

            if ($lines === []) {
                return;
            }

            $previous = null;

            foreach (array_values($lines) as $index => $line) {
                $due = CarbonImmutable::parse((string) ($line['due_date'] ?? ''));

                if ($previous !== null && $due->lessThanOrEqualTo($previous)) {
                    $validator->errors()->add('lines.'.$index.'.due_date', sprintf(
                        'Installment %d is due on %s, which is not after installment %d (%s). Due dates '
                        .'must move forward.',
                        $index + 1,
                        $due->toDateString(),
                        $index,
                        $previous->toDateString(),
                    ));
                }

                $previous = $due;
            }

            // The sum check the wizard's banner shows, restated where it cannot be skipped. The service
            // asserts PI-1 again inside its transaction — three statements of one rule, deliberately.
            $charge = $this->route('fee');

            if ($charge === null) {
                return;
            }

            $total = Money::sum(array_map(
                static fn (array $line): string => Money::of((string) ($line['amount'] ?? '0')),
                array_values($lines),
            ));

            $net = Money::of((string) $charge->net_amount);

            if (Money::compare($total, $net) !== 0) {
                $validator->errors()->add('lines', sprintf(
                    'The installments add up to %s but the net fee is %s — a difference of %s. A plan '
                    .'that does not sum to the fee leaves a charge that can never reach paid.',
                    Money::format($total),
                    Money::format($net),
                    Money::format(Money::abs(Money::sub($total, $net))),
                ));
            }

            if ($this->isRebuild() && trim((string) $this->input('reason')) === '') {
                $validator->errors()->add('reason',
                    'Rebuilding cancels the student\'s current unpaid installments. Say why — they may '
                    .'well ring up about it.');
            }
        });
    }

    /**
     * @return list<InstallmentLine>
     */
    public function toLines(int $startNumber = 1): array
    {
        $out = [];

        foreach (array_values((array) $this->input('lines', [])) as $index => $line) {
            $out[] = new InstallmentLine(
                number: (int) ($line['installment_no'] ?? $startNumber + $index),
                amount: Money::of((string) ($line['amount'] ?? '0')),
                dueDate: CarbonImmutable::parse((string) ($line['due_date'] ?? '')),
            );
        }

        return $out;
    }

    public function isRebuild(): bool
    {
        return $this->isMethod('PUT') || $this->isMethod('PATCH');
    }
}
