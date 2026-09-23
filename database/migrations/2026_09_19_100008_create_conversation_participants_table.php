<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 · file 8 — `conversation_participants` (phase-19-23 §2.23, requirement §94).
 *
 * **This table is the whole of §94's isolation.** `ConversationService::threadsFor()` is *always*
 * `conversation_participants.user_id = auth()->id() AND left_at IS NULL` — never a client id, never
 * a company (phase-05 §9.2's rule, restated here because this is the table that would tempt
 * somebody). A conversation is personal: two people at the same client do not share an inbox.
 *
 * **`panel` is snapshotted, not read live, and that is deliberate.** §94's matrix is about roles, and
 * the side somebody participates *as* is decided when they join. Somebody who is a teacher and later
 * becomes an employee does not retroactively turn an old `student_staff` thread into an
 * `admin_employee` one — the thread was authorised under the pairing that existed, and the stored
 * pair is what `mayParticipate()` re-checks.
 *
 * **`active_guard` is a generated STORED column** so `uq_cp_active` permits **one live membership**
 * while every past one stacks behind it: somebody who left a group and was re-added has two rows and
 * one of them is live. MariaDB tolerates unlimited NULLs in a unique index, which is the mechanism —
 * `CASE WHEN left_at IS NULL THEN 1 ELSE NULL`.
 *
 * **`unread_count` is recounted under a row lock, never incremented** (INV-22-6). A `++` on a badge
 * is a number that drifts the first time two sends race, and an inbox that says 3 when there are 2
 * is an inbox people stop trusting.
 *
 * **`user_id` is `restrictOnDelete`.** Everywhere else in this phase a departed user empties a
 * column; here the row *is* the membership, and a thread with a participant row pointing at nobody
 * would be a thread whose isolation query cannot answer. A user is deactivated, not deleted.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures the rest.
 */
return new class extends Migration
{
    private const TABLE = 'conversation_participants';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->guard();
        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');

            // The side they participate as, snapshotted. See the class note.
            $table->string('panel', 32);
            $table->string('role', 32)->default('member');

            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->unsignedSmallInteger('unread_count')->default(0);

            $table->boolean('is_muted')->default(false);

            // `useCurrent()`, not a bare NOT NULL timestamp: MariaDB gives a second timestamp
            // column an implicit zero-date default, which `NO_ZERO_DATE` then refuses — the
            // migration fails with 1067 rather than the column being created wrong. The house
            // pattern since phase-05.
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->unsignedBigInteger('removed_by')->nullable();

            // A pivot: no soft delete. A membership that ended has `left_at`, which is history rather
            // than a hidden row.
            $table->timestamps();

            $table->index(['user_id', 'last_read_at'], 'idx_cp_user');
            $table->index(['conversation_id', 'panel'], 'idx_cp_thread');
            // The bell's message badge: one indexed SUM over this.
            $table->index(['user_id', 'unread_count'], 'idx_cp_unread');

            // D125: neither leads a composite above.
            $table->index('last_read_message_id', 'idx_cp_last_read');
            $table->index('removed_by', 'idx_cp_remover');

            $table->foreign('conversation_id', RawSchema::foreignKeyName(self::TABLE, 'conversation_id'))
                ->references('id')->on('conversations')->cascadeOnDelete();

            // See the class note: the row is the membership.
            $table->foreign('user_id', RawSchema::foreignKeyName(self::TABLE, 'user_id'))
                ->references('id')->on('users')->restrictOnDelete();

            $table->foreign('last_read_message_id', RawSchema::foreignKeyName(self::TABLE, 'last_read_message_id'))
                ->references('id')->on('messages')->nullOnDelete();

            $table->foreign('removed_by', RawSchema::foreignKeyName(self::TABLE, 'removed_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /** One live membership per person per thread. NULL for the rest is the mechanism. */
    private function guard(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'active_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'active_guard',
                'tinyint',
                'CASE WHEN `left_at` IS NULL THEN 1 ELSE NULL END',
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_cp_active', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_cp_active', ['conversation_id', 'user_id', 'active_guard']);
        }
    }

    private function constraints(): void
    {
        $this->ensure('chk_cp_unread', '`unread_count` >= 0');

        // Leaving cannot precede joining. A row where it did would make "how long were they in this
        // thread?" a negative number in the §99 figures.
        $this->ensure('chk_cp_dates', '`left_at` IS NULL OR `left_at` >= `joined_at`');
    }

    public function down(): void
    {
        // The index reads the generated column, so it goes first: dropping a column an index depends
        // on is error 1553, and the rollback test runs this over a table holding rows.
        if (Schema::hasTable(self::TABLE)) {
            if (RawSchema::indexExists(self::TABLE, 'uq_cp_active', true)) {
                RawSchema::dropIndex(self::TABLE, 'uq_cp_active');
            }

            if (Schema::hasColumn(self::TABLE, 'active_guard')) {
                RawSchema::dropColumn(self::TABLE, 'active_guard');
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
};
