<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17 · file 2 — `batch_topic_coverage`: §83 at class level (phase-14-17 §2.25).
 *
 * **"This batch covered this topic on this date."** One row per (batch, topic), upserted on
 * `uq_btc` — which is why there is **no `deleted_at`** ([D-IN-2]): a soft-deleted row would sit under
 * that unique index and turn the next mark into a 1062 nobody could explain. A topic that should stop
 * counting is `skipped`, which takes its weight out of both sides of the percentage.
 *
 * **`cascadeOnDelete` on the three curriculum keys, and that is safe here** — unlike on the student
 * rows — because coverage is a statement about a batch and a topic. If either is really deleted there
 * is nothing left for the row to say. INV-I13 keeps a referenced topic from being deleted at all; the
 * cascade is the floor under that, not the plan.
 *
 * `course_module_id` is denormalised so the module roll-up is one grouped scan rather than a join
 * through `course_topics` on every recompute — and every recompute touches every topic of a batch.
 */
return new class extends Migration
{
    private const TABLE = 'batch_topic_coverage';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('course_topic_id');
            // Denormalised for the module roll-up.
            $table->unsignedBigInteger('course_module_id');
            // The class that covered it, when one did. A topic can also be marked without a session.
            $table->unsignedBigInteger('class_session_id')->nullable();

            $table->string('status', 16)->default('pending');
            // A topic may be half covered — the class ran out of time and will finish it next week.
            $table->decimal('completion_percentage', 8, 4)->default('0.0000');
            $table->date('covered_on')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['batch_id', 'course_topic_id'], 'uq_btc');

            $table->index(['batch_id', 'status'], 'idx_btc_batch');
            $table->index('course_topic_id', 'idx_btc_topic');
            $table->index('course_module_id', 'idx_btc_module');
            $table->index('class_session_id', 'idx_btc_session');
            $table->index('covered_on', 'idx_btc_covered');
            $table->index('teacher_id', 'idx_btc_teacher');

            $table->foreign('batch_id', RawSchema::foreignKeyName(self::TABLE, 'batch_id'))
                ->references('id')->on('batches')->cascadeOnDelete();
            $table->foreign('course_topic_id', RawSchema::foreignKeyName(self::TABLE, 'course_topic_id'))
                ->references('id')->on('course_topics')->cascadeOnDelete();
            $table->foreign('course_module_id', RawSchema::foreignKeyName(self::TABLE, 'course_module_id'))
                ->references('id')->on('course_modules')->cascadeOnDelete();
            // nullOnDelete: losing the class does not unmark the topic. It was still covered.
            $table->foreign('class_session_id', RawSchema::foreignKeyName(self::TABLE, 'class_session_id'))
                ->references('id')->on('class_sessions')->nullOnDelete();
            $table->foreign('teacher_id', RawSchema::foreignKeyName(self::TABLE, 'teacher_id'))
                ->references('id')->on('teachers')->nullOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_btc_pct', '`completion_percentage` BETWEEN 0 AND 100');
        $this->ensure('chk_btc_status',
            "`status` IN ('pending', 'in_progress', 'completed', 'skipped')");
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }
};
