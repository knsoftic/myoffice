<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 · §1 — module dependency awareness and disable audit trail.
 *
 * Additive only: nothing existing on `modules` is touched. `depends_on` holds an array of module
 * slugs (resolved through App\Support\PermissionRegistry, never a foreign key — a dependency may
 * name a module that has not been seeded yet), and the three audit columns record who turned a
 * module off, when, and why.
 *
 * Every column is guarded with hasColumn so the migration is safe to re-run on a database that
 * already holds real data.
 */
return new class extends Migration
{
    /**
     * Columns added by this migration, in contract order.
     *
     * @var list<string>
     */
    private array $columns = [
        'depends_on',
        'disabled_at',
        'disabled_by',
        'disable_reason',
    ];

    /**
     * Columns carrying a foreign key.
     *
     * @var list<string>
     */
    private array $foreignColumns = ['disabled_by'];

    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        Schema::table('modules', function (Blueprint $table): void {
            if (! Schema::hasColumn('modules', 'depends_on')) {
                $table->json('depends_on')->nullable();
            }

            if (! Schema::hasColumn('modules', 'disabled_at')) {
                $table->timestamp('disabled_at')->nullable();
            }

            if (! Schema::hasColumn('modules', 'disabled_by')) {
                $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('modules', 'disable_reason')) {
                $table->string('disable_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('modules')) {
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
            Schema::table('modules', function (Blueprint $table) use ($droppable, $foreignKeys): void {
                foreach ($droppable as $column) {
                    $table->dropForeign($foreignKeys[$column]);
                }
            });
        }

        $columns = array_values(array_filter(
            $this->columns,
            fn (string $column): bool => Schema::hasColumn('modules', $column)
        ));

        if ($columns !== []) {
            Schema::table('modules', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    /**
     * Map single-column foreign keys on `modules` to their constraint name.
     *
     * @return array<string, string>
     */
    private function foreignKeysByColumn(): array
    {
        $map = [];

        foreach (Schema::getForeignKeys('modules') as $foreignKey) {
            if (count($foreignKey['columns']) === 1) {
                $map[$foreignKey['columns'][0]] = $foreignKey['name'];
            }
        }

        return $map;
    }
};
