<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 23 · file 2 — the five composite indexes the §106 and §107 viewers filter on
 * (phase-19-23 §2.27, INV-23-5).
 *
 * **Indexes only. No column, no default, no data change.** `activity_log` is the audit trail, and
 * INV-23-5 says Phase 23 reads it and never writes it — that has to be true of the migration as
 * well as of the service, or the phase that promised not to touch the record would have touched it
 * on the way in.
 *
 * **Each one is a composite ending in `created_at`, and the existing single-column indexes are why
 * they are needed.** The table already has `log_name`, `module`, `(causer_type, causer_id)` and
 * `(subject_type, subject_id)`. Every one of those answers "which rows" and none of them answers
 * "which rows, newest first" — so MariaDB finds the matching rows by index and then sorts them,
 * which on an audit trail that only ever grows is the whole cost of the screen. Adding `created_at`
 * to the tail lets the same lookup come back ordered.
 *
 * The originals are left in place rather than dropped: `DESCRIBE`-style lookups and the foreign-key
 * machinery use them, dropping an index to add a wider one is a lock on a large table for no gain,
 * and INV-23-5 is easier to keep if this migration only ever adds.
 *
 * **D70 again:** each index is checked before it is added, so a half-applied run heals.
 */
return new class extends Migration
{
    private const TABLE = 'activity_log';

    /**
     * name => columns. Every one is a filter the §106 viewer or the §107 trail actually offers.
     *
     * @var array<string, list<string>>
     */
    private const INDEXES = [
        // "What did this person do?" — the user timeline.
        'idx_al_causer_time' => ['causer_type', 'causer_id', 'created_at'],

        // "What happened to this record?" — the history tab every detail screen embeds.
        'idx_al_subject_time' => ['subject_type', 'subject_id', 'created_at'],

        // "What happened in fees this month?" — the module filter, which is also how the §107
        // trail narrows to `reports.audit_sensitive_modules`.
        'idx_al_module_time' => ['module', 'created_at'],

        // The log channel, which separates the audit rows from the ordinary ones.
        'idx_al_log_time' => ['log_name', 'created_at'],

        // The unfiltered view, and the prune sweep's range scan.
        'idx_al_time' => ['created_at'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::INDEXES as $name => $columns) {
            // A column the log does not have would be a silent no-op index; ask first.
            foreach ($columns as $column) {
                if (! Schema::hasColumn(self::TABLE, $column)) {
                    throw new RuntimeException(sprintf(
                        'activity_log has no `%s` column, so the reporting index `%s` cannot be built. '
                        .'Phase 1 owns that column — check its migration before running this one.',
                        $column,
                        $name,
                    ));
                }
            }

            if (! RawSchema::indexExists(self::TABLE, $name)) {
                RawSchema::index(self::TABLE, $name, $columns);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (array_keys(self::INDEXES) as $name) {
            if (RawSchema::indexExists(self::TABLE, $name)) {
                RawSchema::dropIndex(self::TABLE, $name);
            }
        }
    }
};
