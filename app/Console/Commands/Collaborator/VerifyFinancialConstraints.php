<?php

declare(strict_types=1);

namespace App\Console\Commands\Collaborator;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `financial:verify-constraints` — prove the spine's guarantees are still in the database
 * (spine R-3, R-4, §10.4).
 *
 * **Every invariant in this system is enforced twice**: once by a service, and once by a unique index,
 * a CHECK, a generated column or a `BEFORE DELETE` trigger. The second one is what holds when somebody
 * writes SQL by hand, runs a migration that half-applied, or restores a dump from a server configured
 * differently.
 *
 * The failure this catches is silent by nature. A dropped index does not raise anything — it simply
 * stops refusing, and the first sign is a duplicate commission six weeks later with no explanation. So
 * this runs nightly and shouts, and it is deliberately a **list of names** rather than a re-derivation:
 * a check that computed what ought to exist would drift with the code it is supposed to be independent
 * of.
 */
#[AsCommand(name: 'financial:verify-constraints')]
final class VerifyFinancialConstraints extends Command
{
    protected $signature = 'financial:verify-constraints {--quiet-when-ok : Print nothing unless something is missing}';

    protected $description = 'Assert every financial unique index, CHECK, generated column and delete trigger still exists';

    /**
     * The fifteen tables of spine §2.1.
     *
     * @var list<string>
     */
    private const TABLES = [
        'student_fees', 'student_fee_installments', 'student_fee_discounts', 'student_fee_payments',
        'project_payments', 'payment_reversals',
        'collaborator_referrals', 'collaborator_commission_settings',
        'collaborator_commission_entitlements', 'collaborator_commission_ledger_entries',
        'collaborator_wallets', 'collaborator_payouts', 'collaborator_payout_allocations',
        'collaborator_payout_accounts', 'collaborator_wallet_reconciliations',
    ];

    /**
     * Named because a name is stable and a count is not: an index renamed in a later migration should
     * fail this loudly rather than pass because the total still adds up.
     *
     * @var list<string>
     */
    private const UNIQUE_INDEXES = [
        'uq_sf_number', 'uq_sf_generation', 'uq_sfi_no', 'uq_sfd_reverses',
        'uq_sfp_receipt', 'uq_sfp_idem', 'uq_sfp_gateway',
        'uq_pp_number', 'uq_pp_idem', 'uq_pp_gateway',
        'uq_pr_number', 'uq_pr_idem',
        'uq_cr_student_current', 'uq_cr_project_current', 'uq_cr_client_current', 'uq_cr_lead_current',
        'uq_ccs_start', 'uq_ccs_open',
        'uq_cce_current',
        'uq_cle_dedupe', 'uq_cle_source', 'uq_cle_reversal_pair',
        'uq_cw_collaborator',
        'uq_cp_number', 'uq_cp_txn',
        'uq_cpa_pair',
        'uq_cpacc_default',
        'uq_cwr_run',
    ];

    /**
     * @var list<string>
     */
    private const CHECKS = [
        'chk_sf_nonneg', 'chk_sf_discount_ceiling',
        'chk_sfi_amount', 'chk_sfd_nonzero', 'chk_sfd_pct',
        'chk_sfp_amount', 'chk_sfp_refund_ceiling',
        'chk_pp_amount', 'chk_pp_refund_ceiling',
        'chk_pr_one_target', 'chk_pr_amount',
        'chk_cr_one_subject', 'chk_cr_dates',
        'chk_ccs_dates', 'chk_ccs_rate', 'chk_ccs_fixed', 'chk_ccs_payload',
        'chk_cce_one_doc', 'chk_cce_cap', 'chk_cce_nonneg',
        'chk_cle_amount', 'chk_cle_sign', 'chk_cle_reverses', 'chk_cle_debit_clean',
        'chk_cle_allocation_ceiling', 'chk_cle_undo_ceiling', 'chk_cle_rate', 'chk_cle_base',
    ];

    /**
     * `table.column` — the guards that make a NULL-tolerant unique index mean what it says.
     *
     * @var list<string>
     */
    private const GENERATED = [
        'student_fee_payments.net_received_amount',
        'project_payments.net_received_amount',
        'collaborator_commission_ledger_entries.signed_amount',
        'collaborator_commission_ledger_entries.source_guard',
        'collaborator_referrals.current_guard',
        'collaborator_commission_settings.open_guard',
        'collaborator_commission_entitlements.document_key',
        'collaborator_commission_entitlements.current_guard',
        'collaborator_payout_allocations.active_guard',
        'collaborator_payout_accounts.default_guard',
    ];

    /**
     * The nine `BEFORE DELETE` triggers migration 19 creates — read from that file, not inferred from
     * the append-only table list.
     *
     * `collaborator_payout_allocations` and `collaborator_wallet_reconciliations` are append-only and
     * deliberately have **no** trigger: `CLAUDE.md` §3 puts a trigger on a table only "where its
     * contract says so", and for those two the model `deleting` hook is the protection. Listing them
     * here would make this command fail nightly against a schema that is exactly right.
     *
     * @var list<string>
     */
    private const TRIGGERS = [
        'trg_sfd_no_delete', 'trg_sfp_no_delete', 'trg_pp_no_delete', 'trg_pr_no_delete',
        'trg_cr_no_delete', 'trg_ccs_no_delete', 'trg_cce_no_delete',
        'trg_cle_no_delete', 'trg_cp_no_delete',
    ];

    public function handle(): int
    {
        $missing = array_merge(
            $this->missing('table', self::TABLES, $this->existingTables()),
            $this->missing('unique index', self::UNIQUE_INDEXES, $this->existingUniqueIndexes()),
            $this->missing('check', self::CHECKS, $this->existingChecks()),
            $this->missing('generated column', self::GENERATED, $this->existingGeneratedColumns()),
            $this->missing('trigger', self::TRIGGERS, $this->existingTriggers()),
        );

        $total = count(self::TABLES) + count(self::UNIQUE_INDEXES) + count(self::CHECKS)
            + count(self::GENERATED) + count(self::TRIGGERS);

        if ($missing === []) {
            if (! $this->option('quiet-when-ok')) {
                $this->info(sprintf('%d/%d financial schema objects present on [%s].', $total, $total, DB::getDatabaseName()));
            }

            return self::SUCCESS;
        }

        // Loud on purpose. A vanished guard is the one failure that produces no error of its own — it
        // simply stops refusing, and the first symptom is a duplicate commission nobody can explain.
        $this->error(sprintf('%d financial schema object(s) are MISSING from [%s]:', count($missing), DB::getDatabaseName()));

        foreach ($missing as $line) {
            $this->line('  · '.$line);
        }

        Log::critical('financial:verify-constraints found missing schema objects', [
            'database' => DB::getDatabaseName(),
            'missing' => $missing,
        ]);

        return self::FAILURE;
    }

    /**
     * @param  list<string>  $expected
     * @param  array<string, true>  $actual
     * @return list<string>
     */
    private function missing(string $kind, array $expected, array $actual): array
    {
        $gone = [];

        foreach ($expected as $name) {
            if (! isset($actual[$name])) {
                $gone[] = $kind.' '.$name;
            }
        }

        return $gone;
    }

    /**
     * @return array<string, true>
     */
    private function existingTables(): array
    {
        return $this->keyed(DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->pluck('TABLE_NAME')->all());
    }

    /**
     * @return array<string, true>
     */
    private function existingUniqueIndexes(): array
    {
        return $this->keyed(DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('NON_UNIQUE', 0)
            ->pluck('INDEX_NAME')->all());
    }

    /**
     * @return array<string, true>
     */
    private function existingChecks(): array
    {
        return $this->keyed(DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->pluck('CONSTRAINT_NAME')->all());
    }

    /**
     * @return array<string, true>
     */
    private function existingGeneratedColumns(): array
    {
        $rows = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->whereNotNull('GENERATION_EXPRESSION')
            ->where('GENERATION_EXPRESSION', '<>', '')
            ->selectRaw('CONCAT(TABLE_NAME, ".", COLUMN_NAME) as k')
            ->pluck('k')->all();

        return $this->keyed($rows);
    }

    /**
     * @return array<string, true>
     */
    private function existingTriggers(): array
    {
        return $this->keyed(DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->pluck('TRIGGER_NAME')->all());
    }

    /**
     * @param  array<int, mixed>  $names
     * @return array<string, true>
     */
    private function keyed(array $names): array
    {
        return array_fill_keys(array_map(static fn (mixed $n): string => (string) $n, $names), true);
    }
}
