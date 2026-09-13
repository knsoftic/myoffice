<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D61 · data migration — pin the database session to UTC and re-base every TIMESTAMP value.
 *
 * **The defect.** MariaDB converts a `TIMESTAMP` literal from the *session* time zone to UTC before
 * storing it, and back again on read. Until `config/database.php` pinned `'timezone' => '+00:00'` every
 * session inherited `@@GLOBAL.time_zone` (`SYSTEM`, i.e. Asia/Karachi on the dev host). Laravel writes
 * UTC wall-clock strings, so each stored instant sat five hours before the moment it names; reads only
 * looked right because every client shared the same session zone. A dump restored on a UTC host, or any
 * client with another session zone, would have shifted every row. `DATETIME` and `DATE` columns never
 * convert and are not touched.
 *
 * **What this does.** With the connection now pinned to `+00:00`, every non-NULL, non-zero value in
 * every `timestamp` column of every base table in the current schema is rewritten to
 * `CONVERT_TZ(value, '+00:00', <old session zone>)` — exactly the string the old session read returned —
 * so a read under the pinned session returns the same bytes it returned before. The conversion is per
 * value (not a fixed offset), so a host zone with daylight saving is re-based correctly on both sides of
 * the change. One `UPDATE` per table, all inside one transaction; every table here is InnoDB, and the
 * run refuses to start if a table holding values is not transactional.
 *
 * **When it is a no-op.** When the old session zone is UTC-equivalent — its offset is zero now and in
 * January and July of this year — nothing is read or written: a fresh install or a UTC host. On the empty
 * database `RefreshDatabase` builds, every `UPDATE` matches zero rows.
 *
 * **Guards.** The run refuses (and changes nothing) when the connection is not pinned to `+00:00`, since
 * converting values an unpinned application would then misread is the very break this prevents; and when
 * any value cannot be converted (`CONVERT_TZ` returns NULL — a named zone without time-zone tables, or an
 * out-of-range result), because assigning NULL to a `NOT NULL TIMESTAMP` silently stamps "now".
 *
 * **down()** applies the inverse, `CONVERT_TZ(value, <old session zone>, '+00:00')`, restoring the
 * pre-pin storage. It is only meaningful together with removing the `timezone` pin from
 * `config/database.php`, and it assumes `@@GLOBAL.time_zone` has not changed since `up()` ran.
 */
return new class extends Migration
{
    /** The zone D61 pins every session to. */
    private const PINNED = '+00:00';

    public function up(): void
    {
        $this->rebase(forward: true);
    }

    public function down(): void
    {
        $this->rebase(forward: false);
    }

    /**
     * forward: stored-under-old-zone -> stored-as-UTC. Inverse otherwise.
     */
    private function rebase(bool $forward): void
    {
        $connection = DB::connection($this->getConnection());

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return; // Only MySQL/MariaDB convert TIMESTAMP values through the session zone.
        }

        // The zone every unpinned session inherited — what the old reads were rendered in.
        $legacyZone = (string) $connection->scalar('select @@GLOBAL.time_zone');

        if ($this->isUtcEquivalent($connection, $legacyZone)) {
            return;
        }

        $session = (string) $connection->scalar('select @@SESSION.time_zone');

        if ($session !== self::PINNED) {
            throw new RuntimeException(sprintf(
                "D61: this connection's session time_zone is '%s'. Pin 'timezone' => '%s' on the mysql/mariadb "
                .'connections in config/database.php (and clear any cached config) before running this migration; '
                .'nothing was changed.',
                $session,
                self::PINNED,
            ));
        }

        [$from, $to] = $forward ? [self::PINNED, $legacyZone] : [$legacyZone, self::PINNED];

        $tables = $this->timestampColumnsByTable($connection);

        $connection->transaction(function () use ($connection, $tables, $from, $to): void {
            foreach ($tables as $table => ['engine' => $engine, 'columns' => $columns]) {
                $this->convertTable($connection, $table, $engine, $columns, $from, $to);
            }
        });
    }

    /**
     * Is the zone's offset zero now, in mid-January and in mid-July? (Both hemispheres' DST seasons.)
     */
    private function isUtcEquivalent(Connection $connection, string $zone): bool
    {
        if (in_array(strtoupper($zone), [self::PINNED, 'UTC', '+0:00', '-00:00'], true)) {
            return true;
        }

        $january = gmdate('Y').'-01-15 12:00:00';
        $july = gmdate('Y').'-07-15 12:00:00';

        $row = $connection->selectOne(
            "select
                timestampdiff(second, utc_timestamp(), convert_tz(utc_timestamp(), '+00:00', ?)) as now_offset,
                timestampdiff(second, ?, convert_tz(?, '+00:00', ?)) as january_offset,
                timestampdiff(second, ?, convert_tz(?, '+00:00', ?)) as july_offset",
            [$zone, $january, $january, $zone, $july, $july, $zone],
        );

        $offsets = [$row->now_offset, $row->january_offset, $row->july_offset];

        if (in_array(null, $offsets, true)) {
            throw new RuntimeException(sprintf(
                "D61: the server cannot convert from its time_zone '%s' (CONVERT_TZ returned NULL — are the time "
                .'zone tables loaded?). Nothing was changed.',
                $zone,
            ));
        }

        return array_sum(array_map(static fn ($offset): int => abs((int) $offset), $offsets)) === 0;
    }

    /**
     * table => [engine, list of timestamp columns], base tables of the current schema only (a view's
     * columns are listed in information_schema too, and are not storage).
     *
     * @return array<string, array{engine: string, columns: list<string>}>
     */
    private function timestampColumnsByTable(Connection $connection): array
    {
        $rows = $connection->select(
            "select c.TABLE_NAME as table_name, c.COLUMN_NAME as column_name, coalesce(t.ENGINE, '') as engine
               from information_schema.COLUMNS c
               join information_schema.TABLES t
                 on t.TABLE_SCHEMA = c.TABLE_SCHEMA and t.TABLE_NAME = c.TABLE_NAME
              where c.TABLE_SCHEMA = database()
                and t.TABLE_TYPE = 'BASE TABLE'
                and c.DATA_TYPE = 'timestamp'
              order by c.TABLE_NAME, c.ORDINAL_POSITION"
        );

        $tables = [];

        foreach ($rows as $row) {
            $tables[$row->table_name]['engine'] = (string) $row->engine;
            $tables[$row->table_name]['columns'][] = (string) $row->column_name;
        }

        return $tables;
    }

    /**
     * One UPDATE for the table. Every timestamp column is assigned explicitly (a NULL or zero value to
     * itself), so no `ON UPDATE CURRENT_TIMESTAMP` column can re-stamp the row.
     *
     * @param  list<string>  $columns
     */
    private function convertTable(Connection $connection, string $table, string $engine, array $columns, string $from, string $to): void
    {
        $quotedTable = $this->quote($table);

        // A row is touched only when at least one of its timestamp columns holds a real value.
        $holdsValue = implode(' or ', array_map(
            fn (string $column): string => '('.$this->quote($column).' is not null and unix_timestamp('.$this->quote($column).') <> 0)',
            $columns,
        ));

        $pending = (int) $connection->scalar("select count(*) from {$quotedTable} where {$holdsValue}");

        if ($pending === 0) {
            return;
        }

        if (strcasecmp($engine, 'InnoDB') !== 0) {
            throw new RuntimeException(sprintf(
                "D61: table '%s' uses the non-transactional engine '%s' and holds timestamps; converting it could not be "
                .'rolled back. Nothing was changed.',
                $table,
                $engine,
            ));
        }

        // Refuse before writing when any value would convert to NULL (see the class docblock).
        $unconvertible = implode(' or ', array_map(
            fn (string $column): string => sprintf(
                '(%1$s is not null and unix_timestamp(%1$s) <> 0 and convert_tz(%1$s, ?, ?) is null)',
                $this->quote($column),
            ),
            $columns,
        ));

        $bindings = [];
        foreach ($columns as $column) {
            array_push($bindings, $from, $to);
        }

        $failures = (int) $connection->scalar("select count(*) from {$quotedTable} where {$unconvertible}", $bindings);

        if ($failures > 0) {
            throw new RuntimeException(sprintf(
                "D61: %d row(s) of '%s' hold a timestamp that cannot be converted from %s to %s. Nothing was changed.",
                $failures,
                $table,
                $from,
                $to,
            ));
        }

        $assignments = implode(', ', array_map(
            fn (string $column): string => sprintf(
                '%1$s = case when %1$s is null or unix_timestamp(%1$s) = 0 then %1$s else convert_tz(%1$s, ?, ?) end',
                $this->quote($column),
            ),
            $columns,
        ));

        $connection->update("update {$quotedTable} set {$assignments} where {$holdsValue}", $bindings);
    }

    private function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
};
