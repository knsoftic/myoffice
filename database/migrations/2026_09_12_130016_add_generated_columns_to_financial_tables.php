<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 16 — the eight STORED generated columns (phase-10-12 §2.2).
 *
 * Two jobs, and they are different.
 *
 * **Two are arithmetic the database should own**: `net_received_amount` on both payment tables, and
 * `signed_amount` on the ledger. An income report that sums `net_received_amount` cannot forget the
 * refund, and a balance that sums `signed_amount` cannot forget the direction — because there is no
 * version of the query that omits them.
 *
 * **Six are guard columns**, and they exist for one reason: MariaDB unique indexes ignore NULLs. A
 * column that is the id when a row is "current" and NULL otherwise turns "at most one active referral
 * per subject" — or one open rule, one current entitlement, one default account — into a unique index
 * that superseded rows stack freely underneath. The alternative is a service that checks first and
 * writes second, which is a race with a name.
 *
 * **STORED, never VIRTUAL.** A unique index over a virtual column is legal but recomputed on read, and
 * these are exactly the indexes that have to bite on a concurrent INSERT.
 *
 * `down()` is file 17's inverse partner: MariaDB refuses to drop a stored column while an index uses
 * it, so 17 must roll back before this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- arithmetic the database owns ----------------------------------------------------------
        foreach (['student_fee_payments', 'project_payments'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'net_received_amount')) {
                RawSchema::generatedColumn($table, 'net_received_amount', 'DECIMAL(15,2)',
                    '`amount` - `refunded_amount`');
            }
        }

        if (Schema::hasTable('collaborator_commission_ledger_entries')
            && ! Schema::hasColumn('collaborator_commission_ledger_entries', 'signed_amount')) {
            // The only column anything ever sums.
            RawSchema::generatedColumn('collaborator_commission_ledger_entries', 'signed_amount', 'DECIMAL(15,2)',
                "CASE WHEN `entry_type` = 'credit' THEN `amount` ELSE -`amount` END");
        }

        // --- guard columns --------------------------------------------------------------------------
        if (Schema::hasTable('collaborator_referrals')
            && ! Schema::hasColumn('collaborator_referrals', 'current_guard')) {
            RawSchema::generatedColumn('collaborator_referrals', 'current_guard', 'TINYINT',
                "CASE WHEN `status` = 'active' THEN 1 ELSE NULL END");
        }

        if (Schema::hasTable('collaborator_commission_settings')
            && ! Schema::hasColumn('collaborator_commission_settings', 'open_guard')) {
            RawSchema::generatedColumn('collaborator_commission_settings', 'open_guard', 'TINYINT',
                'CASE WHEN `effective_to` IS NULL THEN 1 ELSE NULL END');
        }

        if (Schema::hasTable('collaborator_commission_entitlements')) {
            if (! Schema::hasColumn('collaborator_commission_entitlements', 'document_key')) {
                // A non-null key, so the unique index over it actually bites. COALESCE picks whichever
                // of the four document ids this row carries — `chk_cce_one_doc` guarantees exactly one.
                RawSchema::generatedColumn('collaborator_commission_entitlements', 'document_key', 'VARCHAR(64)',
                    "CONCAT(`document_type`, ':', COALESCE(`student_admission_id`, `student_fee_id`, "
                    .'`project_milestone_id`, `project_id`))');
            }

            if (! Schema::hasColumn('collaborator_commission_entitlements', 'current_guard')) {
                RawSchema::generatedColumn('collaborator_commission_entitlements', 'current_guard', 'TINYINT',
                    'CASE WHEN `superseded_at` IS NULL THEN 1 ELSE NULL END');
            }
        }

        if (Schema::hasTable('collaborator_payout_allocations')
            && ! Schema::hasColumn('collaborator_payout_allocations', 'active_guard')) {
            RawSchema::generatedColumn('collaborator_payout_allocations', 'active_guard', 'TINYINT',
                'CASE WHEN `is_released` = 0 THEN 1 ELSE NULL END');
        }

        if (Schema::hasTable('collaborator_payout_accounts')
            && ! Schema::hasColumn('collaborator_payout_accounts', 'default_guard')) {
            // The collaborator's own id when this is their live default, NULL otherwise — so the unique
            // index below is "one default per collaborator" rather than "one default in the system".
            RawSchema::generatedColumn('collaborator_payout_accounts', 'default_guard', 'BIGINT UNSIGNED',
                'CASE WHEN `is_default` = 1 AND `deleted_at` IS NULL THEN `collaborator_id` ELSE NULL END');
        }
    }

    public function down(): void
    {
        RawSchema::dropColumn('collaborator_payout_accounts', 'default_guard');
        RawSchema::dropColumn('collaborator_payout_allocations', 'active_guard');
        RawSchema::dropColumn('collaborator_commission_entitlements', 'current_guard');
        RawSchema::dropColumn('collaborator_commission_entitlements', 'document_key');
        RawSchema::dropColumn('collaborator_commission_settings', 'open_guard');
        RawSchema::dropColumn('collaborator_referrals', 'current_guard');
        RawSchema::dropColumn('collaborator_commission_ledger_entries', 'signed_amount');
        RawSchema::dropColumn('project_payments', 'net_received_amount');
        RawSchema::dropColumn('student_fee_payments', 'net_received_amount');
    }
};
