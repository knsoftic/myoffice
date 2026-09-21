<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 · file 4 — `course_topic_resources`: the **syllabus** resource list of §65
 * (phase-14-17 §2.8).
 *
 * **This is not Phase 19's `course_materials`, and the difference is who may see it.** A resource here
 * is part of the published outline — the reading a visitor can see listed before they enrol — and an
 * `is_public` one appears on the landing page. Phase 19's material is distributable content gated by
 * enrollment and assignable to one batch or one student. One table for both would mean every download
 * route had to ask which kind it was holding.
 *
 * **`mime_type` records what the file *is*, not what it claimed.** The service runs the content through
 * `finfo` and checks it against `CourseResourceType::allowedMimes()` (§111); the column stores that
 * answer, so a later audit reads the verdict rather than the upload's own story about itself.
 *
 * **`chk_ctr_target` is the rule that a resource points at something.** A row with neither a file nor a
 * URL would render as a link to nowhere on the public page, which is worse than not listing it.
 */
return new class extends Migration
{
    private const TABLE = 'course_topic_resources';

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

            $table->unsignedBigInteger('course_topic_id');
            $table->unsignedBigInteger('course_id');

            $table->string('title', 180);
            $table->string('type', 24);

            $table->string('file_path', 255)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('mime_type', 120)->nullable();

            // Public means "on the course landing page, before anybody applies". Everything else needs
            // `course_outline.view`.
            $table->boolean('is_public')->default(false);
            $table->boolean('is_downloadable')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->index(['course_topic_id', 'sort_order'], 'idx_ctr_topic_sort');
            $table->index(['course_id', 'is_public'], 'idx_ctr_course_public');

            $table->foreign('course_topic_id', RawSchema::foreignKeyName(self::TABLE, 'course_topic_id'))
                ->references('id')->on('course_topics')->cascadeOnDelete();
            $table->foreign('course_id', RawSchema::foreignKeyName(self::TABLE, 'course_id'))
                ->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_ctr_target', '`file_path` IS NOT NULL OR `external_url` IS NOT NULL');

        $this->ensure(
            'chk_ctr_type',
            "`type` IN ('pdf', 'document', 'note', 'slide', 'image', 'video', 'audio', 'zip',"
            ." 'source_code', 'link')",
        );
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
