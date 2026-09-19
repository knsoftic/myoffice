<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.3 and §2.5 — services and the service_technology pivot.
 *
 * `services` is one sellable service (§11). `starting_price` is money: `decimal(15,2)`, nullable
 * (null renders "on request") and never touched by PHP arithmetic. No SEO column (decision D23); the
 * image is a `media_assets` row (decision D24). `contact_inquiries.service_id` (a later Phase 4
 * migration) and — from Phase 5/6/8 — `leads.service_id`, `projects.service_id` and
 * `collaborator_service` point here (build-order §3 row E20).
 *
 * `service_technology` is a link pivot: composite primary key, no timestamps, no soft deletes
 * (decision D19 history pivot).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            Schema::create('services', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('service_category_id')->nullable();
                $table->string('name', 150);
                $table->string('slug', 180);
                $table->string('short_description', 500)->nullable();
                $table->longText('full_description')->nullable();
                $table->string('icon', 64)->nullable();
                $table->unsignedBigInteger('image_media_id')->nullable();
                $table->decimal('starting_price', 15, 2)->nullable();
                $table->string('price_note', 100)->nullable();
                $table->boolean('price_visible')->default(true);
                $table->json('features')->nullable();
                $table->string('status', 32)->default('draft');
                $table->boolean('is_featured')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.3 Indexes. (service_category_id, status) also serves the category foreign key.
                $table->unique('slug');
                $table->index(['status', 'is_featured', 'sort_order']);
                $table->index(['service_category_id', 'status']);
                $table->index('image_media_id');
                $table->index('is_featured');

                $table->foreign('service_category_id')->references('id')->on('service_categories')->nullOnDelete();
                $table->foreign('image_media_id')->references('id')->on('media_assets')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('service_technology')) {
            Schema::create('service_technology', function (Blueprint $table): void {
                $table->unsignedBigInteger('service_id');
                $table->unsignedBigInteger('technology_id');
                $table->unsignedSmallInteger('sort_order')->default(0);

                // §2.5: composite primary key (it leads with service_id, so it serves that foreign key).
                $table->primary(['service_id', 'technology_id']);
                $table->index('technology_id');

                $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
                $table->foreign('technology_id')->references('id')->on('technologies')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Child first: the pivot owns the keys into services and technologies. Inbound keys to
        // services (contact_inquiries.service_id) belong to a later Phase 4 migration.
        Schema::dropIfExists('service_technology');
        Schema::dropIfExists('services');
    }
};
