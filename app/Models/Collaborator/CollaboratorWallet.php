<?php

declare(strict_types=1);

namespace App\Models\Collaborator;

use App\Enums\ReconciliationStatus;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A pure cache of the ledger (finance spine §2.12, requirement §50).
 *
 * **It holds no truth of its own.** Every column here is defined by an exact SQL expression over
 * `collaborator_commission_ledger_entries` and is proven nightly; the table exists so a dashboard does
 * not aggregate a million rows to draw one number. `CLAUDE.md` §5 states the rule the whole design rests
 * on — the balances must always be re-derivable by summing the ledger — and
 * `CollaboratorWalletService::assertConsistent()` is what turns that into a test that can fail.
 *
 * **Not a `FinancialRow`.** A derived row is rebuilt rather than protected: `recalculate()` rewrites it
 * from the ledger whenever the two disagree. Guarding it against writes would make the repair path
 * impossible while protecting nothing, because the ledger is where the truth is.
 *
 * `available_balance` **may be negative** after a clawback — the partner genuinely owes money back — and
 * there is deliberately no CHECK on it: a recoverable accounting position must not become a 500 in the
 * middle of a refund. The payout guard refuses to *spend* a negative balance, which is a different
 * thing from refusing to record one.
 *
 * @property string $available_balance
 * @property string $pending_balance
 * @property ReconciliationStatus $reconciliation_status
 */
class CollaboratorWallet extends Model
{
    use Blameable;
    use LogsActivityWithContext;

    protected $table = 'collaborator_wallets';

    /**
     * Nothing. Every figure is written by `CollaboratorWalletService` under this row's lock.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collaborator_id' => 'integer',
            'pending_balance' => 'decimal:2',
            'available_balance' => 'decimal:2',
            'reserved_balance' => 'decimal:2',
            'paid_balance' => 'decimal:2',
            'lifetime_earned' => 'decimal:2',
            'total_student_commission' => 'decimal:2',
            'total_project_commission' => 'decimal:2',
            'total_adjustments' => 'decimal:2',
            'total_reversed' => 'decimal:2',
            'total_paid_out' => 'decimal:2',
            'ledger_entry_count' => 'integer',
            'last_entry_id' => 'integer',
            'last_entry_at' => 'immutable_datetime',
            'version' => 'integer',
            'is_frozen' => 'boolean',
            'recalculated_at' => 'immutable_datetime',
            'last_reconciled_at' => 'immutable_datetime',
            'reconciliation_status' => ReconciliationStatus::class,
            'drift_amount' => 'decimal:2',
        ];
    }

    public function moduleSlug(): string
    {
        return 'collaborator_wallets';
    }

    protected function activityModule(): ?string
    {
        return 'collaborator_wallets';
    }

    /*
    |--------------------------------------------------------------------------
    | Questions a payout asks
    |--------------------------------------------------------------------------
    */

    /**
     * May this wallet fund a withdrawal at all?
     *
     * A freeze blocks payouts without blocking earning — the partner keeps accruing while whatever
     * prompted the freeze is sorted out, which is the point of it being two separate states.
     */
    public function canFundAPayout(): bool
    {
        return ! $this->is_frozen && bccomp((string) $this->available_balance, '0.00', 2) === 1;
    }

    /**
     * Does the partner owe money back? True after a clawback larger than what was left available.
     */
    public function isInDebit(): bool
    {
        return bccomp((string) $this->available_balance, '0.00', 2) === -1;
    }

    /**
     * Are the cached figures safe to put on a screen?
     *
     * When they are not, the screens render the **derived** figures behind a banner rather than these:
     * a number nobody can reproduce is worse than a number that admits it is wrong.
     */
    public function isTrustworthy(): bool
    {
        return $this->reconciliation_status->isHealthy();
    }

    /**
     * R2, the identity the reconciler asserts: `lifetime = pending + available + reserved + paid`.
     *
     * Lives here rather than only in the reconciler so a test, a screen and the nightly job all ask the
     * same question in the same words.
     */
    public function identityHolds(): bool
    {
        $parts = bcadd(
            bcadd((string) $this->pending_balance, (string) $this->available_balance, 2),
            bcadd((string) $this->reserved_balance, (string) $this->paid_balance, 2),
            2,
        );

        return bccomp($parts, (string) $this->lifetime_earned, 2) === 0;
    }

    public function scopeDrifting(Builder $query): Builder
    {
        return $query->whereIn('reconciliation_status', [
            ReconciliationStatus::Drift->value,
            ReconciliationStatus::Failed->value,
        ]);
    }

    public function scopeWithBalance(Builder $query): Builder
    {
        return $query->where('available_balance', '>', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (spine §2.12)
    |--------------------------------------------------------------------------
    */

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CollaboratorCommissionLedgerEntry::class, 'collaborator_wallet_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(CollaboratorWalletReconciliation::class, 'collaborator_wallet_id');
    }

    public function latestReconciliation(): HasOne
    {
        return $this->hasOne(CollaboratorWalletReconciliation::class, 'collaborator_wallet_id')
            ->latestOfMany('checked_at');
    }
}
