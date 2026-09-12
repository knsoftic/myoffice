<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 · §1.7 — extend spatie's `activity_log` table.
 *
 * Adds the request context filled in by LogsActivityWithContext::tapActivity()
 * plus the free-text `reason` used by audit entries. Connection and table name
 * come from config/activitylog.php, like the vendor migrations.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $columns = [
        'ip_address',
        'user_agent',
        'device',
        'module',
        'reason',
    ];

    public function up(): void
    {
        $connection = $this->connection();
        $table = $this->table();

        Schema::connection($connection)->table($table, function (Blueprint $blueprint) use ($connection, $table): void {
            if (! Schema::connection($connection)->hasColumn($table, 'ip_address')) {
                $blueprint->string('ip_address', 45)->nullable();
            }

            if (! Schema::connection($connection)->hasColumn($table, 'user_agent')) {
                $blueprint->text('user_agent')->nullable();
            }

            if (! Schema::connection($connection)->hasColumn($table, 'device')) {
                $blueprint->string('device', 64)->nullable();
            }

            if (! Schema::connection($connection)->hasColumn($table, 'module')) {
                $blueprint->string('module', 64)->nullable()->index();
            }

            if (! Schema::connection($connection)->hasColumn($table, 'reason')) {
                $blueprint->string('reason', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        $connection = $this->connection();
        $table = $this->table();

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        $existing = array_values(array_filter(
            $this->columns,
            fn (string $column): bool => Schema::connection($connection)->hasColumn($table, $column)
        ));

        if ($existing === []) {
            return;
        }

        Schema::connection($connection)->table($table, function (Blueprint $blueprint) use ($existing): void {
            $blueprint->dropColumn($existing);
        });
    }

    private function connection(): ?string
    {
        $connection = config('activitylog.database_connection');

        return $connection === null ? null : (string) $connection;
    }

    private function table(): string
    {
        return (string) config('activitylog.table_name', 'activity_log');
    }
};
