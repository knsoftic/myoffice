<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\StatisticMetric;
use App\Enums\Cms\StatisticValueMode;
use App\Models\Cms\WebsiteSectionItem;
use App\Support\Modules;
use App\Support\Money;
use App\Support\SettingsRepository;
use BackedEnum;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The six hero statistics of requirement §9, resolved live (phase-03 §6.11, INV-12, build-order F2).
 *
 * One public entry point per question:
 *
 *   all()               live metric value => ?string, memoised 15 minutes under the D22 cache stamp
 *   resolve()           one metric's live value
 *   valueFor()          the single rule the renderer uses for a stored item (`auto` -> live, falling back
 *                       to `manual_value` when the live value is null **or zero**; `manual` -> `manual_value`)
 *   valueForSnapshot()  the same rule for a published snapshot item (`<x-site.stats>` prefers it)
 *
 * Invariants:
 *
 *   · **INV-12 — decimal strings, never floats; unresolvable renders nothing.** Every value leaving this
 *     class is a string such as `"1248.00"`, or null. A metric whose module is disabled, whose table (or
 *     a column it filters on) is absent, or whose query fails resolves to **null** — never `"0.00"` —
 *     so a statistics strip never claims "0 Students Trained" because a later phase is not installed.
 *     An `auto` item whose live count is **zero** renders the same way (review round 2): its manual value,
 *     or nothing — so a later phase's freshly migrated, still empty table never puts a "0" on the site.
 *   · **Guarded twice.** A counted metric runs only after `Modules::enabled(module())` **and** a schema
 *     probe proving its table and filtered columns exist (one probe for every table, one UNION ALL
 *     count — a bounded query budget however many later phases install); a failure of a later phase's
 *     table is reported, never thrown into a public page.
 *   · **`years_experience`** is `now()->year - company.founded_year`, and null when the setting is
 *     empty, non-numeric or in the future.
 *   · **Cached, never stale past a publish.** The resolved set is stored for 15 minutes under a
 *     `CacheVersion` key, so any publish makes the next read recount.
 *   · **Read-only.** This class never writes.
 */
final class StatisticsProvider
{
    /** §6.11: "all of it behind one 15-minute cache entry keyed by the public cache version". */
    public const CACHE_SECONDS = 900;

    private const CACHE_NAMESPACE = 'statistics';

    /**
     * What each counted metric counts (phase-03 §13.3). The owning phases' status enums do not exist
     * yet, so their contracted values are named here once: phase-06 `ProjectStatus::Completed`,
     * phase-05 `ClientStatus::Active`, phase-14-17 `StudentStatus::Completed` and
     * `CourseStatus::Published`, phase-04 team members `published` and `is_public`.
     *
     * @var array<string, array{where: array<string, list<string>>, flags: list<string>}>
     */
    private const SOURCES = [
        'projects_completed' => ['where' => ['status' => ['completed']], 'flags' => []],
        'happy_clients' => ['where' => ['status' => ['active']], 'flags' => []],
        'students_trained' => ['where' => ['status' => ['completed']], 'flags' => []],
        'active_courses' => ['where' => ['status' => ['published']], 'flags' => []],
        'team_members' => ['where' => ['status' => ['published']], 'flags' => ['is_public']],
    ];

    private const DECIMAL_PATTERN = '/^-?\d{1,13}(?:\.\d{1,2})?$/';

    /** @var array<string, string|null>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CacheVersion $cache,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Every live metric's resolved value, keyed by the metric's backing value (`manual` is not a live
     * metric and is absent).
     *
     * @return array<string, string|null>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $stored = $this->cache->remember(
            self::CACHE_NAMESPACE,
            ['all'],
            self::CACHE_SECONDS,
            fn (): array => $this->computeAll(),
        );

        return $this->memo = $this->normaliseSet(is_array($stored) ? $stored : $this->computeAll());
    }

    /**
     * One metric's live value, or null when it cannot be resolved (INV-12).
     */
    public function resolve(StatisticMetric $metric): ?string
    {
        if (! $metric->isLive()) {
            return null;
        }

        return $this->all()[$metric->value] ?? null;
    }

    /**
     * The value a stored statistic item renders, or null when it must not render at all.
     */
    public function valueFor(WebsiteSectionItem $item): ?string
    {
        return $this->valueFrom(
            $item->getAttribute('value_mode'),
            $item->getAttribute('metric'),
            $item->getAttribute('manual_value'),
        );
    }

    /**
     * The same rule for one item of a published snapshot (`SnapshotBuilder` shape: `value_mode`,
     * `metric`, `manual_value`, `value`).
     *
     * @param  array<string, mixed>  $item
     */
    public function valueForSnapshot(array $item): ?string
    {
        return $this->valueFrom(
            $item['value_mode'] ?? null,
            $item['metric'] ?? null,
            $item['manual_value'] ?? ($item['value'] ?? null),
        );
    }

    /**
     * Forget the per-instance memo (long-running workers, tests).
     */
    public function refresh(): void
    {
        $this->memo = null;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function valueFrom(mixed $mode, mixed $metric, mixed $manual): ?string
    {
        $mode = $mode instanceof StatisticValueMode ? $mode : StatisticValueMode::tryFrom($this->scalar($mode));
        $manualValue = $this->decimal($manual);

        if ($mode !== StatisticValueMode::Auto) {
            return $manualValue;
        }

        $metric = $metric instanceof StatisticMetric ? $metric : StatisticMetric::tryFrom($this->scalar($metric));
        $live = $metric === null ? null : $this->resolve($metric);

        // build-order F2 / INV-12: a live count of zero is not a claim a public page makes. The day a later
        // phase migrates an empty `team_members` or `clients` table the count becomes "0.00", not null, and
        // the seeded hero would read "0 Team Members". Zero falls back exactly like an unresolved metric:
        // to the typed `manual_value`, else to nothing at all. (`resolve()` still reports the true count.)
        if ($live !== null && Money::isZero($live)) {
            $live = null;
        }

        return $live ?? $manualValue;
    }

    /**
     * @return array<string, string|null>
     */
    /**
     * Every live metric, in a bounded number of queries whatever later phases install (FT-27): one
     * schema probe for all counted tables, then one UNION ALL count over the tables that exist. If the
     * combined count fails, each metric is counted on its own so one broken table nulls only itself.
     *
     * @return array<string, string|null>
     */
    private function computeAll(): array
    {
        $values = [];
        $counted = [];

        foreach (StatisticMetric::cases() as $metric) {
            if (! $metric->isLive()) {
                continue;
            }

            $values[$metric->value] = null;

            if ($metric === StatisticMetric::YearsExperience) {
                try {
                    $values[$metric->value] = $this->yearsExperience();
                } catch (Throwable $exception) {
                    report($exception);
                }

                continue;
            }

            $module = $metric->module();

            if ($module !== null && $metric->table() !== null && isset(self::SOURCES[$metric->value]) && Modules::enabled($module)) {
                $counted[] = $metric;
            }
        }

        if ($counted === []) {
            return $values;
        }

        try {
            $columns = $this->existingColumns(array_map(static fn (StatisticMetric $metric): string => (string) $metric->table(), $counted));
        } catch (Throwable $exception) {
            // A later phase's broken schema must never take the home page down (INV-12).
            report($exception);

            return $values;
        }

        $queries = [];

        foreach ($counted as $metric) {
            $query = $this->countQuery($metric, $columns[(string) $metric->table()] ?? null);

            if ($query !== null) {
                $queries[$metric->value] = $query;
            }
        }

        if ($queries === []) {
            return $values;
        }

        try {
            $union = null;

            foreach ($queries as $key => $query) {
                $part = (clone $query)->selectRaw('? as metric, COUNT(*) as aggregate', [$key]);
                $union = $union === null ? $part : $union->unionAll($part);
            }

            foreach ($union->get() as $row) {
                $values[(string) $row->metric] = sprintf('%d.00', (int) $row->aggregate);
            }
        } catch (Throwable $exception) {
            report($exception);

            foreach ($queries as $key => $query) {
                try {
                    $values[$key] = sprintf('%d.00', (int) $query->count());
                } catch (Throwable $inner) {
                    report($inner);
                    $values[$key] = null;
                }
            }
        }

        return $values;
    }

    /**
     * The counting query of one metric, or null when its table or a filtered column is absent.
     *
     * @param  list<string>|null  $columns  the table's columns (lower-case), null when the table is absent
     */
    private function countQuery(StatisticMetric $metric, ?array $columns): ?Builder
    {
        $source = self::SOURCES[$metric->value] ?? null;
        $table = $metric->table();

        if ($source === null || $table === null || $columns === null) {
            return null;
        }

        foreach (array_merge(array_keys($source['where']), $source['flags']) as $column) {
            if (! in_array(strtolower($column), $columns, true)) {
                return null;
            }
        }

        $query = $this->db->connection()->table($table);

        foreach ($source['where'] as $column => $allowed) {
            $query->whereIn($column, $allowed);
        }

        foreach ($source['flags'] as $column) {
            $query->where($column, true);
        }

        if (in_array('deleted_at', $columns, true)) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }

    /**
     * table => its lower-case column names, for the given tables that exist. One query on MySQL / MariaDB.
     *
     * @param  list<string>  $tables
     * @return array<string, list<string>>
     */
    private function existingColumns(array $tables): array
    {
        $connection = $this->db->connection();
        $prefix = $connection->getTablePrefix();
        $columns = [];

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $names = array_values(array_unique(array_map(static fn (string $table): string => $prefix.$table, $tables)));

            $rows = $connection->select(
                'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.COLUMNS'
                .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.implode(', ', array_fill(0, count($names), '?')).')',
                $names,
            );

            foreach ($rows as $row) {
                $table = substr((string) $row->table_name, strlen($prefix));
                $columns[$table][] = strtolower((string) $row->column_name);
            }

            return $columns;
        }

        $schema = $connection->getSchemaBuilder();

        foreach (array_unique($tables) as $table) {
            if ($schema->hasTable($table)) {
                $columns[$table] = array_map('strtolower', $schema->getColumnListing($table));
            }
        }

        return $columns;
    }

    private function yearsExperience(): ?string
    {
        $founded = $this->settings->get('company.founded_year');

        if (is_string($founded)) {
            $founded = trim($founded);
            $founded = ctype_digit($founded) ? (int) $founded : null;
        }

        if (! is_int($founded) || $founded < 1) {
            return null;
        }

        $year = (int) Carbon::now()->format('Y');

        if ($founded > $year) {
            return null;
        }

        return sprintf('%d.00', $year - $founded);
    }

    /**
     * A cached set is trusted only in the exact shape this class writes.
     *
     * @param  array<mixed>  $stored
     * @return array<string, string|null>
     */
    private function normaliseSet(array $stored): array
    {
        $set = [];

        foreach (StatisticMetric::cases() as $metric) {
            if ($metric->isLive()) {
                $set[$metric->value] = $this->decimal($stored[$metric->value] ?? null);
            }
        }

        return $set;
    }

    /**
     * A decimal string, or null. Integers are accepted as they come from a driver; floats never are.
     */
    private function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return sprintf('%d.00', $value);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match(self::DECIMAL_PATTERN, $value) === 1 ? $value : null;
    }

    private function scalar(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
