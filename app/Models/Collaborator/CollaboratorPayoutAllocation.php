<?php

declare(strict_types=1);

namespace App\Models\Collaborator;

use App\Enums\AllocationReleaseReason;
use App\Enums\CommissionStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\FinancialRow;
use App\Models\Concerns\HasGeneratedColumns;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slice of one earning, claimed by one payout (finance spine §2.14).
 *
 * **The difference between "we paid him 20,000" and "we paid him exactly these commissions."** That
 * distinction is what makes a 20,000 payout against a single 50,000 entry expressible, what makes a
 * clawback traceable to the transfer that carried it, and what makes "a commission is never paid twice"
 * a database guarantee rather than a service convention.
 *
 * **An allocation is released, never deleted.** A rejected or cancelled payout hands the money back by
 * setting `is_released` with a reason; the row stays. Deleting it would make the wallet's arithmetic
 * come out right while destroying the only evidence that the money was ever reserved — and "my balance
 * moved and nobody can tell me why" is the exact failure this table exists to prevent.
 *
 * `uq_cpa_pair` has **no** active guard, deliberately: the same entry never re-enters the same payout,
 * released or not. A released earning is claimed by a *different* payout.
 *
 * @property string $amount
 * @property AllocationReleaseReason|null $release_reason
 */
class CollaboratorPayoutAllocation extends Model
{
    use Blameable;
    use FinancialRow;
    use HasGeneratedColumns;
    use LogsActivityWithContext;

    protected $table = 'collaborator_payout_allocations';

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payout_id' => 'integer',
            'ledger_entry_id' => 'integer',
            'collaborator_id' => 'integer',
            'amount' => 'decimal:2',
            'entry_transaction_date' => 'immutable_date',
            'entry_status_at_allocation' => CommissionStatus::class,
            'is_released' => 'boolean',
            'released_at' => 'immutable_datetime',
            'release_reason' => AllocationReleaseReason::class,
            'released_by' => 'integer',
        ];
    }

    /**
     * Carries `idx_cpa_entry_active`, the hot "how much of this entry is still claimed" lookup.
     *
     * @return list<string>
     */
    public function generatedColumns(): array
    {
        return ['active_guard'];
    }

    /**
     * Append-only: there is no `updated_by` column, because there is no update to attribute.
     *
     * The one field that ever changes on an allocation is its release, and that is recorded by
     * `released_by` / `released_at` / `release_reason` — three columns that say *what* changed and not
     * merely that somebody touched the row. A generic `updated_by` beside them would be the weaker of
     * two records of the same fact.
     */
    protected static function updatedByColumn(): ?string
    {
        return null;
    }

    public function moduleSlug(): string
    {
        return 'collaborator_payouts';
    }

    protected function activityModule(): ?string
    {
        return 'collaborator_payouts';
    }

    /**
     * Only the release. The amount, the entry and the two snapshots are what the allocation **is**;
     * changing one of them would rewrite which commission a transfer actually carried.
     *
     * @return list<string>
     */
    protected function mutableColumns(): array
    {
        return ['is_released', 'released_at', 'release_reason', 'release_note', 'released_by'];
    }

    protected function owningService(): string
    {
        return 'App\\Services\\Collaborator\\PayoutService';
    }

    protected function noDeleteMessage(): string
    {
        return 'An allocation is released with a reason, never deleted: it is the only evidence that '
            .'this earning was ever reserved for this payout.';
    }

    /**
     * Is this allocation still holding the earning?
     */
    public function isLive(): bool
    {
        return ! $this->is_released;
    }

    /**
     * Does releasing this hand the earning back to available?
     *
     * A reversal is the one release that does not: the commission itself was undone, so there is
     * nothing to return.
     */
    public function returnsToAvailable(): bool
    {
        return $this->is_released && ($this->release_reason?->returnsToAvailable() ?? false);
    }

    /**
     * Live allocations only — the ones every balance and cross-check counts (INV-23).
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_released', false);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(CollaboratorPayout::class, 'payout_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(CollaboratorCommissionLedgerEntry::class, 'ledger_entry_id');
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
