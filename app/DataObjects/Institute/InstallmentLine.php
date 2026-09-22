<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use Carbon\CarbonImmutable;

/**
 * One line of an installment plan, before it is a row (phase-18 §6.2).
 *
 * `InstallmentPlanCalculator` returns these; `StudentFeeService` turns them into
 * `student_fee_installments`. Keeping the arithmetic's output in a readonly object rather than an
 * associative array is what lets the plan wizard's preview and the plan the service actually writes be
 * produced by the same call — a preview computed a second way is a preview that can disagree.
 *
 * `$amount` is a `Money` string, never a float, and `$number` is the `installment_no` the line will
 * carry. On a rebuild the numbers continue from `MAX + 1` rather than restarting at 1 ([D18-6], D50):
 * "the third installment" has to keep meaning one thing for as long as a fee dispute can run.
 */
final readonly class InstallmentLine
{
    public function __construct(
        public int $number,
        public string $amount,
        public CarbonImmutable $dueDate,
        /** Set only when the line already exists — a rebuild keeps paid lines where they are. */
        public ?int $id = null,
    ) {}

    /**
     * The shape the plan wizard posts back and the Form Request validates.
     *
     * @return array{installment_no: int, amount: string, due_date: string, id: int|null}
     */
    public function toArray(): array
    {
        return [
            'installment_no' => $this->number,
            'amount' => $this->amount,
            'due_date' => $this->dueDate->toDateString(),
            'id' => $this->id,
        ];
    }

    public function withAmount(string $amount): self
    {
        return new self($this->number, $amount, $this->dueDate, $this->id);
    }
}
