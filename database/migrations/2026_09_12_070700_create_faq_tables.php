<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · §2.9, §2.10 and §2.11 — faq_categories, faqs and the faq_website_section pivot.
 *
 * Shared CMS infrastructure (§1.3): course FAQs (§90, Phase 14-17) attach through the
 * nullable `faqable_type` / `faqable_id` morph. No `course_faqs` table exists (F-2.2).
 * The pivot carries the FAQs hand-picked into a FAQ section and is used only when the
 * section's `content.source = selected`.
 *
 * Created after website_sections, which the pivot references.
 *
 * `faq_categories` and `faqs` are mutable content tables → timestamps + softDeletes +
 * blameable. `faq_website_section` is a pivot → timestamps only (§2.11).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('faq_categories')) {
            Schema::create('faq_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 150);
                $table->string('description', 300)->nullable();
                $table->string('icon', 64)->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.9 Keys.
                $table->unique('slug', 'uq_faqcat_slug');
                $table->index(['is_enabled', 'sort_order']);
            });
        }

        if (! Schema::hasTable('faqs')) {
            Schema::create('faqs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('faq_category_id')->nullable()
                    ->constrained('faq_categories')->nullOnDelete();
                // The hook for course FAQs (§90). Phase 3 leaves it NULL; Phase 14 must
                // use it instead of a `course_faqs` table. nullableMorphs() also supplies
                // §2.10's INDEX (faqable_type, faqable_id).
                $table->nullableMorphs('faqable');
                $table->string('question', 300);
                $table->longText('answer');
                $table->string('status', 16)->default('draft');
                $table->boolean('is_featured')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.10 Keys.
                $table->index(['faq_category_id', 'status', 'sort_order'], 'idx_faq_public');
                $table->index(['is_featured', 'status']);
            });
        }

        if (! Schema::hasTable('faq_website_section')) {
            Schema::create('faq_website_section', function (Blueprint $table): void {
                $table->unsignedBigInteger('faq_id');
                $table->unsignedBigInteger('website_section_id');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                // §2.11 Keys. The composite PK leads with faq_id, so it also serves that
                // foreign key; the second index is the contract's curated-order index and
                // serves the website_section_id foreign key too.
                $table->primary(['faq_id', 'website_section_id']);
                $table->index(['website_section_id', 'sort_order']);

                $table->foreign('faq_id')
                    ->references('id')->on('faqs')->cascadeOnDelete();
                $table->foreign('website_section_id')
                    ->references('id')->on('website_sections')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Child-first: the pivot owns the foreign keys into faqs and website_sections,
        // and faqs owns the one into faq_categories. DROP TABLE removes a table's
        // foreign keys in the same statement, so no separate ALTER is needed here —
        // up() created whole tables and dropped no column.
        Schema::dropIfExists('faq_website_section');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('faq_categories');
    }
};
