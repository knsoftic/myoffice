<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 08 — `collaborator_commission_settings`: immutable rule versions (spine §2.9).
 *
 * **A rate change is a new version, never an edit.** That is the whole design: "what was this partner's
 * rate on the day that payment arrived" has to be answerable years later, and a mutable rate column
 * cannot answer it. `effective_to` is the only money-adjacent column the model hook lets move, and only
 * from NULL to a date — closing a window, never re-opening one.
 *
 * **`uq_ccs_open` is the interesting index.** At most one open-ended version per collaborator per scope,
 * carried by a generated column (files 16-17), so two concurrent edits cannot both leave an open rule
 * and the resolver can never find two candidates for the same date. Without it the race is silent and
 * the symptom is a commission that is right on Tuesday and wrong on Wednesday.
 *
 * `chk_ccs_payload` refuses a rule saved without the number it needs — a percentage rule with no rate,
 * or a fixed rule with no amount. Both would resolve happily and produce zero.
 */
return new class extends Migration
{
    private const TABLE = 'collaborator_commission_settings';

    public function up(): void
    {
        // Guards the CREATE only. The constraints below are ensured on every run, so a table left
        // behind by a half-applied migration cannot end up looking complete without them.
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('collaborator_id');
            // The two sides are configured independently (§35).
            $table->string('commission_for', 16);
            $table->boolean('is_enabled')->default(true);

            $table->string('calculation_type', 16);
            // 10.0000 = 10 %.
            $table->decimal('rate', 8, 4)->nullable();
            $table->decimal('fixed_amount', 15, 2)->nullable();
            // Null = inherit collaborator.fixed_commission_release.
            $table->string('fixed_release', 24)->nullable();
            // Null = use the global base setting for this scope.
            $table->string('base_override', 32)->nullable();

            // Null = use collaborator.commissionable_fee_types.
            $table->json('applies_to_fee_types')->nullable();
            $table->json('applies_to_milestone_ids')->nullable();

            // Ignore dust receipts; null = no floor.
            $table->decimal('min_payment_amount', 15, 2)->nullable();
            // Per-document cap; null = uncapped.
            $table->decimal('max_commission_amount', 15, 2)->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // `open_guard` is a STORED generated column added by file 16.

            $table->string('status', 32)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->dateTime('superseded_at')->nullable();

            // §107: "10% -> 15% because ...". Mandatory in the Form Request once a previous version
            // exists, because a rate that changed for no recorded reason is the one people argue about.
            $table->string('change_reason', 255)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->string('notes', 255)->nullable();

            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            // Two versions cannot start on the same day, so "the rule on 2026-04-02" is never ambiguous.
            $table->unique(['collaborator_id', 'commission_for', 'effective_from'], 'uq_ccs_start');
            $table->unique(['collaborator_id', 'commission_for', 'version'], 'uq_ccs_version');
            // The resolver's only query.
            $table->index(['collaborator_id', 'commission_for', 'effective_from', 'effective_to'], 'idx_ccs_lookup');
            $table->index('status', 'idx_ccs_status');
            $table->index('supersedes_id', 'idx_ccs_supersedes');
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_ccs_dates',
            '`effective_to` IS NULL OR `effective_to` >= `effective_from`');

        $this->ensure('chk_ccs_rate',
            '`rate` IS NULL OR (`rate` >= 0 AND `rate` <= 100)');

        $this->ensure('chk_ccs_fixed',
            '`fixed_amount` IS NULL OR `fixed_amount` >= 0');

        $this->ensure('chk_ccs_payload',
            "(`calculation_type` = 'percentage' AND `rate` IS NOT NULL AND `fixed_amount` IS NULL) "
            ."OR (`calculation_type` = 'fixed' AND `fixed_amount` IS NOT NULL AND `rate` IS NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }
};
