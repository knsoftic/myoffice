<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 · file 03 — `student_fee_discounts`: every reduction ever granted (spine §2.4).
 *
 * **Append-only** (D16, D19): no `deleted_at`, and a `BEFORE DELETE` trigger in file 19. A wrong
 * discount is undone by a `reversal` row that points at it, never by an edit — which is what lets the
 * engine answer "what was the net fee on the date payment X arrived" without mutating anything.
 *
 * **`amount` is a SIGNED delta to `net_amount`**, not a magnitude: reductions are negative, a
 * `correction` is positive, and a `reversal` carries the opposite sign of the row it undoes. One column
 * and one sign convention, so the net is a SUM rather than a case analysis.
 *
 * `approved_by_name` is a snapshot beside the foreign key on purpose: the approver's user row may be
 * deleted years later, and "approved by" with nothing after it is the sentence a dispute turns on.
 */
return new class extends Migration
{
    private const TABLE = 'student_fee_discounts';

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

            $table->unsignedBigInteger('student_fee_id');
            $table->string('type', 32);

            $table->decimal('amount', 15, 2);
            // Only for percentage_discount. `amount` still stores the computed result, so the
            // arithmetic is never re-done against a fee that has since changed.
            $table->decimal('percentage', 8, 4)->nullable();

            $table->string('reason', 255);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name', 150)->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->date('effective_on');
            $table->unsignedBigInteger('reverses_discount_id')->nullable();
            $table->string('idempotency_key', 64);

            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            // A double-submitted discount form cannot discount twice.
            $table->unique('idempotency_key', 'uq_sfd_idem');
            // An adjustment can be reversed at most once.
            $table->unique('reverses_discount_id', 'uq_sfd_reverses');
            $table->index(['student_fee_id', 'effective_on']);
            $table->index('type');
        });
    }

    private function constraints(): void
    {
        // A zero-value discount is a row that says nothing and still has to be reversed to be undone.
        $this->ensure('chk_sfd_nonzero', '`amount` <> 0');
        $this->ensure('chk_sfd_pct',
            '`percentage` IS NULL OR (`percentage` > 0 AND `percentage` <= 100)');
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
