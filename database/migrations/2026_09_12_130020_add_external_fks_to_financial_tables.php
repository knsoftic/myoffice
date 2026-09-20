<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 20 — the foreign keys whose targets live in other phases (phase-10-12 §2.2).
 *
 * `collaborators`, `users` and `branches` exist now; `students`, `student_admissions`, `courses`,
 * `batches`, `projects`, `project_milestones`, `clients` and `leads` arrive with phases 5, 6 and 14-17.
 * Every key here is guarded on both tables existing **and is re-runnable**, so a later phase simply
 * re-runs this migration once its tables are in place ([D-FS-1]).
 *
 * It skips loudly rather than silently: a missing financial key is a missing guarantee, and a migration
 * that quietly declines to add one leaves the schema looking complete while it is not.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const KEYS = [
        ['student_fees', 'branch_id', 'branches', 'null'],
        ['student_fees', 'student_id', 'students', 'restrict'],
        ['student_fees', 'student_admission_id', 'student_admissions', 'restrict'],
        ['student_fees', 'course_id', 'courses', 'null'],
        ['student_fees', 'batch_id', 'batches', 'null'],
        ['student_fees', 'collaborator_id', 'collaborators', 'null'],
        ['student_fees', 'cancelled_by', 'users', 'null'],
        ['student_fees', 'created_by', 'users', 'null'],
        ['student_fees', 'updated_by', 'users', 'null'],

        ['student_fee_installments', 'created_by', 'users', 'null'],
        ['student_fee_installments', 'updated_by', 'users', 'null'],

        ['student_fee_discounts', 'approved_by', 'users', 'null'],
        ['student_fee_discounts', 'created_by', 'users', 'null'],
        ['student_fee_discounts', 'updated_by', 'users', 'null'],

        ['student_fee_payments', 'student_id', 'students', 'restrict'],
        ['student_fee_payments', 'branch_id', 'branches', 'null'],
        // restrictOnDelete: a partner who has been paid on a receipt cannot be erased from it.
        ['student_fee_payments', 'collaborator_id', 'collaborators', 'restrict'],
        ['student_fee_payments', 'received_by', 'users', 'null'],
        ['student_fee_payments', 'created_by', 'users', 'null'],
        ['student_fee_payments', 'updated_by', 'users', 'null'],

        ['project_payments', 'project_id', 'projects', 'restrict'],
        ['project_payments', 'client_id', 'clients', 'restrict'],
        ['project_payments', 'project_milestone_id', 'project_milestones', 'null'],
        ['project_payments', 'collaborator_id', 'collaborators', 'restrict'],
        ['project_payments', 'received_by', 'users', 'null'],
        ['project_payments', 'created_by', 'users', 'null'],
        ['project_payments', 'updated_by', 'users', 'null'],

        ['payment_reversals', 'approved_by', 'users', 'null'],
        ['payment_reversals', 'performed_by', 'users', 'null'],
        ['payment_reversals', 'created_by', 'users', 'null'],
        ['payment_reversals', 'updated_by', 'users', 'null'],

        ['collaborator_referrals', 'collaborator_id', 'collaborators', 'restrict'],
        ['collaborator_referrals', 'student_id', 'students', 'restrict'],
        ['collaborator_referrals', 'project_id', 'projects', 'restrict'],
        ['collaborator_referrals', 'client_id', 'clients', 'restrict'],
        // A lead is not financial evidence, so this one may go null.
        ['collaborator_referrals', 'lead_id', 'leads', 'null'],
        ['collaborator_referrals', 'changed_by', 'users', 'null'],
        ['collaborator_referrals', 'created_by', 'users', 'null'],
        ['collaborator_referrals', 'updated_by', 'users', 'null'],

        ['collaborator_commission_settings', 'collaborator_id', 'collaborators', 'restrict'],
        ['collaborator_commission_settings', 'approved_by', 'users', 'null'],
        ['collaborator_commission_settings', 'created_by', 'users', 'null'],
        ['collaborator_commission_settings', 'updated_by', 'users', 'null'],

        ['collaborator_commission_entitlements', 'collaborator_id', 'collaborators', 'restrict'],
        ['collaborator_commission_entitlements', 'student_admission_id', 'student_admissions', 'restrict'],
        ['collaborator_commission_entitlements', 'project_id', 'projects', 'restrict'],
        ['collaborator_commission_entitlements', 'project_milestone_id', 'project_milestones', 'restrict'],
        ['collaborator_commission_entitlements', 'created_by', 'users', 'null'],
        ['collaborator_commission_entitlements', 'updated_by', 'users', 'null'],

        ['collaborator_commission_ledger_entries', 'collaborator_id', 'collaborators', 'restrict'],
        ['collaborator_commission_ledger_entries', 'student_id', 'students', 'restrict'],
        ['collaborator_commission_ledger_entries', 'project_id', 'projects', 'restrict'],
        ['collaborator_commission_ledger_entries', 'project_milestone_id', 'project_milestones', 'null'],
        ['collaborator_commission_ledger_entries', 'approved_by', 'users', 'null'],
        ['collaborator_commission_ledger_entries', 'cancelled_by', 'users', 'null'],
        ['collaborator_commission_ledger_entries', 'created_by', 'users', 'null'],
        ['collaborator_commission_ledger_entries', 'updated_by', 'users', 'null'],

        ['collaborator_wallets', 'collaborator_id', 'collaborators', 'restrict'],
        ['collaborator_wallets', 'created_by', 'users', 'null'],
        ['collaborator_wallets', 'updated_by', 'users', 'null'],

        ['collaborator_payout_accounts', 'collaborator_id', 'collaborators', 'restrict'],
        ['collaborator_payout_accounts', 'verified_by', 'users', 'null'],
        ['collaborator_payout_accounts', 'created_by', 'users', 'null'],
        ['collaborator_payout_accounts', 'updated_by', 'users', 'null'],

        ['collaborator_payouts', 'collaborator_id', 'collaborators', 'restrict'],
        ['collaborator_payouts', 'requested_by', 'users', 'null'],
        ['collaborator_payouts', 'approved_by', 'users', 'null'],
        ['collaborator_payouts', 'paid_by', 'users', 'null'],
        ['collaborator_payouts', 'rejected_by', 'users', 'null'],
        ['collaborator_payouts', 'cancelled_by', 'users', 'null'],
        ['collaborator_payouts', 'created_by', 'users', 'null'],
        ['collaborator_payouts', 'updated_by', 'users', 'null'],

        ['collaborator_payout_allocations', 'collaborator_id', 'collaborators', 'restrict'],
        ['collaborator_payout_allocations', 'released_by', 'users', 'null'],
        ['collaborator_payout_allocations', 'created_by', 'users', 'null'],

        ['collaborator_wallet_reconciliations', 'collaborator_id', 'collaborators', 'restrict'],
        ['collaborator_wallet_reconciliations', 'repaired_by', 'users', 'null'],
        ['collaborator_wallet_reconciliations', 'created_by', 'users', 'null'],
    ];

    public function up(): void
    {
        $skipped = [];

        foreach (self::KEYS as [$table, $column, $references, $onDelete]) {
            if (! $this->add($table, $column, $references, $onDelete)) {
                $skipped[] = $table.'.'.$column.' -> '.$references;
            }
        }

        if ($skipped !== []) {
            $message = sprintf(
                'phase-10 file 20: %d foreign key(s) were skipped because their target table does not '
                .'exist yet. Re-run this migration once those phases have migrated — it is idempotent. '
                .'Skipped: %s',
                count($skipped),
                implode(', ', $skipped),
            );

            logger()->warning($message);

            // Printed for a person running `migrate`, but not for the test runner: every suite that
            // refreshes the database would otherwise repeat it, and a warning that appears forty times
            // an hour is one nobody reads.
            if (! app()->runningUnitTests()) {
                echo $message.PHP_EOL;
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::KEYS) as [$table, $column]) {
            $name = RawSchema::foreignKeyName($table, $column);

            if (Schema::hasTable($table) && $this->exists($name)) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $name));
            }
        }
    }

    private function add(string $table, string $column, string $references, string $onDelete): bool
    {
        $name = RawSchema::foreignKeyName($table, $column);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return false;
        }

        if ($this->exists($name)) {
            return true;
        }

        if (! Schema::hasTable($references)) {
            return false;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`) ON DELETE %s',
            $table,
            $name,
            $column,
            $references,
            $onDelete === 'null' ? 'SET NULL' : 'RESTRICT',
        ));

        return true;
    }

    private function exists(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }
};
