<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\DataObjects\Collaborator\StatementData;
use App\DataObjects\Collaborator\StatementFilters;
use App\DataObjects\Collaborator\StatementLine;
use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryPurpose;
use App\Enums\PayoutStatus;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorCommissionLedgerEntry;
use App\Models\Collaborator\CollaboratorPayout;
use App\Support\DateRange;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * A partner's statement, and the proof that it balances (spine §6.5.5, requirement §56).
 *
 * **Movement-based, and therefore reproducible.** Every figure keys off `transaction_date` and
 * `paid_on` — business dates — never off a status that can still change and never off `now()`. A closed
 * month re-issued a year later is the same document, and when it is not, `posted_at` on the entry says
 * why: a back-dated receipt genuinely landed in that month after the fact.
 *
 * **It refuses to render an unbalanced statement.** `opening + credits − debits − payouts = closing` is
 * checked against the same expression evaluated as an opening balance at `to + 1 day` — two different
 * queries that must agree — and a mismatch throws. Handing a partner a document whose footer disagrees
 * with its own rows gives them no way to tell which number to believe, and every subsequent
 * conversation starts from that.
 *
 * **Filters narrow the rows, never the balances.** A filtered statement still opens and closes where
 * the unfiltered one does; the running balance on each visible row is its true running balance, taken
 * from the full ordered list before filtering. Otherwise a statement with "student 123" selected would
 * print a closing balance that is neither the partner's balance nor the sum of what is on the page.
 */
class CollaboratorStatementService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Build the statement for `[from, to]`, asserting the §6.5.5 identity before returning.
     */
    public function build(Collaborator $collaborator, DateRange $range, ?StatementFilters $filters = null): StatementData
    {
        $filters ??= StatementFilters::none();

        $id = (int) $collaborator->getKey();
        $from = $range->start()->toDateString();
        $to = $range->end()->toDateString();

        $opening = $this->openingAt($id, $from);
        $credits = $this->creditsBetween($id, $from, $to);
        $debits = $this->debitsBetween($id, $from, $to);
        $payouts = $this->payoutsBetween($id, $from, $to);

        $closing = Money::sub(Money::sub(Money::add($opening, $credits), $debits), $payouts);

        $this->assertBalances($collaborator, $range, $opening, $credits, $debits, $payouts, $closing);

        // The running balance is computed over **every** movement, then the filter is applied. A
        // balance computed after filtering would be a different number wearing the same column heading.
        $all = $this->runningBalances($this->movements($id, $from, $to), $opening);

        $visible = array_values(array_filter($all, fn (StatementLine $line): bool => $this->passes($line, $filters)));

        return new StatementData(
            collaboratorId: $id,
            range: $range,
            opening: $opening,
            credits: $credits,
            debits: $debits,
            payouts: $payouts,
            closing: $closing,
            lines: $visible,
            subtotals: $this->subtotals($all),
            filters: $filters,
            isFiltered: count($visible) !== count($all),
        );
    }

    /**
     * Total commission **accrued** over a range — earnings net of reversals and clawbacks (F-4.8).
     *
     * One `SUM(signed_amount)` over every non-cancelled row in the window, which is exactly §6.5.5's
     * `credits − debits`: the figure a report prints is therefore the figure the statement balances to,
     * rather than a second number computed a second way about the same money.
     *
     * **`$collaborator = null` means company-wide (ND-6)** — the identical SQL with the id predicate
     * dropped, one query and never a loop, which is what makes the company figure *equal* to the sum of
     * the per-partner figures rather than merely close to it. The first parameter has no default so
     * that company-wide is always something a caller typed, and a null collaborator requires a range.
     *
     * Nothing outside this class and `CollaboratorWalletService` may sum the ledger (INV-26).
     */
    public function commissionAccruedTotal(?Collaborator $collaborator, ?DateRange $range = null): string
    {
        if ($collaborator === null && $range === null) {
            throw new LogicException(
                'commissionAccruedTotal(null, null) would sum every commission the business has ever '
                .'accrued, which is never the question being asked. Name a collaborator, a range, or both.'
            );
        }

        $query = $this->db->table('collaborator_commission_ledger_entries')
            ->whereNot('status', CommissionStatus::Cancelled->value);

        if ($collaborator !== null) {
            $query->where('collaborator_id', $collaborator->getKey());
        }

        if ($range !== null) {
            // §6.5.5 dates on `transaction_date` — the business date — so a back-dated receipt lands in
            // the period it belongs to and not in the one it was keyed in.
            $query->whereBetween($this->db->raw('CAST(transaction_date AS CHAR)'), [
                $range->start()->toDateString(),
                $range->end()->toDateString(),
            ]);
        }

        return Money::of((string) ($query->sum('signed_amount') ?: Money::ZERO));
    }

    /*
    |--------------------------------------------------------------------------
    | The five figures of §6.5.5
    |--------------------------------------------------------------------------
    */

    /**
     * Everything that had happened before `$date`: the ledger net of itself, less what was paid out.
     *
     * Called twice per statement with two different dates — once for the opening balance and once, at
     * `to + 1 day`, as the independent check on the closing balance.
     *
     * **Protected, and deliberately so.** The §6.5.5 identity is held up by {@see CREDIT_PURPOSES} and
     * {@see DEBIT_PURPOSES} covering every case of `LedgerEntryPurpose`, and `chk_cle_sign` refuses any
     * other value — so with the schema intact the two sides cannot disagree, and the guard could never
     * be seen to fire. FT-44 perturbs this one method to prove it does.
     */
    protected function openingAt(int $collaboratorId, string $date): string
    {
        $ledger = $this->db->table('collaborator_commission_ledger_entries')
            ->where('collaborator_id', $collaboratorId)
            ->whereNot('status', CommissionStatus::Cancelled->value)
            ->whereRaw('CAST(transaction_date AS CHAR) < ?', [$date])
            ->sum('signed_amount');

        $paid = $this->db->table('collaborator_payouts')
            ->where('collaborator_id', $collaboratorId)
            ->where('status', PayoutStatus::Paid->value)
            ->whereRaw('CAST(paid_on AS CHAR) < ?', [$date])
            ->sum('amount');

        return Money::sub(
            Money::of((string) ($ledger ?: Money::ZERO)),
            Money::of((string) ($paid ?: Money::ZERO)),
        );
    }

    /**
     * The purposes that add to a balance, and the purposes that take away from it.
     *
     * **Between them they must cover every case of `LedgerEntryPurpose`.** The statement's proof is
     * that the window's movements plus the prior balance equal the balance re-derived one day later,
     * and the re-derivation sums the ledger without looking at purpose at all. A seventh purpose that
     * appeared in neither list would be counted by one side and not the other, and the statement would
     * refuse to render — which is what FT-44's coverage test exists to catch first.
     *
     * @var list<LedgerEntryPurpose>
     */
    public const CREDIT_PURPOSES = [
        LedgerEntryPurpose::StudentCommission,
        LedgerEntryPurpose::ProjectCommission,
        LedgerEntryPurpose::ManualAdjustment,
        LedgerEntryPurpose::WriteOff,
    ];

    /** @var list<LedgerEntryPurpose> */
    public const DEBIT_PURPOSES = [
        LedgerEntryPurpose::Reversal,
        LedgerEntryPurpose::Clawback,
    ];

    private function creditsBetween(int $collaboratorId, string $from, string $to): string
    {
        $total = $this->entriesBetween($collaboratorId, $from, $to)
            ->whereIn('purpose', array_map(static fn (LedgerEntryPurpose $p): string => $p->value, self::CREDIT_PURPOSES))
            ->sum('signed_amount');

        return Money::of((string) ($total ?: Money::ZERO));
    }

    /**
     * Reversals and clawbacks, as **positive magnitudes**: the statement prints them in a debit column
     * and subtracts them, so a negative number here would be subtracted twice.
     */
    private function debitsBetween(int $collaboratorId, string $from, string $to): string
    {
        $total = $this->entriesBetween($collaboratorId, $from, $to)
            ->whereIn('purpose', array_map(static fn (LedgerEntryPurpose $p): string => $p->value, self::DEBIT_PURPOSES))
            ->sum('amount');

        return Money::of((string) ($total ?: Money::ZERO));
    }

    private function payoutsBetween(int $collaboratorId, string $from, string $to): string
    {
        $total = $this->db->table('collaborator_payouts')
            ->where('collaborator_id', $collaboratorId)
            ->where('status', PayoutStatus::Paid->value)
            ->whereBetween($this->db->raw('CAST(paid_on AS CHAR)'), [$from, $to])
            ->sum('amount');

        return Money::of((string) ($total ?: Money::ZERO));
    }

    private function entriesBetween(int $collaboratorId, string $from, string $to)
    {
        return $this->db->table('collaborator_commission_ledger_entries')
            ->where('collaborator_id', $collaboratorId)
            ->whereNot('status', CommissionStatus::Cancelled->value)
            ->whereBetween($this->db->raw('CAST(transaction_date AS CHAR)'), [$from, $to]);
    }

    /**
     * The §6.5.5 assertion: the closing balance computed from movements must equal the balance computed
     * as an opening balance one day later.
     *
     * Two genuinely different queries — one sums the window and adds it to a prior balance, the other
     * sums everything before a date — so agreement is evidence rather than a tautology.
     */
    private function assertBalances(
        Collaborator $collaborator,
        DateRange $range,
        string $opening,
        string $credits,
        string $debits,
        string $payouts,
        string $closing,
    ): void {
        $independent = $this->openingAt(
            (int) $collaborator->getKey(),
            $range->end()->addDay()->toDateString(),
        );

        if (Money::compare($closing, $independent) === 0) {
            return;
        }

        throw new LogicException(sprintf(
            'The statement for collaborator #%s over %s to %s does not balance: %s + %s − %s − %s = %s, '
            .'but the balance re-derived at %s is %s (out by %s). Nothing was rendered — a statement '
            .'whose footer disagrees with its own rows gives the partner no way to tell which number to '
            .'believe.',
            (string) $collaborator->getKey(),
            $range->start()->toDateString(),
            $range->end()->toDateString(),
            $opening, $credits, $debits, $payouts, $closing,
            $range->end()->addDay()->toDateString(),
            $independent,
            Money::sub($closing, $independent),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | The rows
    |--------------------------------------------------------------------------
    */

    /**
     * Every movement in the window, in business-date order.
     *
     * Ledger entries and payouts are two tables with two different date columns, merged and sorted
     * here rather than in SQL: a UNION would have to agree on a column list, and the two rows genuinely
     * carry different things (a rate and a base on one, a method and a reference on the other).
     *
     * @return list<StatementLine>
     */
    private function movements(int $collaboratorId, string $from, string $to): array
    {
        $lines = [];

        $entries = CollaboratorCommissionLedgerEntry::query()
            ->where('collaborator_id', $collaboratorId)
            ->whereNot('status', CommissionStatus::Cancelled->value)
            ->whereBetween($this->db->raw('CAST(transaction_date AS CHAR)'), [$from, $to])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $isDebit = $entry->purpose->isUndo();

            $lines[] = new StatementLine(
                kind: 'entry',
                id: (int) $entry->getKey(),
                date: CarbonImmutable::parse($entry->transaction_date->toDateString()),
                reference: (string) $entry->reference,
                description: $this->describe($entry),
                purpose: $entry->purpose,
                rate: $entry->commission_rate === null ? null : (string) $entry->commission_rate,
                base: $entry->base_amount === null ? null : (string) $entry->base_amount,
                credit: $isDebit ? Money::ZERO : Money::max(Money::ZERO, (string) $entry->signed_amount),
                debit: $isDebit ? Money::of((string) $entry->amount) : Money::ZERO,
                link: ['entry' => (int) $entry->getKey()],
                status: $entry->status->label(),
            );
        }

        $payouts = CollaboratorPayout::query()
            ->where('collaborator_id', $collaboratorId)
            ->where('status', PayoutStatus::Paid->value)
            ->whereBetween($this->db->raw('CAST(paid_on AS CHAR)'), [$from, $to])
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get();

        foreach ($payouts as $payout) {
            $lines[] = new StatementLine(
                kind: 'payout',
                id: (int) $payout->getKey(),
                date: CarbonImmutable::parse($payout->paid_on->toDateString()),
                reference: (string) $payout->payout_no,
                description: sprintf('Paid by %s%s', $payout->method->label(),
                    $payout->transaction_id === null ? '' : ' — '.$payout->transaction_id),
                purpose: null,
                rate: null,
                base: null,
                credit: Money::ZERO,
                debit: Money::of((string) $payout->amount),
                link: ['payout' => (int) $payout->getKey()],
                status: $payout->status->label(),
            );
        }

        usort($lines, static function (StatementLine $a, StatementLine $b): int {
            $byDate = $a->date <=> $b->date;

            if ($byDate !== 0) {
                return $byDate;
            }

            // An earning and the payout that settled it can share a date. The earning is listed first,
            // so the running balance never shows money being paid out before it existed.
            return [$a->kind === 'payout', $a->id] <=> [$b->kind === 'payout', $b->id];
        });

        return $lines;
    }

    /**
     * One pass over the ordered lines: the running balance after each, starting from the opening.
     *
     * @param  list<StatementLine>  $lines
     * @return list<StatementLine>
     */
    private function runningBalances(array $lines, string $opening): array
    {
        $balance = $opening;
        $out = [];

        foreach ($lines as $line) {
            $balance = Money::add($balance, $line->movement());
            $out[] = $line->withBalance($balance);
        }

        return $out;
    }

    /**
     * @param  list<StatementLine>  $lines
     * @return array<string, string>
     */
    private function subtotals(array $lines): array
    {
        $totals = array_fill_keys(StatementData::GROUPS, Money::ZERO);

        foreach ($lines as $line) {
            $group = $line->group();

            // Reversals and payouts subtotal as positive magnitudes: they are printed under a heading
            // that already says they come off, and a negative under a "less:" heading reads as a credit.
            $totals[$group] = Money::add($totals[$group], in_array($group, ['reversals', 'payouts'], true)
                ? $line->debit
                : $line->movement());
        }

        return $totals;
    }

    private function passes(StatementLine $line, StatementFilters $filters): bool
    {
        if ($line->kind === 'payout') {
            return $filters->showPayouts
                && $filters->studentId === null
                && $filters->projectId === null
                && $filters->purpose === null
                && $filters->sourceType === null;
        }

        if (! $filters->showTechnicalRows && $line->isTechnical()) {
            return false;
        }

        if ($filters->purpose !== null && $line->purpose !== $filters->purpose) {
            return false;
        }

        if ($filters->search !== null && $filters->search !== ''
            && ! str_contains(mb_strtolower($line->reference.' '.$line->description), mb_strtolower($filters->search))) {
            return false;
        }

        if ($filters->studentId === null && $filters->projectId === null
            && $filters->sourceType === null && $filters->status === null) {
            return true;
        }

        return $this->entryPasses($line, $filters);
    }

    /**
     * The filters that need the entry itself. Loaded once per line and only when one of them is set,
     * so the common unfiltered statement does no extra query at all.
     */
    private function entryPasses(StatementLine $line, StatementFilters $filters): bool
    {
        $entry = CollaboratorCommissionLedgerEntry::query()->find($line->id);

        if ($entry === null) {
            return false;
        }

        return ($filters->studentId === null || (int) $entry->student_id === $filters->studentId)
            && ($filters->projectId === null || (int) $entry->project_id === $filters->projectId)
            && ($filters->sourceType === null || $entry->source_type?->value === $filters->sourceType)
            && ($filters->status === null || $entry->status === $filters->status);
    }

    /**
     * What the description column says.
     *
     * The entry's own `notes` wins when it has any — a reversal carries the reason the refund was
     * given, and that sentence is the whole point of the row being visible to the partner at all.
     */
    private function describe(CollaboratorCommissionLedgerEntry $entry): string
    {
        $notes = trim((string) $entry->notes);

        if ($notes !== '') {
            return $notes;
        }

        return match ($entry->purpose) {
            LedgerEntryPurpose::StudentCommission => $entry->student_fee_id === null
                ? 'Student commission'
                : sprintf('Student commission on fee #%d', (int) $entry->student_fee_id),
            LedgerEntryPurpose::ProjectCommission => $entry->project_id === null
                ? 'Project commission'
                : sprintf('Project commission on project #%d', (int) $entry->project_id),
            LedgerEntryPurpose::Reversal => 'Reversal of '.($entry->original?->reference ?? 'an earlier commission'),
            LedgerEntryPurpose::Clawback => 'Clawback of '.($entry->original?->reference ?? 'an earlier commission'),
            default => $entry->purpose->label(),
        };
    }
}
