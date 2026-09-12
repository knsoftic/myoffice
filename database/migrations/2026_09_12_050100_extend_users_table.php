<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 · §1.1 — extend the default Laravel `users` table.
 *
 * Adds profile, status, preference, login-tracking, branch and blameable columns
 * plus soft deletes. Laravel's own migration is left untouched.
 */
return new class extends Migration
{
    /**
     * Columns added by this migration, in contract order.
     *
     * @var list<string>
     */
    private array $columns = [
        'phone',
        'whatsapp',
        'avatar_path',
        'status',
        'status_reason',
        'status_changed_at',
        'theme',
        'locale',
        'timezone',
        'last_login_at',
        'last_login_ip',
        'password_changed_at',
        'must_change_password',
        'branch_id',
        'created_by',
        'updated_by',
        'deleted_at',
    ];

    /**
     * Columns carrying a foreign key.
     *
     * @var list<string>
     */
    private array $foreignColumns = ['branch_id', 'created_by', 'updated_by'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 32)->nullable();
            }

            if (! Schema::hasColumn('users', 'whatsapp')) {
                $table->string('whatsapp', 32)->nullable();
            }

            if (! Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path', 255)->nullable();
            }

            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 32)->default('active')->index();
            }

            if (! Schema::hasColumn('users', 'status_reason')) {
                $table->string('status_reason', 255)->nullable();
            }

            if (! Schema::hasColumn('users', 'status_changed_at')) {
                $table->timestamp('status_changed_at')->nullable();
            }

            if (! Schema::hasColumn('users', 'theme')) {
                $table->string('theme', 16)->default('system');
            }

            if (! Schema::hasColumn('users', 'locale')) {
                $table->string('locale', 8)->default('en');
            }

            if (! Schema::hasColumn('users', 'timezone')) {
                $table->string('timezone', 64)->nullable();
            }

            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
            }

            if (! Schema::hasColumn('users', 'last_login_ip')) {
                $table->string('last_login_ip', 45)->nullable();
            }

            if (! Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable();
            }

            if (! Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false);
            }

            if (! Schema::hasColumn('users', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        // MariaDB refuses to drop a column that still backs a foreign key, so the
        // constraints go first, in their own ALTER statement.
        $foreignKeys = $this->foreignKeysByColumn();

        $droppable = array_values(array_filter(
            $this->foreignColumns,
            fn (string $column): bool => isset($foreignKeys[$column])
        ));

        if ($droppable !== []) {
            Schema::table('users', function (Blueprint $table) use ($droppable, $foreignKeys): void {
                foreach ($droppable as $column) {
                    $table->dropForeign($foreignKeys[$column]);
                }
            });
        }

        $columns = array_values(array_filter(
            $this->columns,
            fn (string $column): bool => Schema::hasColumn('users', $column)
        ));

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    /**
     * Map single-column foreign keys on `users` to their constraint name.
     *
     * @return array<string, string>
     */
    private function foreignKeysByColumn(): array
    {
        $map = [];

        foreach (Schema::getForeignKeys('users') as $foreignKey) {
            if (count($foreignKey['columns']) === 1) {
                $map[$foreignKey['columns'][0]] = $foreignKey['name'];
            }
        }

        return $map;
    }
};
