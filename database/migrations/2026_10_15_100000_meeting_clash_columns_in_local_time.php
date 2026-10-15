<?php

declare(strict_types=1);

use App\Support\Format;
use App\Support\Schema\RawSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The meeting clash columns read the meeting's local time, not its UTC instant.
 *
 * `2026_09_19_100012` derived `meeting_date`, `meeting_start_time` and `meeting_end_time` from the raw
 * `scheduled_at`. That only lined up with class sessions, demos and exams (which keep a local date and
 * local TIME columns) while the meeting form wrote the typed local time straight into `scheduled_at`
 * — the same bug that showed every meeting five hours late, because the page, the reminders and the
 * calendar export all read `scheduled_at` as UTC (D61). The form now stores UTC, so these three columns
 * convert it back to the business's local time before the clash detector compares them.
 *
 * **The offset is fixed when this runs**, from `localization.timezone` (Asia/Karachi, +05:00). A
 * generated column cannot follow a setting, and Pakistan keeps no daylight saving, so the figure is
 * exact; a business that moves to a zone with DST would need this rerun with a per-row conversion.
 *
 * **D70: MariaDB DDL is not transactional**, so every step is guarded and safe to repeat.
 */
return new class extends Migration
{
    private const TABLE = 'meetings';

    private const INDEX = 'idx_me_clash';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $offset = CarbonImmutable::now(Format::timezone())->format('P');
        $local = sprintf("CONVERT_TZ(`scheduled_at`, '+00:00', '%s')", $offset);

        $this->rebuild([
            'meeting_date' => ['date', "DATE({$local})"],
            'meeting_start_time' => ['time', "TIME({$local})"],
            'meeting_end_time' => [
                'time',
                // Clamped to the end of the start day, as before.
                "CASE WHEN DATE({$local} + INTERVAL `duration_minutes` MINUTE) > DATE({$local})"
                ." THEN '23:59:59'"
                ." ELSE TIME({$local} + INTERVAL `duration_minutes` MINUTE) END",
            ],
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->rebuild([
            'meeting_date' => ['date', 'DATE(`scheduled_at`)'],
            'meeting_start_time' => ['time', 'TIME(`scheduled_at`)'],
            'meeting_end_time' => [
                'time',
                'CASE WHEN DATE(`scheduled_at` + INTERVAL `duration_minutes` MINUTE) > DATE(`scheduled_at`)'
                ." THEN '23:59:59'"
                .' ELSE TIME(`scheduled_at` + INTERVAL `duration_minutes` MINUTE) END',
            ],
        ]);
    }

    /**
     * Generated columns cannot be altered in place, so each is dropped and added back with its new
     * expression; the index that reads all three goes first and comes back last.
     *
     * @param  array<string, array{0: string, 1: string}>  $columns
     */
    private function rebuild(array $columns): void
    {
        RawSchema::dropIndex(self::TABLE, self::INDEX);

        foreach ($columns as $column => [$type, $expression]) {
            RawSchema::dropColumn(self::TABLE, $column);
            RawSchema::generatedColumn(self::TABLE, $column, $type, $expression);
        }

        if (! RawSchema::indexExists(self::TABLE, self::INDEX)) {
            RawSchema::index(self::TABLE, self::INDEX, ['meeting_date', 'meeting_start_time', 'meeting_end_time']);
        }
    }
};
