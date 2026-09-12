<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 · §1.4 — settings.
 *
 * Key/value store grouped by domain, read through App\Support\SettingsRepository.
 * No soft deletes, no blameable (§1).
 *
 * unique(group, key) = (64 + 128) chars × 4 bytes = 768 bytes, well under the
 * MariaDB 10.4 InnoDB DYNAMIC 3072-byte key limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            return;
        }

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 64);
            $table->string('key', 128);
            $table->longText('value')->nullable();
            $table->string('type', 16)->default('string');
            $table->json('options')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->boolean('is_public')->default(false);
            $table->string('label', 150)->nullable();
            $table->string('description', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
