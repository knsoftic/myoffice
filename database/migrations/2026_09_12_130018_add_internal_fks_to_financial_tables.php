<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 18 — every foreign key whose target is one of the fifteen (phase-10-12 §2.2).
 *
 * **[D-IMP-1]: the creates declared columns only, and the keys arrive here.** The in-spine references
 * form cycles across creation order — a receipt points at a referral created in file 7, the ledger
 * points at a wallet and an entitlement, a payout points at an account — so there is no ordering of
 * `Schema::create` calls that satisfies all of them. Splitting the keys out makes the forward order
 * trivial and `down()` deterministic.
 *
 * **`restrictOnDelete` on every money edge**, and `nullOnDelete` only where the spine says so. The rule
 * that produced a commission can never be deleted; the attribution that earned it can never be deleted;
 * the receipt it came from can never be deleted. That is not caution — it is what makes a ledger row
 * still explainable in three years, which is the only reason the ledger exists.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const KEYS = [
        // table, column, references table, on delete
        ['student_fee_installments', 'student_fee_id', 'student_fees', 'restrict'],

        ['student_fee_discounts', 'student_fee_id', 'student_fees', 'restrict'],
        // A wrong discount is reversed, never edited — so the row it reverses must outlive it.
        ['student_fee_discounts', 'reverses_discount_id', 'student_fee_discounts', 'restrict'],

        ['student_fee_payments', 'student_fee_id', 'student_fees', 'restrict'],
        ['student_fee_payments', 'student_fee_installment_id', 'student_fee_installments', 'restrict'],
        ['student_fee_payments', 'collaborator_referral_id', 'collaborator_referrals', 'restrict'],

        ['project_payments', 'collaborator_referral_id', 'collaborator_referrals', 'restrict'],

        ['payment_reversals', 'student_fee_payment_id', 'student_fee_payments', 'restrict'],
        ['payment_reversals', 'project_payment_id', 'project_payments', 'restrict'],

        // The version chain. `nullOnDelete` is harmless here because the no-delete trigger means it
        // never fires; it is the spine's stated behaviour and is kept verbatim.
        ['collaborator_referrals', 'previous_referral_id', 'collaborator_referrals', 'null'],
        ['collaborator_referrals', 'superseded_by_id', 'collaborator_referrals', 'null'],
        ['collaborator_commission_settings', 'supersedes_id', 'collaborator_commission_settings', 'null'],
        ['collaborator_commission_entitlements', 'supersedes_id', 'collaborator_commission_entitlements', 'null'],

        ['collaborator_commission_entitlements', 'collaborator_referral_id', 'collaborator_referrals', 'restrict'],
        ['collaborator_commission_entitlements', 'commission_setting_id', 'collaborator_commission_settings', 'restrict'],
        ['collaborator_commission_entitlements', 'student_fee_id', 'student_fees', 'restrict'],

        ['collaborator_commission_ledger_entries', 'collaborator_wallet_id', 'collaborator_wallets', 'restrict'],
        ['collaborator_commission_ledger_entries', 'entitlement_id', 'collaborator_commission_entitlements', 'restrict'],
        ['collaborator_commission_ledger_entries', 'collaborator_referral_id', 'collaborator_referrals', 'restrict'],
        ['collaborator_commission_ledger_entries', 'commission_setting_id', 'collaborator_commission_settings', 'restrict'],
        ['collaborator_commission_ledger_entries', 'student_fee_payment_id', 'student_fee_payments', 'restrict'],
        ['collaborator_commission_ledger_entries', 'project_payment_id', 'project_payments', 'restrict'],
        ['collaborator_commission_ledger_entries', 'payment_reversal_id', 'payment_reversals', 'restrict'],
        ['collaborator_commission_ledger_entries', 'reverses_entry_id', 'collaborator_commission_ledger_entries', 'restrict'],
        ['collaborator_commission_ledger_entries', 'student_fee_id', 'student_fees', 'restrict'],

        ['collaborator_wallets', 'last_entry_id', 'collaborator_commission_ledger_entries', 'null'],

        ['collaborator_payouts', 'payout_account_id', 'collaborator_payout_accounts', 'null'],

        ['collaborator_payout_allocations', 'payout_id', 'collaborator_payouts', 'restrict'],
        ['collaborator_payout_allocations', 'ledger_entry_id', 'collaborator_commission_ledger_entries', 'restrict'],

        ['collaborator_wallet_reconciliations', 'collaborator_wallet_id', 'collaborator_wallets', 'restrict'],
        ['collaborator_wallet_reconciliations', 'last_entry_id', 'collaborator_commission_ledger_entries', 'null'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as [$table, $column, $references, $onDelete]) {
            $this->add($table, $column, $references, $onDelete);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::KEYS) as [$table, $column]) {
            $this->drop($table, $column);
        }
    }

    private function add(string $table, string $column, string $references, string $onDelete): void
    {
        $name = RawSchema::foreignKeyName($table, $column);

        if (! Schema::hasTable($table) || ! Schema::hasTable($references)
            || ! Schema::hasColumn($table, $column) || $this->exists($name)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`) ON DELETE %s',
            $table,
            $name,
            $column,
            $references,
            $onDelete === 'null' ? 'SET NULL' : 'RESTRICT',
        ));
    }

    private function drop(string $table, string $column): void
    {
        $name = RawSchema::foreignKeyName($table, $column);

        if (Schema::hasTable($table) && $this->exists($name)) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $name));
        }
    }

    private function exists(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }
};
