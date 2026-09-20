<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 09 — `collaborator_commission_entitlements`: the promise (spine §2.10).
 *
 * **The contract layer between a rule and the releases.** A base other than `paid` promises money before
 * the business has collected any: "10 % of the gross fee" is a number the moment the charge exists. This
 * row records that promise once — with the rule, the rate, the document figure and the collectible
 * figure all snapshotted — and each receipt releases a slice of it. The total can therefore never exceed
 * what was agreed, however many payments arrive and whatever anybody later changes the rule to.
 *
 * **One is opened for every commission, including the uncapped `paid` base** ([D-FS-7]), where
 * `entitlement_amount` is NULL. One uniform code path is safer than a conditional one: the conditional
 * version is the one where the rarely-taken branch is the one with the bug.
 *
 * **It is also the row that is locked** to serialise two payments arriving against the same document at
 * the same moment.
 *
 * `chk_cce_cap` is the **structural** over-release ceiling (INV-12): even a future caller with a bug in
 * the proportional arithmetic cannot push the total past the promise, because the UPDATE fails. A rule
 * enforced only in a service is a rule that holds until somebody writes a second service.
 */
return new class extends Migration
{
    private const TABLE = 'collaborator_commission_entitlements';

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
            // Immutable proof of the attribution this promise rests on.
            $table->unsignedBigInteger('collaborator_referral_id');
            // Null when rule_source is not `collaborator_rule`.
            $table->unsignedBigInteger('commission_setting_id')->nullable();
            $table->string('rule_source', 24);

            $table->string('document_type', 24);
            $table->unsignedBigInteger('student_admission_id')->nullable();
            $table->unsignedBigInteger('student_fee_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('project_milestone_id')->nullable();
            // `document_key` is a STORED generated column added by file 16 — a non-null key, so the
            // unique index over it actually bites.

            $table->string('commission_for', 16);

            // Every rule figure below is a SNAPSHOT. Changing the rule tomorrow must not move a promise
            // made today, and re-reading the rule at release time is exactly how that happens.
            $table->string('calculation_type', 16);
            $table->decimal('commission_rate', 8, 4)->nullable();
            $table->decimal('fixed_amount', 15, 2)->nullable();
            $table->string('fixed_release', 24)->nullable();
            $table->string('commission_base', 32);
            $table->string('approval_mode', 16);
            $table->unsignedSmallInteger('hold_days')->default(0);

            // The figure the promise was computed from.
            $table->decimal('document_base_amount', 15, 2)->default('0.00');
            // The DENOMINATOR of proportional release: what is expected to be collected.
            $table->decimal('collectible_amount', 15, 2)->default('0.00');
            // The capped total promise. **NULL means uncapped** — a percentage on money actually paid.
            $table->decimal('entitlement_amount', 15, 2)->nullable();
            $table->decimal('max_commission_amount', 15, 2)->nullable();

            $table->decimal('released_amount', 15, 2)->default('0.00');
            $table->decimal('reversed_amount', 15, 2)->default('0.00');
            // The NUMERATOR: commissionable money counted so far.
            $table->decimal('collected_amount', 15, 2)->default('0.00');
            // Set when a supersede floors the promise below what was already released (§6.6).
            $table->decimal('over_released_amount', 15, 2)->default('0.00');
            $table->unsignedInteger('ledger_entry_count')->default(0);

            $table->string('status', 32)->default('open');
            $table->date('opened_on');
            $table->date('closed_on')->nullable();

            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->string('supersede_reason', 255)->nullable();
            // `current_guard` is a STORED generated column added by file 16.

            // The rule row plus every global setting used, frozen at opening. This is what makes a
            // commission explainable years later without reconstructing the settings of the day.
            $table->json('rule_snapshot');

            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['collaborator_id', 'status'], 'idx_cce_collaborator_status');
            $table->index('commission_setting_id', 'idx_cce_setting');
            $table->index('collaborator_referral_id', 'idx_cce_referral');
            // The discrepancy queue.
            $table->index(['status', 'over_released_amount'], 'idx_cce_discrepancy');
            $table->index('student_admission_id', 'idx_cce_admission');
            $table->index('student_fee_id', 'idx_cce_fee');
            $table->index('project_id', 'idx_cce_project');
            $table->index('project_milestone_id', 'idx_cce_milestone');
            $table->index('supersedes_id', 'idx_cce_supersedes');
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_cce_one_doc',
            '(`student_admission_id` IS NOT NULL) + (`student_fee_id` IS NOT NULL) '
            .'+ (`project_id` IS NOT NULL) + (`project_milestone_id` IS NOT NULL) = 1');

        // INV-12, the structural ceiling.
        $this->ensure('chk_cce_cap',
            '`entitlement_amount` IS NULL OR `released_amount` <= `entitlement_amount`');

        $this->ensure('chk_cce_nonneg',
            '`released_amount` >= 0 AND `collected_amount` >= 0 AND `reversed_amount` >= 0 '
            .'AND `over_released_amount` >= 0 AND `collectible_amount` >= 0');
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
