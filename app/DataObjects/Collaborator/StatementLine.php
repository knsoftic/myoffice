<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Enums\LedgerEntryPurpose;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One row of a partner's statement (phase-10-12 §8.7).
 *
 * Four presenters render these and nothing else — the screen, the print view, the PDF and the CSV
 * ([D-IMP-7]) — which is what makes FT-44's "the four totals agree to the paisa" true by construction
 * rather than by four careful implementations.
 *
 * `balance` is the running balance **after** this line. It is filled by the builder in one pass over
 * the ordered lines, so it is arithmetic on the list rather than a per-row query, and the last line's
 * balance is the closing balance by definition.
 */
final readonly class StatementLine
{
    /**
     * @param  'entry'|'payout'  $kind
     * @param  array<string, mixed>  $link  route name + parameters, or [] when the viewer may not follow it
     */
    public function __construct(
        public string $kind,
        public int $id,
        public CarbonImmutable $date,
        public string $reference,
        public string $description,
        public ?LedgerEntryPurpose $purpose,
        public ?string $rate,
        public ?string $base,
        public string $credit,
        public string $debit,
        public string $balance = Money::ZERO,
        public array $link = [],
        public ?string $status = null,
    ) {}

    /**
     * The same line with its running balance filled in.
     */
    public function withBalance(string $balance): self
    {
        return new self(
            kind: $this->kind,
            id: $this->id,
            date: $this->date,
            reference: $this->reference,
            description: $this->description,
            purpose: $this->purpose,
            rate: $this->rate,
            base: $this->base,
            credit: $this->credit,
            debit: $this->debit,
            balance: $balance,
            link: $this->link,
            status: $this->status,
        );
    }

    /**
     * What this line does to the balance: credits add, debits and payouts subtract.
     */
    public function movement(): string
    {
        return Money::sub($this->credit, $this->debit);
    }

    /**
     * Which of the five §56 groups this belongs to.
     */
    public function group(): string
    {
        if ($this->kind === 'payout') {
            return 'payouts';
        }

        return match ($this->purpose) {
            LedgerEntryPurpose::StudentCommission => 'student_commissions',
            LedgerEntryPurpose::ProjectCommission => 'project_commissions',
            LedgerEntryPurpose::Reversal, LedgerEntryPurpose::Clawback => 'reversals',
            default => 'adjustments',
        };
    }

    /**
     * Is this one of the rows `collaborator.statement_show_technical_rows` hides? A partner did not do
     * anything that caused a write-off, and seeing one without being told why reads as an error.
     */
    public function isTechnical(): bool
    {
        return in_array($this->purpose, [
            LedgerEntryPurpose::ManualAdjustment,
            LedgerEntryPurpose::WriteOff,
        ], true);
    }

    /**
     * @return array<string, mixed> the CSV row, and the shape the PDF iterates
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'reference' => $this->reference,
            'description' => $this->description,
            'type' => $this->purpose?->label() ?? 'Payout',
            'rate' => $this->rate,
            'base' => $this->base,
            'credit' => Money::isZero($this->credit) ? null : $this->credit,
            'debit' => Money::isZero($this->debit) ? null : $this->debit,
            'balance' => $this->balance,
        ];
    }
}
