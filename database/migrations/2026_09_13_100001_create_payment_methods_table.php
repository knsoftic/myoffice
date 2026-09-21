<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 · file 1 — `payment_methods`: the configurable face of a fixed enum (§32, phase-13 §2.2).
 *
 * **The enum value on a payment row stays the snapshot of record.** `project_payments.payment_method`
 * and its siblings are `PaymentMethod` strings written once and never touched again; this table is the
 * presentation, ordering, activation and gateway wiring *around* them. Renaming "Bank transfer" to
 * "Bank transfer — HBL" therefore changes every dropdown and no history, which is the whole reason the
 * two are separate.
 *
 * **`uq_pm_default(default_guard)`** makes exactly one default method possible system-wide, enforced by
 * the database rather than by a clear-all-others loop that can half-fail and leave two.
 *
 * `config_encrypted` holds gateway keys. It is `$hidden`, absent from every activity diff, and rendered
 * nowhere — a screen that could show it would make the encryption decorative.
 */
return new class extends Migration
{
    private const TABLE = 'payment_methods';

    public function up(): void
    {
        // Guards the CREATE only; the constraints below are ensured on every run, so a table left
        // behind by a half-applied migration cannot end up looking complete without them (D70).
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            // Must be a `PaymentMethod` enum value; the Form Request validates it against the enum so
            // a method can never be configured for an instrument no payment row can record.
            $table->string('code', 32);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('type', 32);

            $table->boolean('is_online')->default(false);
            $table->string('gateway_driver', 32)->nullable();
            $table->boolean('is_test_mode')->default(true);
            // Keys, secrets, merchant ids. Never rendered, never logged.
            $table->text('config_encrypted')->nullable();

            $table->boolean('supports_refund')->default(false);
            $table->boolean('requires_reference')->default(false);
            // Rendered on the client-facing invoice: "transfer to account 1234, quote the invoice no".
            $table->text('instructions')->nullable();

            // Which forms may offer it, as a JSON subset. A payout method and an invoice method are
            // genuinely different lists, and one flag per context would be six columns that drift.
            $table->json('usable_for');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->integer('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('code', 'uq_pm_code');
            $table->index(['is_active', 'sort_order'], 'idx_pm_active_sort');
            $table->index('type', 'idx_pm_type');
        });

        // A soft-deleted or non-default row is NULL here, and MariaDB ignores NULLs in a unique index —
        // so the guard means "at most one live default" without forbidding anything else.
        RawSchema::generatedColumn(self::TABLE, 'default_guard', 'TINYINT',
            'CASE WHEN `is_default` = 1 AND `deleted_at` IS NULL THEN 1 ELSE NULL END');

        RawSchema::uniqueIndex(self::TABLE, 'uq_pm_default', ['default_guard']);
    }

    private function constraints(): void
    {
        // Only a gateway can be online. An "online" cash method would put a pay-now button on an
        // invoice that nothing in the system can honour.
        $this->ensure('chk_pm_online', "`is_online` = 0 OR `type` = 'gateway'");
        $this->ensure('chk_pm_driver', '`gateway_driver` IS NULL OR `is_online` = 1');
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
