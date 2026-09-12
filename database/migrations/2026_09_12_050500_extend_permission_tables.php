<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 · §1.2 — extend spatie's `permissions` and `roles` tables.
 *
 * The vendor migration is published unchanged; everything the project needs on top
 * of it lives here. Table names are read from config/permission.php so a rename
 * there keeps working.
 *
 * NOTE: `permissions.module` and `permissions.ability` are NOT NULL with no default
 * (per contract). Always create permissions through PermissionRegistry so both are
 * populated.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $permissionColumns = [
        'module',
        'ability',
        'group',
        'label',
        'description',
        'sort_order',
    ];

    /**
     * @var list<string>
     */
    private array $roleColumns = [
        'label',
        'description',
        'panel',
        'level',
        'is_system',
        'is_default',
        'created_by',
        'updated_by',
    ];

    /**
     * @var list<string>
     */
    private array $roleForeignColumns = ['created_by', 'updated_by'];

    public function up(): void
    {
        $permissions = $this->permissionsTable();
        $roles = $this->rolesTable();

        Schema::table($permissions, function (Blueprint $table) use ($permissions): void {
            if (! Schema::hasColumn($permissions, 'module')) {
                $table->string('module', 64)->index();
            }

            if (! Schema::hasColumn($permissions, 'ability')) {
                $table->string('ability', 32);
            }

            if (! Schema::hasColumn($permissions, 'group')) {
                $table->string('group', 64)->nullable();
            }

            if (! Schema::hasColumn($permissions, 'label')) {
                $table->string('label', 150)->nullable();
            }

            if (! Schema::hasColumn($permissions, 'description')) {
                $table->string('description', 255)->nullable();
            }

            if (! Schema::hasColumn($permissions, 'sort_order')) {
                $table->integer('sort_order')->default(0);
            }
        });

        Schema::table($roles, function (Blueprint $table) use ($roles): void {
            if (! Schema::hasColumn($roles, 'label')) {
                $table->string('label', 150)->nullable();
            }

            if (! Schema::hasColumn($roles, 'description')) {
                $table->string('description', 255)->nullable();
            }

            if (! Schema::hasColumn($roles, 'panel')) {
                $table->string('panel', 32)->default('admin')->index();
            }

            if (! Schema::hasColumn($roles, 'level')) {
                $table->unsignedSmallInteger('level')->default(50);
            }

            if (! Schema::hasColumn($roles, 'is_system')) {
                $table->boolean('is_system')->default(false);
            }

            if (! Schema::hasColumn($roles, 'is_default')) {
                $table->boolean('is_default')->default(false);
            }

            if (! Schema::hasColumn($roles, 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn($roles, 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $permissions = $this->permissionsTable();
        $roles = $this->rolesTable();

        if (Schema::hasTable($roles)) {
            // Foreign keys must go before the columns that back them (MariaDB).
            $foreignKeys = $this->foreignKeysByColumn($roles);

            $droppable = array_values(array_filter(
                $this->roleForeignColumns,
                fn (string $column): bool => isset($foreignKeys[$column])
            ));

            if ($droppable !== []) {
                Schema::table($roles, function (Blueprint $table) use ($droppable, $foreignKeys): void {
                    foreach ($droppable as $column) {
                        $table->dropForeign($foreignKeys[$column]);
                    }
                });
            }

            $this->dropColumns($roles, $this->roleColumns);
        }

        if (Schema::hasTable($permissions)) {
            $this->dropColumns($permissions, $this->permissionColumns);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column)
        ));

        if ($existing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($existing): void {
            $blueprint->dropColumn($existing);
        });
    }

    /**
     * @return array<string, string>
     */
    private function foreignKeysByColumn(string $table): array
    {
        $map = [];

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (count($foreignKey['columns']) === 1) {
                $map[$foreignKey['columns'][0]] = $foreignKey['name'];
            }
        }

        return $map;
    }

    private function permissionsTable(): string
    {
        return (string) config('permission.table_names.permissions', 'permissions');
    }

    private function rolesTable(): string
    {
        return (string) config('permission.table_names.roles', 'roles');
    }
};
