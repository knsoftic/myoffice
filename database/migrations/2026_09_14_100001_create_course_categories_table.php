<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 · file 1 — `course_categories`: the catalogue's top level (§64, phase-14-17 §2.3).
 *
 * **Flat on purpose.** There is no `parent_id`: §64 asks for a reorderable list, and a tree would buy
 * one screen a nesting nobody asked for while every catalogue query, every breadcrumb and every filter
 * gained a recursion.
 *
 * **`sort_order` is deliberately not unique.** A unique sort column makes the ordinary act — dragging
 * row 3 above row 2 — impossible without a temporary value, and the workaround is always a
 * three-statement dance that can half-fail. The order is a preference, not an identity.
 *
 * **No `branch_id` (§2.2).** A category is a way of describing the catalogue, and the catalogue is one
 * catalogue: a branch that offers only some of the courses inside a category still uses that category's
 * name for them.
 */
return new class extends Migration
{
    private const TABLE = 'course_categories';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        // Ensured on every run, never only on creation (D70): MariaDB DDL is not transactional, so a
        // CREATE that failed halfway can leave a table standing with none of its CHECKs.
        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->string('name', 150);
            // Generated from the name and then editable: the institute owns its own URLs, and a slug
            // that silently followed a rename would break every link already shared.
            $table->string('slug', 170);
            $table->string('description', 500)->nullable();
            // An icon token, never markup — the value reaches a Blade component, not innerHTML.
            $table->string('icon', 64)->nullable();
            $table->string('image_path', 255)->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            // A CACHE of non-archived courses, rewritten by CourseCategoryService::recount(). No screen
            // may treat it as truth; the recount command is what makes it re-derivable.
            $table->unsignedInteger('courses_count')->default(0);

            $table->string('seo_title', 180)->nullable();
            $table->string('seo_description', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('slug', 'uq_cc_slug');
            $table->index(['is_active', 'sort_order'], 'idx_cc_active_sort');
            $table->index('name', 'idx_cc_name');

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        // Negative order would sort a category above one the admin never touched, which reads as a bug
        // rather than as a choice.
        $this->ensure('chk_cc_sort', '`sort_order` >= 0');
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
