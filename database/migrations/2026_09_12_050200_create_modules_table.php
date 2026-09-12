<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 · §1.3 — modules.
 *
 * Drives module enable/disable gating. No soft deletes, no blameable (§1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('modules')) {
            return;
        }

        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('group', 32)->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('is_core')->default(false);
            $table->integer('sort_order')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
