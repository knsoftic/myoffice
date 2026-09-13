<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 · §1 — settings audit columns.
 *
 * `updated_by` is stamped by App\Services\Core\SettingsService so every settings group can show
 * "last updated by X"; `is_readonly` marks a key that may only change through the console or the
 * environment, so the settings screen renders it disabled and the Form Request refuses it.
 *
 * Additive only, guarded with hasColumn so a re-run on live data is a no-op.
 */
return new class extends Migration
{
    /**
     * Columns added by this migration, in contract order.
     *
     * @var list<string>
     */
    private array $columns = [
        'updated_by',
        'is_readonly',
    ];

    /**
     * Columns carrying a foreign key.
     *
     * @var list<string>
     */
    private array $foreignColumns = ['updated_by'];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('settings', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('settings', 'is_readonly')) {
                $table->boolean('is_readonly')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        // MariaDB refuses to drop a column that still backs a foreign key, so the constraints go
        // first, in their own ALTER statement.
        $foreignKeys = $this->foreignKeysByColumn();

        $droppable = array_values(array_filter(
            $this->foreignColumns,
            fn (string $column): bool => isset($foreignKeys[$column])
        ));

        if ($droppable !== []) {
            Schema::table('settings', function (Blueprint $table) use ($droppable, $foreignKeys): void {
                foreach ($droppable as $column) {
                    $table->dropForeign($foreignKeys[$column]);
                }
            });
        }

        $columns = array_values(array_filter(
            $this->columns,
            fn (string $column): bool => Schema::hasColumn('settings', $column)
        ));

        if ($columns !== []) {
            Schema::table('settings', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    /**
     * Map single-column foreign keys on `settings` to their constraint name.
     *
     * @return array<string, string>
     */
    private function foreignKeysByColumn(): array
    {
        $map = [];

        foreach (Schema::getForeignKeys('settings') as $foreignKey) {
            if (count($foreignKey['columns']) === 1) {
                $map[$foreignKey['columns'][0]] = $foreignKey['name'];
            }
        }

        return $map;
    }
};
