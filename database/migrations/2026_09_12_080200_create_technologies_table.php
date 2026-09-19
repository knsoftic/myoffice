<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.4 — technologies.
 *
 * The tech-stack chip shared by services and portfolio items ("Laravel", "Flutter"); the slug is the
 * public filter value. The logo is a `media_assets` row with `ImageProfile::Logo` (decision D24).
 *
 * Created before `services` and `portfolio_items` because both link pivots reference it.
 *
 * Mutable catalogue table → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('technologies')) {
            return;
        }

        Schema::create('technologies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 180);
            $table->unsignedBigInteger('logo_media_id')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('color', 16)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.4 Indexes.
            $table->unique('slug');
            $table->index(['is_active', 'sort_order']);
            $table->index('logo_media_id');

            $table->foreign('logo_media_id')->references('id')->on('media_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Inbound keys (service_technology, portfolio_item_technology) belong to later Phase 4
        // migrations and are rolled back first; DROP TABLE removes the keys this table owns.
        Schema::dropIfExists('technologies');
    }
};
