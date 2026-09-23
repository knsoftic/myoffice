<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 · file 4 — `meetings` (phase-19-23 §2.20, requirement §95).
 *
 * **`ends_at` is a generated STORED column, and that is an overlap query talking.** A room booking
 * clash is `a.starts < b.ends AND b.starts < a.ends`, and computing the right-hand side per row
 * would mean `scheduled_at + INTERVAL duration_minutes MINUTE` inside the predicate — unindexable,
 * and re-derived for every candidate on every check. Storing it makes `(classroom_id, scheduled_at)`
 * a usable index and lets `ScheduleClashDetector` ask the same question of a meeting it asks of a
 * class.
 *
 * **Nine nullable subject foreign keys, and two of them are other phases' asks.** `client_id` is
 * phase-05 §13's, `collaborator_id` is phase-08-09 §13's, and `support_ticket_id` is the call booked
 * off a ticket. A meeting has one subject or none; a polymorphic pair would have cost every §9 scope
 * its index, which on a calendar is every query there is.
 *
 * **`rescheduled_from_id` is UNIQUE, so the history is a chain and not a fan.** A meeting that
 * slipped three times reads as three rows pointing back one at a time. Two rows claiming the same
 * predecessor would make "what did this become?" a question with two answers, which is exactly the
 * `uq_ce_reissue` reasoning of Phase 21 applied to a diary.
 *
 * **`cancellation_reason` is mandatory on `cancelled` and `postponed`, by CHECK** — people whose
 * afternoon was cleared are owed a sentence, and a reason that the application merely asks for
 * politely is a reason half the rows will not have.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures the rest.
 */
return new class extends Migration
{
    private const TABLE = 'meetings';

    /** The subject foreign keys, and the table each points at. All empty rather than block. */
    private const SUBJECTS = [
        'classroom_id' => 'classrooms',
        'project_id' => 'projects',
        'course_id' => 'courses',
        'batch_id' => 'batches',
        'client_id' => 'clients',
        'lead_id' => 'leads',
        'collaborator_id' => 'collaborators',
        'support_ticket_id' => 'support_tickets',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->generated();
        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->string('title', 180);
            $table->unsignedBigInteger('branch_id')->nullable();

            // `restrictOnDelete`: a meeting with no organiser is a meeting nobody owns, and §95
            // makes the organiser part of what a meeting is.
            $table->unsignedBigInteger('organizer_id');

            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes');

            $table->string('delivery_mode', 32)->default('online');
            $table->string('location', 255)->nullable();
            $table->string('meeting_url', 500)->nullable();

            $table->text('agenda')->nullable();
            // Minutes. §9.4 decides who reads them — a client participant only once the meeting is
            // completed, and never a portal user who was not in the room.
            $table->longText('notes')->nullable();

            foreach (array_keys(self::SUBJECTS) as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }

            $table->string('status', 32)->default('scheduled');
            $table->boolean('is_private')->default(false);

            $table->unsignedSmallInteger('reminder_minutes_before')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('second_reminder_sent_at')->nullable();

            // CACHES. Recounted, never incremented in place.
            $table->unsignedSmallInteger('participants_count')->default(0);
            $table->unsignedSmallInteger('accepted_count')->default(0);
            $table->unsignedSmallInteger('declined_count')->default(0);
            $table->unsignedSmallInteger('attended_count')->default(0);

            $table->string('cancellation_reason', 255)->nullable();
            $table->unsignedBigInteger('rescheduled_from_id')->nullable();
            $table->string('outcome_summary', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['scheduled_at', 'status'], 'idx_me_calendar');
            $table->index(['organizer_id', 'scheduled_at'], 'idx_me_organizer');
            $table->index(['status', 'scheduled_at'], 'idx_me_status');
            $table->index(['classroom_id', 'scheduled_at'], 'idx_me_room');
            $table->index(['branch_id', 'scheduled_at'], 'idx_me_branch');
            $table->index('project_id', 'idx_me_project');
            $table->index('course_id', 'idx_me_course');
            $table->index('batch_id', 'idx_me_batch');
            $table->index('client_id', 'idx_me_client');
            $table->index('collaborator_id', 'idx_me_collaborator');
            $table->index('reminder_sent_at', 'idx_me_reminder');

            // D125: these three lead no composite above, so each carries its own.
            $table->index('lead_id', 'idx_me_lead');
            $table->index('support_ticket_id', 'idx_me_ticket');
            $table->index('created_by', 'idx_me_created_by');
            $table->index('updated_by', 'idx_me_updated_by');

            $table->foreign('organizer_id', RawSchema::foreignKeyName(self::TABLE, 'organizer_id'))
                ->references('id')->on('users')->restrictOnDelete();

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();

            foreach (self::SUBJECTS as $column => $references) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on($references)->nullOnDelete();
            }

            $table->foreign('rescheduled_from_id', RawSchema::foreignKeyName(self::TABLE, 'rescheduled_from_id'))
                ->references('id')->on(self::TABLE)->nullOnDelete();

            foreach (['created_by', 'updated_by'] as $column) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    /**
     * `ends_at`, and the unique that makes a reschedule a chain.
     *
     * Both are ensured every run rather than only on create: D70 means a half-applied migration can
     * leave the table without them, and an overlap query silently reading a missing column is not a
     * failure mode worth having.
     */
    private function generated(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'ends_at')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'ends_at',
                'datetime',
                '`scheduled_at` + INTERVAL `duration_minutes` MINUTE',
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'idx_me_ends')) {
            RawSchema::index(self::TABLE, 'idx_me_ends', ['ends_at']);
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_me_resched', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_me_resched', ['rescheduled_from_id']);
        }
    }

    private function constraints(): void
    {
        // Five minutes is the shortest meeting worth booking a room for; a day is the longest thing
        // that is still one meeting. Outside that somebody has typed into the wrong field.
        $this->ensure('chk_me_duration', '`duration_minutes` BETWEEN 5 AND 1440');

        // An online meeting with no link is an invitation nobody can accept.
        $this->ensure('chk_me_url', "`delivery_mode` <> 'online' OR `meeting_url` IS NOT NULL");

        $this->ensure(
            'chk_me_cancel',
            "`status` NOT IN ('cancelled','postponed') OR `cancellation_reason` IS NOT NULL",
        );

        $this->ensure(
            'chk_me_counts',
            '`participants_count` >= 0 AND `accepted_count` >= 0'
            .' AND `declined_count` >= 0 AND `attended_count` >= 0',
        );
    }

    public function down(): void
    {
        // Three things have to come apart in order, and getting it wrong is a 1553 each time.
        //
        // **The self-referencing foreign key goes first.** `meetings_rescheduled_from_id_foreign`
        // needs `uq_me_resched` — a foreign key requires an index on its own column, and this is the
        // only one — so MariaDB refuses to drop that index while the constraint exists. It is easy
        // to miss because the dependency runs the opposite way from the usual one.
        //
        // **Then the indexes that read the generated column**, because dropping a column an index
        // depends on is the same error, and the rollback test runs this over a table holding rows.
        if (Schema::hasTable(self::TABLE)) {
            if ($this->selfKeyExists()) {
                Schema::table(self::TABLE, function ($table): void {
                    $table->dropForeign(RawSchema::foreignKeyName(self::TABLE, 'rescheduled_from_id'));
                });
            }

            if (RawSchema::indexExists(self::TABLE, 'uq_me_resched', true)) {
                RawSchema::dropIndex(self::TABLE, 'uq_me_resched');
            }

            if (RawSchema::indexExists(self::TABLE, 'idx_me_ends')) {
                RawSchema::dropIndex(self::TABLE, 'idx_me_ends');
            }

            if (Schema::hasColumn(self::TABLE, 'ends_at')) {
                RawSchema::dropColumn(self::TABLE, 'ends_at');
            }
        }

        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }

    /** Asked of `information_schema` by name, filtered to this schema — never assumed. */
    private function selfKeyExists(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS present FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [self::TABLE, RawSchema::foreignKeyName(self::TABLE, 'rescheduled_from_id'), 'FOREIGN KEY'],
        ) !== null;
    }
};
