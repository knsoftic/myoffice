<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 · file 2 — `courses`: every field of §62 (phase-14-17 §2.4).
 *
 * **The three fee columns are the agreed price list, not money.** Nothing here is a charge: Phase 18's
 * `StudentFeeService` reads these to build a fee structure at admission, and a later edit to a price
 * changes what the *next* student is quoted and nothing that has already been sold (INV-I1, INV-I2).
 * They are `decimal(15,2)` like every other money column in the system, and a CHECK refuses a negative.
 *
 * **`slug` is the public contract.** Once `published_at` is stamped, a link to `/courses/{slug}` may be
 * in a WhatsApp forward, a printed flyer or somebody's bookmarks; the service refuses to change it
 * without a reason, and this column's uniqueness is what makes that reason worth recording.
 *
 * **`branch_id` is nullable and null means every branch** (§2.2, D11). The catalogue and the public
 * site are one site: a branch-specific course is the exception, so the exception is what carries a
 * value.
 *
 * **The four `*_count` columns and `outline_minutes` are CACHES** written only by `CourseOutlineService`
 * and `CourseService::recountOutline()`. Nothing reads them to decide anything — the completeness check
 * that gates publishing counts real modules — and a recount rewrites them from the tree.
 *
 * `default_teacher_id` has no FK here: `teachers` ships in Phase 16. The guarded migration that adds it
 * runs then ([D-IN-1]), so `migrate:fresh` works in either order.
 */
return new class extends Migration
{
    private const TABLE = 'courses';

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

            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('course_category_id');

            $table->string('code', 32);
            $table->string('name', 180);
            $table->string('slug', 200);
            $table->string('short_description', 500)->nullable();
            $table->longText('full_description')->nullable();

            $table->string('image_path', 255)->nullable();
            $table->string('thumbnail_path', 255)->nullable();
            $table->string('promo_video_url', 255)->nullable();

            $table->unsignedSmallInteger('duration_value')->nullable();
            $table->string('duration_unit', 16)->default('weeks');
            $table->unsignedSmallInteger('total_classes')->nullable();
            // Null falls back to `institute.default_class_duration` at the moment a batch is planned,
            // so changing the institute default moves every course that never stated its own.
            $table->unsignedSmallInteger('class_duration_minutes')->nullable();

            $table->decimal('course_fee', 15, 2)->default(0);
            $table->decimal('admission_fee', 15, 2)->default(0);
            $table->decimal('registration_fee', 15, 2)->default(0);
            // Not in §62. Phase 18 §13.1 asks for it so `fees:generate-monthly` can prefill rather than
            // ask every month; null means the course has no monthly head at all.
            $table->decimal('monthly_fee', 15, 2)->nullable();

            $table->boolean('installment_available')->default(false);
            $table->unsignedTinyInteger('max_installments')->default(0);
            $table->string('installment_note', 255)->nullable();

            $table->string('level', 16)->default('beginner');
            $table->string('delivery_mode', 16)->default('physical');
            $table->unsignedBigInteger('default_teacher_id')->nullable();

            // Ordered arrays of trimmed strings, normalised by the service — never free-form JSON from
            // a request body.
            $table->json('requirements')->nullable();
            $table->json('outcomes')->nullable();

            $table->boolean('certificate_available')->default(false);
            $table->boolean('is_featured')->default(false);
            // Effective admission is this AND `institute.admission_open`: one switch for the institute,
            // one per course, and `CourseService::effectiveAdmissionOpen()` is the only place they meet.
            $table->boolean('admission_open')->default(true);

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->integer('sort_order')->default(0);

            $table->unsignedSmallInteger('modules_count')->default(0);
            $table->unsignedSmallInteger('topics_count')->default(0);
            $table->unsignedSmallInteger('lectures_count')->default(0);
            $table->unsignedInteger('outline_minutes')->default(0);

            $table->string('seo_title', 180)->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('seo_keywords', 255)->nullable();
            $table->string('og_image_path', 255)->nullable();
            $table->string('canonical_url', 255)->nullable();
            $table->boolean('is_indexable')->default(true);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('code', 'uq_co_code');
            $table->unique('slug', 'uq_co_slug');

            $table->index(['status', 'is_featured', 'sort_order'], 'idx_co_catalogue');
            $table->index(['course_category_id', 'status'], 'idx_co_category');
            $table->index('level', 'idx_co_level');
            $table->index('delivery_mode', 'idx_co_mode');
            $table->index(['branch_id', 'status'], 'idx_co_branch');
            $table->index('default_teacher_id', 'idx_co_teacher');
            $table->index(['admission_open', 'status'], 'idx_co_admission');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            // restrictOnDelete: a category holding courses cannot be removed, and the UI offers "move
            // the courses first" rather than a cascade that would take the catalogue with it.
            $table->foreign('course_category_id', RawSchema::foreignKeyName(self::TABLE, 'course_category_id'))
                ->references('id')->on('course_categories')->restrictOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        $this->ensure('chk_co_money', '`course_fee` >= 0 AND `admission_fee` >= 0 AND `registration_fee` >= 0');

        // The two halves of "offers installments" must agree. A course advertising installments with a
        // maximum of zero would show the option and then refuse every plan.
        $this->ensure(
            'chk_co_installments',
            '(`installment_available` = 0 AND `max_installments` = 0)'
            .' OR (`installment_available` = 1 AND `max_installments` BETWEEN 1 AND 36)',
        );

        $this->ensure('chk_co_duration', '`duration_value` IS NULL OR `duration_value` > 0');
        $this->ensure('chk_co_status', "`status` IN ('draft', 'published', 'archived')");
        $this->ensure('chk_co_level', "`level` IN ('beginner', 'intermediate', 'advanced')");
        $this->ensure('chk_co_mode', "`delivery_mode` IN ('physical', 'online', 'hybrid')");
        $this->ensure('chk_co_unit', "`duration_unit` IN ('hours', 'days', 'weeks', 'months')");
        $this->ensure('chk_co_monthly', '`monthly_fee` IS NULL OR `monthly_fee` >= 0');
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
