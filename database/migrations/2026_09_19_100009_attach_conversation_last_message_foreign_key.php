<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 · file 9 — the one foreign key that could not be declared with its table
 * (phase-19-23 §2.22).
 *
 * `conversations.last_message_id` points at `messages.id`, and `messages.conversation_id` points
 * back. One of the two has to be added after both tables exist; this is that one, on its own, doing
 * nothing else — the same shape phase-14's deferred course keys use, for the same reason.
 *
 * **`nullOnDelete`, not cascade.** A message is append-only and never deleted, so this is a
 * constraint that should never fire; if it somehow did, emptying a pointer is survivable and taking
 * the whole conversation with it is not.
 *
 * **Idempotent both ways.** D70: MariaDB DDL is not transactional, so a half-applied run has to be
 * safe to repeat, and a rollback that ran twice must not fail on a constraint it already dropped.
 */
return new class extends Migration
{
    private const TABLE = 'conversations';

    private const COLUMN = 'last_message_id';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasTable('messages')) {
            return;
        }

        if ($this->exists()) {
            return;
        }

        Schema::table(self::TABLE, function ($table): void {
            $table->foreign(self::COLUMN, RawSchema::foreignKeyName(self::TABLE, self::COLUMN))
                ->references('id')->on('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! $this->exists()) {
            return;
        }

        Schema::table(self::TABLE, function ($table): void {
            $table->dropForeign(RawSchema::foreignKeyName(self::TABLE, self::COLUMN));
        });
    }

    /** Asked of `information_schema` by name, filtered to this schema — never assumed. */
    private function exists(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS present FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [self::TABLE, RawSchema::foreignKeyName(self::TABLE, self::COLUMN), 'FOREIGN KEY'],
        ) !== null;
    }
};
