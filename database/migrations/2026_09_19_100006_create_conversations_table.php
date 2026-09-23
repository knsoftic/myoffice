<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 · file 6 — `conversations` (phase-19-23 §2.22, requirement §94).
 *
 * **`pair_scope` is stored, and that is INV-22-4.** It records *which of §94's six pairs authorised
 * this thread*, so `MessagingMatrix::mayParticipate()` can re-check it on **every send** rather than
 * once at creation. A check made only at creation leaves yesterday's threads running under today's
 * policy: switch `student_staff` off in `support.messaging_allowed_pairs` and every existing student
 * thread would keep working, which is the opposite of what switching it off means.
 *
 * **`direct_key` is the guard that makes one thread per pair** (INV-22-5). It is the sha256 of the
 * sorted participant ids, so messaging the same person twice reopens the thread you already had
 * instead of starting a second one that splits the history — `startDirect()` catches the 1062 on
 * `uq_cv_direct` and **returns the existing thread** rather than failing. A group has no key, because
 * two groups with the same members are a legitimate thing to want.
 *
 * **`last_message_id` gets its column here and its foreign key in file 9.** `conversations` and
 * `messages` point at each other, so one of the two constraints has to be added after both tables
 * exist. The column is written here so nothing has to `ALTER` a table it did not create, and the
 * follow-up does one thing.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures the rest.
 */
return new class extends Migration
{
    private const TABLE = 'conversations';

    private const CONTEXTS = [
        'project_id' => 'projects',
        'course_id' => 'courses',
        'batch_id' => 'batches',
        'support_ticket_id' => 'support_tickets',
    ];

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

            $table->string('type', 32)->default('direct');
            $table->string('subject', 180)->nullable();

            // See the class note. Not derivable at read time: the roles that authorised the thread
            // may have changed since, and the stored value is what the re-check compares against.
            $table->string('pair_scope', 32);

            foreach (array_keys(self::CONTEXTS) as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }

            $table->char('direct_key', 64)->nullable();

            // The foreign key lands in file 9 — see the class note.
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();

            // CACHES.
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedSmallInteger('participants_count')->default(0);

            $table->boolean('is_closed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('direct_key', 'uq_cv_direct');

            // The inbox is ordered by this, and nothing else.
            $table->index('last_message_at', 'idx_cv_recent');
            $table->index(['type', 'is_closed'], 'idx_cv_type');
            $table->index('pair_scope', 'idx_cv_scope');
            $table->index('project_id', 'idx_cv_project');
            $table->index('course_id', 'idx_cv_course');
            $table->index('support_ticket_id', 'idx_cv_ticket');

            // D125: these lead no composite above.
            $table->index('batch_id', 'idx_cv_batch');
            $table->index('last_message_id', 'idx_cv_last_message');
            $table->index('closed_by', 'idx_cv_closer');
            $table->index('created_by', 'idx_cv_created_by');
            $table->index('updated_by', 'idx_cv_updated_by');

            foreach (self::CONTEXTS as $column => $references) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on($references)->nullOnDelete();
            }

            foreach (['closed_by', 'created_by', 'updated_by'] as $column) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    private function constraints(): void
    {
        // A group without a subject is indistinguishable from every other group with the same people
        // in it.
        $this->ensure('chk_cv_group', "`type` <> 'group' OR `subject` IS NOT NULL");

        // A direct thread without its key is a thread `uq_cv_direct` cannot protect, which means a
        // second one is a matter of time.
        $this->ensure('chk_cv_direct', "`type` <> 'direct' OR `direct_key` IS NOT NULL");

        $this->ensure('chk_cv_counts', '`messages_count` >= 0 AND `participants_count` >= 0');
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
