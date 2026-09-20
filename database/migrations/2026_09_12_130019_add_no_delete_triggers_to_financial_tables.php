<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 19 — the nine `BEFORE DELETE` triggers (spine INV-5).
 *
 * Nine tables in this set carry no `deleted_at` because they are money, evidence or audit (D16, D19).
 * The model hooks refuse a delete first and say why; **these are the layer that holds when somebody
 * reaches the table with a raw query, a console command, a database client or a future migration.**
 *
 * The one that matters most is `trg_cle_no_delete`. Every duplicate guarantee in the commission engine
 * rests on `uq_cle_source` and `uq_cle_dedupe`, and a DELETE would **free the unique slot for a second
 * commission on the same receipt**. Without this trigger, "a payment can never pay twice" is true only
 * as long as nobody deletes a row.
 *
 * Each message is written for the person reading it at two in the morning, because the spine's R-5
 * lesson is that a bare `SQLSTATE 45000` with no explanation is how a trigger eventually gets dropped.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const TRIGGERS = [
        ['student_fee_discounts', 'trg_sfd_no_delete',
            'A discount is reversed by a new row that points at it, never deleted (D16).'],
        ['student_fee_payments', 'trg_sfp_no_delete',
            'A receipt is voided or refunded through payment_reversals, never deleted (D16).'],
        ['project_payments', 'trg_pp_no_delete',
            'A payment is voided or refunded through payment_reversals, never deleted (D16).'],
        ['payment_reversals', 'trg_pr_no_delete',
            'A reversal is evidence that money went back. It is never deleted (D16).'],
        ['collaborator_referrals', 'trg_cr_no_delete',
            'An attribution is superseded, never deleted: a ledger row must stay explainable (INV-R4).'],
        ['collaborator_commission_settings', 'trg_ccs_no_delete',
            'A rule version is superseded, never deleted: past commission must stay explainable.'],
        ['collaborator_commission_entitlements', 'trg_cce_no_delete',
            'An entitlement is the promise a commission was released against. It is never deleted.'],
        ['collaborator_commission_ledger_entries', 'trg_cle_no_delete',
            'Deleting a commission would free its unique slot and let the same receipt pay twice.'],
        ['collaborator_payouts', 'trg_cp_no_delete',
            'Payout history survives cancellation; cancelling is a status, not a delete (D16).'],
    ];

    public function up(): void
    {
        foreach (self::TRIGGERS as [$table, $trigger, $why]) {
            if (! Schema::hasTable($table) || RawSchema::triggerExists($trigger)) {
                continue;
            }

            RawSchema::noDeleteTrigger($table, $trigger, $why);
        }
    }

    public function down(): void
    {
        foreach (self::TRIGGERS as [, $trigger]) {
            RawSchema::dropTrigger($trigger);
        }
    }
};
