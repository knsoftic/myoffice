<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19 · file 3 — `course_material_downloads` (phase-19-23 §2.5, §79, INV-19-4).
 *
 * The access log, on Phase 4's `blog_post_views` pattern: **`created_at` only — no `updated_at`, no
 * `deleted_at`, no blameable** (`CLAUDE.md` §3, D19). A row says a person opened a file at a moment. It
 * cannot later become untrue, so there is nothing to edit and nothing to hide.
 *
 * **INV-19-4: the row is written *before* the stream starts.** §6.4 step 6 puts logging ahead of step 7
 * deliberately — a stream that begins unlogged is a file served with no record, and the failure mode of
 * logging afterwards is exactly the case you most want recorded: the download that crashed half way.
 * `bytes_sent` is therefore nullable, and null means the stream did not finish.
 *
 * `user_id`, `student_id` and `teacher_id` are all kept because they answer different questions. The
 * user is the account; the student is the person the entitlement belonged to; the teacher is the one who
 * may look at their own batch's figures. A staff download resolves to a user and neither of the others,
 * and that is a meaningful row rather than a gap.
 *
 * **Every FK is `nullOnDelete` except the material's.** A user or student may be removed without erasing
 * the evidence of what was accessed (D11); a log line about a material that no longer exists, though,
 * describes nothing at all.
 */
return new class extends Migration
{
    private const TABLE = 'course_material_downloads';

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

            $table->unsignedBigInteger('course_material_id');
            // The actor's account.
            $table->unsignedBigInteger('user_id')->nullable();
            // Set when the actor resolved to a student — the entitlement that let them through.
            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();

            // Which portal the open came from; cast PanelType.
            $table->string('panel', 16);
            // view / download / link_open; cast MaterialAccessAction.
            $table->string('action', 16);

            // Null when the stream was aborted — which is the case worth being able to see.
            $table->unsignedBigInteger('bytes_sent')->nullable();

            // 45 characters: an IPv6 address with an IPv4 tail is the longest form there is.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device', 64)->nullable();

            // Written once. No updated_at (D19), and MariaDB gives no implicit ON UPDATE to a
            // nullable timestamp with no default.
            $table->timestamp('created_at')->nullable();

            $table->index(['course_material_id', 'created_at'], 'idx_cmd_material');
            $table->index(['student_id', 'created_at'], 'idx_cmd_student');
            $table->index(['user_id', 'created_at'], 'idx_cmd_user');
            $table->index(['action', 'created_at'], 'idx_cmd_action');

            $table->foreign('course_material_id', RawSchema::foreignKeyName(self::TABLE, 'course_material_id'))
                ->references('id')->on('course_materials')->cascadeOnDelete();
            $table->foreign('user_id', RawSchema::foreignKeyName(self::TABLE, 'user_id'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('student_id', RawSchema::foreignKeyName(self::TABLE, 'student_id'))
                ->references('id')->on('students')->nullOnDelete();
            $table->foreign('teacher_id', RawSchema::foreignKeyName(self::TABLE, 'teacher_id'))
                ->references('id')->on('teachers')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_cmd_action', "`action` IN ('view', 'download', 'link_open')");
        // A stream that sent nothing is recorded as null, not as zero: zero would claim the stream
        // completed and delivered an empty file.
        $this->ensure('chk_cmd_bytes', '`bytes_sent` IS NULL OR `bytes_sent` >= 0');
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
