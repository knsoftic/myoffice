<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 · file 12 — the three columns that let a meeting join the clash check
 * (phase-19-23 §6.17, phase-14-17 §6.7, D47).
 *
 * **`ScheduleClashDetector` scans a date column and two TIME columns**, because that is the shape
 * every occupant it was written for has: a timetable entry, a class session, a demo, an exam. A
 * meeting stores `scheduled_at` as a datetime and derives `ends_at` from it, so it does not fit —
 * and `where('scheduled_at', '<', '13:00:00')` compares a datetime to a time string and quietly
 * matches nothing.
 *
 * **The bridge is three generated STORED columns here rather than a change to the detector.** D47 is
 * the reason: Phase 16 owns that class, and a later phase widening its query shapes is how two
 * phases come to share one piece of logic and disagree about it. A meeting that presents itself in
 * the shape the detector already understands needs no new code path, no new branch to test, and no
 * second definition of "overlap".
 *
 * **`meeting_end_time` is clamped to the end of the start day**, which is the one honest thing to do
 * with a meeting that crosses midnight. Without the clamp, a 23:00 meeting lasting two hours would
 * have an end time of 01:00 — *earlier* than its start — and the detector's `start < end` comparison
 * would find no overlap at all, silently missing every conflict including the obvious one. Clamped,
 * the start day is checked correctly and only the small hours of the following day are unchecked.
 * That is a bounded, stated limitation rather than a hidden one, and it is the same behaviour a
 * class session running past midnight would get.
 *
 * `duration_minutes` is capped at 1440 by `chk_me_duration`, so the overflow is at most one day.
 *
 * **D70: MariaDB DDL is not transactional**, so every step is guarded and safe to repeat.
 */
return new class extends Migration
{
    private const TABLE = 'meetings';

    /** column => the expression behind it. */
    private const GENERATED = [
        'meeting_date' => ['date', 'DATE(`scheduled_at`)'],
        'meeting_start_time' => ['time', 'TIME(`scheduled_at`)'],
        'meeting_end_time' => [
            'time',
            // See the class note on the clamp.
            "CASE WHEN DATE(`scheduled_at` + INTERVAL `duration_minutes` MINUTE) > DATE(`scheduled_at`)"
            ." THEN '23:59:59'"
            .' ELSE TIME(`scheduled_at` + INTERVAL `duration_minutes` MINUTE) END',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::GENERATED as $column => [$type, $expression]) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                RawSchema::generatedColumn(self::TABLE, $column, $type, $expression);
            }
        }

        // The detector filters on the date first and the times second, which is the order that makes
        // this index usable — and a room's diary is the query it runs most.
        if (! RawSchema::indexExists(self::TABLE, 'idx_me_clash')) {
            RawSchema::index(self::TABLE, 'idx_me_clash', ['meeting_date', 'meeting_start_time', 'meeting_end_time']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // The index reads all three columns, so it goes first — dropping a column an index depends
        // on is error 1553.
        if (RawSchema::indexExists(self::TABLE, 'idx_me_clash')) {
            RawSchema::dropIndex(self::TABLE, 'idx_me_clash');
        }

        foreach (array_keys(self::GENERATED) as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                RawSchema::dropColumn(self::TABLE, $column);
            }
        }
    }
};
