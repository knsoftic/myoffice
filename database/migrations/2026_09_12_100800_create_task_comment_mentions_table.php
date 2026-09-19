<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 · §2.8 — task_comment_mentions: §22's mentions, one row per mentioned user.
 *
 * A row per mention is what makes "mentions of me" an index lookup instead of a `LIKE '%@name%'` scan over
 * every comment body.
 *
 * **No soft deletes** (D19, CLAUDE.md §3): this is a history pivot with no independent life — it exists
 * only while its comment does, and `cascadeOnDelete` on both sides says so.
 *
 * `uq_tcm_pair` gives one mention per person per comment however many times the handle appears in the
 * body, so a notification cannot be sent twice for one comment.
 *
 * Only users who are **active members of the comment's project** (or its manager) may be mentioned, which
 * is enforced in the service: the autocomplete must not become a way to enumerate staff.
 */
return new class extends Migration
{
    private const TABLE = 'task_comment_mentions';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_comment_id')->constrained('task_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            // No softDeletes(): append-only history pivot (D19).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.8 Keys.
            $table->unique(['task_comment_id', 'user_id'], 'uq_tcm_pair');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
