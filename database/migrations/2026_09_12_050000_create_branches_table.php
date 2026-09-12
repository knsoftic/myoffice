<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 · §1.5 — branches.
 *
 * Business table: timestamps + softDeletes + blameable (created_by / updated_by).
 * Created before `users` is extended because `users.branch_id` references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branches')) {
            return;
        }

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 150);
            $table->string('phone', 32)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('address', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Dropping the table also drops the foreign keys it owns. Foreign keys that
        // point *at* branches (users.branch_id) are removed by the migration that
        // created them, which rolls back first.
        Schema::dropIfExists('branches');
    }
};
