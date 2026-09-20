<?php

declare(strict_types=1);

namespace App\Models\Collaborator;

use App\Enums\ReconciliationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The proof that a wallet equalled its ledger, on a given day (finance spine §2.16, requirement §50).
 *
 * §50 asks that totals not rely on a stored balance — that they be **reproducible**. This row is what
 * turns that from an intention into a dated record an auditor can read: the figures re-derived from the
 * ledger beside the figures the wallet was holding, the drift between them, and whether anything was
 * repaired.
 *
 * **A repair rewrites the cache only, never the ledger.** When the two disagree the ledger is right by
 * definition — it is the thing the cache is a cache of — and a "repair" that touched a commission entry
 * would be the system quietly deciding what somebody earned.
 *
 * Written once and never edited: `$timestamps` is off, there is no `updated_at` and no `deleted_at`.
 * A proof that can be amended is not a proof.
 *
 * @property string $drift_total
 * @property ReconciliationStatus $status
 */
class CollaboratorWalletReconciliation extends Model
{
    protected $table = 'collaborator_wallet_reconciliations';

    /**
     * No `updated_at` on this table: the row is a statement about a moment, and a moment does not get
     * edited.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        $casts = [
            'collaborator_id' => 'integer',
            'collaborator_wallet_id' => 'integer',
            'as_of' => 'immutable_datetime',
            'expected_entry_count' => 'integer',
            'stored_entry_count' => 'integer',
            'drift_total' => 'decimal:2',
            'identity_holds' => 'boolean',
            'payout_cross_check' => 'decimal:2',
            'allocation_mismatch_count' => 'integer',
            'orphan_allocation_count' => 'integer',
            'reversal_group_mismatch_count' => 'integer',
            'bucket_crossfoot_diff' => 'decimal:2',
            'last_entry_id' => 'integer',
            'status' => ReconciliationStatus::class,
            'repaired' => 'boolean',
            'repaired_at' => 'immutable_datetime',
            'repaired_by' => 'integer',
            'details' => 'array',
            'duration_ms' => 'integer',
            'checked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];

        foreach (self::BUCKETS as $bucket) {
            $casts['expected_'.$bucket] = 'decimal:2';
            $casts['stored_'.$bucket] = 'decimal:2';
        }

        return $casts;
    }

    /**
     * The nine buckets each run compares. Named once, so the casts, the diff and any screen that
     * renders them all walk the same list.
     *
     * @var list<string>
     */
    public const BUCKETS = [
        'pending', 'available', 'reserved', 'paid', 'lifetime',
        'student', 'project', 'adjustments', 'reversed',
    ];

    public function moduleSlug(): string
    {
        return 'collaborator_wallets';
    }

    /*
    |--------------------------------------------------------------------------
    | What the run found
    |--------------------------------------------------------------------------
    */

    /**
     * Did everything line up? Not just the money: a drift of zero with a row count that disagrees means
     * an entry vanished and its sum happened to cancel, which is worse than a visible difference.
     *
     * Deliberately **not** called `isClean()` — Eloquent already owns that name for dirty tracking, and
     * a model method that shadows a framework one is a bug waiting for whoever reads the call site.
     */
    public function matchesLedger(): bool
    {
        return bccomp((string) $this->drift_total, '0.00', 2) === 0
            && $this->identity_holds
            && bccomp((string) $this->payout_cross_check, '0.00', 2) === 0
            && $this->allocation_mismatch_count === 0
            && $this->orphan_allocation_count === 0
            && $this->reversal_group_mismatch_count === 0
            && bccomp((string) $this->bucket_crossfoot_diff, '0.00', 2) === 0
            && $this->expected_entry_count === $this->stored_entry_count;
    }

    /**
     * Each bucket where the two figures differ, with both numbers — what a drift screen renders.
     *
     * @return array<string, array{expected: string, stored: string, difference: string}>
     */
    public function differences(): array
    {
        $rows = [];

        foreach (self::BUCKETS as $bucket) {
            $expected = (string) $this->getAttribute('expected_'.$bucket);
            $stored = (string) $this->getAttribute('stored_'.$bucket);

            if (bccomp($expected, $stored, 2) === 0) {
                continue;
            }

            $rows[$bucket] = [
                'expected' => $expected,
                'stored' => $stored,
                'difference' => bcsub($stored, $expected, 2),
            ];
        }

        return $rows;
    }

    /**
     * The one-line summary for an alert: what disagreed, not merely that something did.
     */
    public function summary(): string
    {
        if ($this->matchesLedger()) {
            return 'The wallet matches the ledger.';
        }

        $parts = [];

        foreach ($this->differences() as $bucket => $diff) {
            $parts[] = sprintf('%s is out by %s', $bucket, $diff['difference']);
        }

        if ($this->expected_entry_count !== $this->stored_entry_count) {
            $parts[] = sprintf('%d entries expected, %d counted',
                $this->expected_entry_count, $this->stored_entry_count);
        }

        if (! $this->identity_holds) {
            $parts[] = 'the buckets do not add up to lifetime earned';
        }

        if ($this->orphan_allocation_count > 0) {
            $parts[] = sprintf('%d allocation(s) on an entry that cannot be paid', $this->orphan_allocation_count);
        }

        return ucfirst(implode('; ', $parts)).'.';
    }

    public function scopeDrifting(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ReconciliationStatus::Drift->value,
            ReconciliationStatus::Failed->value,
        ]);
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class, 'collaborator_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CollaboratorWallet::class, 'collaborator_wallet_id');
    }

    public function repairedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repaired_by');
    }
}
