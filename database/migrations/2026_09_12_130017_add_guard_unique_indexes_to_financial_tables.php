<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 17 — the eight guard indexes (phase-10-12 §2.2).
 *
 * Each of these needs a generated column from file 16, which is why it is its own migration and why
 * `down()` here must run **before** file 16's: MariaDB refuses to drop a stored column while an index
 * still uses it.
 *
 * What each one buys:
 *
 *   · `uq_cr_*_current` (four) — **at most one active referral per subject, for the life of the
 *     database** (INV-18). A race between a `?ref=` URL and a receptionist's manual selection resolves
 *     to exactly one attribution, and the loser becomes a superseded row with its reason.
 *   · `uq_ccs_open` — at most one open-ended rule version per collaborator per scope, so the resolver
 *     can never find two candidates for one date.
 *   · `uq_cce_current` — at most one current entitlement per document per collaborator, so two
 *     concurrent first payments on the same admission cannot promise a fixed amount twice.
 *   · `idx_cpa_entry_active` — not a guard but the hot lookup: "how much of this entry is still
 *     claimed", on every payout build.
 *   · `uq_cpacc_default` — exactly one default payout account per collaborator, enforced by the
 *     database instead of a clear-all-others loop that can half-fail.
 *
 * **`collaborator_referrals.superseded_by_id` is deliberately absent from this list** (ND-12). It
 * carries the plain `idx_cr_superseded_by` created with the ordinary indexes, because one winner may
 * legitimately supersede several rows — `change()`'s predecessor plus one per losing candidate — and a
 * unique index there would 1062 on the second of those legal writes.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: list<string>}>
     */
    private const GUARDS = [
        ['collaborator_referrals', 'uq_cr_student_current', ['student_id', 'current_guard']],
        ['collaborator_referrals', 'uq_cr_project_current', ['project_id', 'current_guard']],
        ['collaborator_referrals', 'uq_cr_client_current', ['client_id', 'current_guard']],
        ['collaborator_referrals', 'uq_cr_lead_current', ['lead_id', 'current_guard']],
        ['collaborator_commission_settings', 'uq_ccs_open', ['collaborator_id', 'commission_for', 'open_guard']],
        ['collaborator_commission_entitlements', 'uq_cce_current', ['document_key', 'collaborator_id', 'current_guard']],
        ['collaborator_payout_accounts', 'uq_cpacc_default', ['default_guard']],
    ];

    public function up(): void
    {
        foreach (self::GUARDS as [$table, $name, $columns]) {
            if (! Schema::hasTable($table) || RawSchema::indexExists($table, $name)) {
                continue;
            }

            RawSchema::uniqueIndex($table, $name, $columns);
        }

        if (Schema::hasTable('collaborator_payout_allocations')
            && ! RawSchema::indexExists('collaborator_payout_allocations', 'idx_cpa_entry_active')) {
            RawSchema::index('collaborator_payout_allocations', 'idx_cpa_entry_active',
                ['ledger_entry_id', 'active_guard']);
        }
    }

    public function down(): void
    {
        RawSchema::dropIndex('collaborator_payout_allocations', 'idx_cpa_entry_active');

        foreach (array_reverse(self::GUARDS) as [$table, $name]) {
            RawSchema::dropIndex($table, $name);
        }
    }
};
