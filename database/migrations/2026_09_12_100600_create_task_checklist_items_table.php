<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 · §2.6 — task_checklist_items: §22's checklists.
 *
 * `chk_tci_done` keeps the pair honest: a done item always carries `done_at`, so "when was this ticked"
 * can never be unanswerable. Toggling an item recomputes `tasks.checklist_total` / `checklist_done` over
 * non-trashed rows by COUNT under a row lock — never by incrementing — and then walks the progress chain
 * (INV-P6, §6.3).
 */
return new class extends Migration
{
    private const TABLE = 'task_checklist_items';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->string('title', 255);
                $table->boolean('is_done')->default(false);
                $table->timestamp('done_at')->nullable();
                $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.6 Keys.
                $table->index(['task_id', 'sort_order']);
                $table->index(['task_id', 'is_done']);
            });
        }

        $this->addCheck('chk_tci_done', '`is_done` = 0 or `done_at` is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function addCheck(string $name, string $expression): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->hasCheck($name)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', self::TABLE, $name, $expression));
    }

    private function hasCheck(string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), self::TABLE, $name]
        ) !== [];
    }
};
