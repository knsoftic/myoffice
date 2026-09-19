<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 · §2.7 — task_comments: §22's comments and §59's "add comments" for collaborators.
 *
 * `author_name` is a snapshot so a deleted user does not erase the conversation. `body` is stored **raw
 * and escaped on render** — this is plain text, not rich text, so `RichText` (D25) is deliberately not in
 * the path and no HTML is ever stored.
 *
 * `visibility` casts to `CommentVisibility`: `internal` is staff-only and `team` includes the project's
 * collaborators (§112). There is no `client` case — the client panel is read-only over projects,
 * milestones, tasks and files and never sees the task conversation (§7.7).
 *
 * A comment is editable by its author only, and only inside `projects.task_comment_edit_minutes`; every
 * edit and delete writes an activity row carrying the old body (§107), which is why `edited_at` exists
 * rather than a silent in-place update.
 */
return new class extends Migration
{
    private const TABLE = 'task_comments';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 150);
            $table->text('body');
            $table->string('visibility', 16)->default('team');
            $table->unsignedTinyInteger('mention_count')->default(0);
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.7 Keys.
            $table->index(['task_id', 'created_at']);
            $table->index('user_id');
            $table->index('visibility');
        });
    }

    public function down(): void
    {
        // task_comment_mentions.task_comment_id points here and is rolled back first.
        Schema::dropIfExists(self::TABLE);
    }
};
