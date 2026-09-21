<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 · file 7 — the room backstop must agree with the room rule.
 *
 * `uq_tte_room` and `uq_cs_room_slot` were guarded on `active_guard`, which is only "is this booking
 * live". That made them stricter than `ScheduleClashDetector`, which skips the classroom dimension
 * for an online class and for a virtual room: two online batches naming the same meeting link were
 * allowed by the detector and then refused by a 1062 nobody could explain. A backstop that catches
 * things the rule permits is not a backstop.
 *
 * So the two room indexes move to `room_guard`, which is 1 only while the booking is live **and**
 * actually occupies a room. The teacher and batch indexes keep `active_guard`: a teacher is one
 * person and a batch is one group of students whatever the mode, so neither has an exemption.
 *
 * **A virtual room therefore only makes sense for an online class**, and that is now a rule the
 * services state rather than a coincidence: a physical or hybrid class needs somewhere to physically
 * be. With that, the index and the detector exempt exactly the same rows.
 *
 * The two create migrations declare `room_guard` from the start, so a fresh install never runs the
 * repair below — it finds the column and the indexes already right and does nothing.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{0: string, 1: string, 2: array<int, string>}>
     */
    private const REPAIRS = [
        'timetable_entries' => ['uq_tte_room', 'is_active', ['classroom_id', 'day_of_week', 'start_time', 'effective_from', 'room_guard']],
        'class_sessions' => ['uq_cs_room_slot', 'status', ['classroom_id', 'session_date', 'start_time', 'room_guard']],
    ];

    public function up(): void
    {
        foreach (self::REPAIRS as $table => [$index, $liveColumn, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'room_guard')) {
                RawSchema::generatedColumn(
                    $table,
                    'room_guard',
                    'TINYINT',
                    $this->expressionFor($liveColumn),
                );
            }

            // Swap the index only if it is still the one guarded on `active_guard`.
            if ($this->indexGuardedOnActive($table, $index)) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index));
            }

            if (! RawSchema::indexExists($table, $index, unique: true)) {
                RawSchema::uniqueIndex($table, $index, $columns);
            }
        }
    }

    public function down(): void
    {
        foreach (self::REPAIRS as $table => [$index, , $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (RawSchema::indexExists($table, $index, unique: true)) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index));
            }

            $restored = array_map(
                static fn (string $column): string => $column === 'room_guard' ? 'active_guard' : $column,
                $columns,
            );

            RawSchema::uniqueIndex($table, $index, $restored);

            if (Schema::hasColumn($table, 'room_guard')) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP COLUMN `room_guard`', $table));
            }
        }
    }

    private function expressionFor(string $liveColumn): string
    {
        // A booking holds a room while it is live AND its mode actually needs one.
        return $liveColumn === 'is_active'
            ? "CASE WHEN `is_active` = 1 AND `delivery_mode` <> 'online' THEN 1 ELSE NULL END"
            : "CASE WHEN `status` IN ('scheduled', 'held') AND `delivery_mode` <> 'online' THEN 1 ELSE NULL END";
    }

    private function indexGuardedOnActive(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->where('COLUMN_NAME', 'active_guard')
            ->exists();
    }
};
