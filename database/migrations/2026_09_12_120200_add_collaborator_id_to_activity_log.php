<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 · §2.5 — `activity_log.collaborator_id` (requirement §60).
 *
 * §60 asks for a per-collaborator activity log. **D13 contracts one audit store**, so §60 has to be a
 * filtered view over `activity_log` rather than a second table — and the filter has to be an **indexed
 * column** rather than the causer, because the rows a partner most wants to see (commission created,
 * commission approved, payout paid) are written by the engine or the scheduler and have a **null
 * causer**. Scoping by causer alone would silently drop exactly those.
 *
 * Nullable, `nullOnDelete`, and indexed with `created_at` because the feed is always "this collaborator,
 * newest first".
 */
return new class extends Migration
{
    private const TABLE = 'activity_log';

    private const COLUMN = 'collaborator_id';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->foreignId(self::COLUMN)->nullable()->after('subject_type')
                ->constrained('collaborators')->nullOnDelete();
            $table->index([self::COLUMN, 'created_at'], 'idx_activity_collaborator');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropForeign([self::COLUMN]);
            $table->dropIndex('idx_activity_collaborator');
            $table->dropColumn(self::COLUMN);
        });
    }
};
