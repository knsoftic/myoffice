<?php

declare(strict_types=1);

namespace App\DataObjects\Collaborator;

use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryPurpose;
use Illuminate\Http\Request;

/**
 * What the statement screen narrowed the movements to (phase-10-12 §8.7).
 *
 * A filtered statement is still a statement: the opening balance, the closing balance and the proof
 * footer are always computed over **everything**, and the filters only decide which movement rows are
 * listed. Filtering the balances too would produce a document that says 18,400.00 at the bottom of a
 * page whose rows add to something else, which is the one thing §56 asks the statement never to do.
 */
final readonly class StatementFilters
{
    public function __construct(
        public ?int $studentId = null,
        public ?int $projectId = null,
        public ?string $sourceType = null,
        public ?LedgerEntryPurpose $purpose = null,
        public ?CommissionStatus $status = null,
        public ?string $search = null,
        /**
         * `write_off` and `manual_adjustment` are bookkeeping, not earning: a partner reading their
         * statement did not do anything that caused them. Default from
         * `collaborator.statement_show_technical_rows`.
         */
        public bool $showTechnicalRows = false,
        /** Payout rows can be hidden for a "what did I earn" view; the totals never change. */
        public bool $showPayouts = true,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            studentId: $request->filled('student') ? (int) $request->input('student') : null,
            projectId: $request->filled('project') ? (int) $request->input('project') : null,
            sourceType: $request->filled('source') ? (string) $request->input('source') : null,
            purpose: LedgerEntryPurpose::tryFrom((string) $request->input('purpose', '')),
            status: CommissionStatus::tryFrom((string) $request->input('status', '')),
            search: $request->filled('q') ? trim((string) $request->input('q')) : null,
            showTechnicalRows: $request->boolean('technical',
                (bool) setting('collaborator.statement_show_technical_rows', false)),
            showPayouts: ! $request->has('payouts') || $request->boolean('payouts'),
        );
    }

    /**
     * Is anything actually narrowed? The screen says so above the table, because a subtotal that looks
     * wrong is usually a filter somebody forgot was on.
     */
    public function any(): bool
    {
        return $this->studentId !== null
            || $this->projectId !== null
            || $this->sourceType !== null
            || $this->purpose !== null
            || $this->status !== null
            || ($this->search !== null && $this->search !== '')
            || ! $this->showPayouts;
    }

    /**
     * The query string that reproduces this view — what the PDF footer prints as "the filter in force"
     * and what the CSV link carries.
     *
     * @return array<string, string>
     */
    public function queryParameters(): array
    {
        return array_filter([
            'student' => $this->studentId === null ? null : (string) $this->studentId,
            'project' => $this->projectId === null ? null : (string) $this->projectId,
            'source' => $this->sourceType,
            'purpose' => $this->purpose?->value,
            'status' => $this->status?->value,
            'q' => $this->search,
            'technical' => $this->showTechnicalRows ? '1' : null,
            'payouts' => $this->showPayouts ? null : '0',
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
