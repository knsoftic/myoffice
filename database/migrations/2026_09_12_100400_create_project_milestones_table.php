<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 · §2.4 — project_milestones: §21's milestones, plus the `amount` without which the finance
 * spine's `milestone` commission base cannot exist.
 *
 * `amount` is nullable on purpose: NULL means "not a payment milestone", which is a different fact from
 * `0.00`. `weight` (`decimal(8,4)`, `CHECK > 0`) is the project-progress weighting of §6.3, so a
 * three-week milestone can outweigh a one-day one; `progress_percent` is derived by
 * `ProjectProgressService` and by nothing else (INV-P8).
 *
 * Soft delete rather than hard: once Phase 10 exists, `project_payments.project_milestone_id` must stay
 * resolvable, and `MilestoneService::delete()` refuses outright while a payment references the milestone.
 * A deleted milestone detaches its tasks (`project_milestone_id = null`) — it never deletes them.
 */
return new class extends Migration
{
    private const TABLE = 'project_milestones';

    private const CHECKS = [
        'chk_pms_amount' => '`amount` is null or `amount` >= 0',
        'chk_pms_weight' => '`weight` > 0',
        'chk_pms_progress' => '`progress_percent` >= 0 and `progress_percent` <= 100',
        'chk_pms_dates' => '`start_date` is null or `deadline` is null or `deadline` >= `start_date`',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->date('start_date')->nullable();
                $table->date('deadline')->nullable();
                // [spine] NULL = not a payment milestone, which is not the same fact as 0.00.
                $table->decimal('amount', 15, 2)->nullable();
                $table->decimal('weight', 8, 4)->default(1);
                $table->decimal('progress_percent', 8, 4)->default(0);
                $table->string('status', 32)->default('pending');
                $table->integer('sort_order')->default(0);
                $table->date('completed_on')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.4 Keys.
                $table->index(['project_id', 'sort_order']);
                $table->index(['project_id', 'status']);
                $table->index('deadline');
            });
        }

        foreach (self::CHECKS as $name => $expression) {
            $this->addCheck($name, $expression);
        }
    }

    public function down(): void
    {
        // tasks.project_milestone_id and (later) project_payments.project_milestone_id point here; both
        // live in later migrations and are rolled back first.
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
