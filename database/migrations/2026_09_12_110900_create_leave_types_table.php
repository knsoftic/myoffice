<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 · §2.12 — leave_types: requirement §27's leave kinds, with their annual quotas.
 *
 * Every rule a business might have about a leave type lives here as data rather than as code: how the
 * quota accrues, whether it carries forward and for how long, how much notice it needs, whether it may
 * be taken half a day at a time, whether weekends and holidays inside a range count against it, which
 * employment types may use it, and how many approval levels it goes through.
 *
 * `color` is a plain column rather than an enum `color()` — leave types are **data**, seeded and then
 * edited by the business, so the calendar's colour has to be editable too.
 *
 * `excludes_weekends` / `excludes_holidays` are what make "5 calendar days cost 3 quota days" explainable:
 * the skipped days are still written as `leave_request_days` rows marked not counted.
 */
return new class extends Migration
{
    private const TABLE = 'leave_types';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->decimal('annual_quota_days', 6, 2)->default(0);
            $table->boolean('is_paid')->default(true);
            $table->string('accrual_method', 16)->default('annual_grant');
            $table->decimal('accrual_days_per_month', 6, 2)->default(0);
            $table->boolean('accrue_from_joining')->default(true);
            $table->boolean('carry_forward_enabled')->default(false);
            $table->decimal('max_carry_forward_days', 6, 2)->default(0);
            $table->unsignedTinyInteger('carry_forward_expiry_months')->default(0);
            $table->unsignedSmallInteger('max_consecutive_days')->default(0);
            $table->unsignedSmallInteger('min_notice_days')->default(0);
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('allow_negative_balance')->default(false);
            $table->boolean('requires_attachment')->default(false);
            $table->unsignedSmallInteger('attachment_required_after_days')->default(0);
            $table->boolean('excludes_weekends')->default(true);
            $table->boolean('excludes_holidays')->default(true);
            $table->json('applies_to_employment_types')->nullable();
            $table->boolean('allowed_on_probation')->default(false);
            $table->unsignedTinyInteger('approval_levels')->default(1);
            $table->boolean('is_encashable')->default(false);
            $table->string('color', 16)->default('slate');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.12 Keys.
            $table->unique('code', 'uq_lt_code');
            $table->unique(['name', 'deleted_at'], 'uq_lt_name');
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
