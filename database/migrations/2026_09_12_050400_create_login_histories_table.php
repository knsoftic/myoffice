<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 · §1.6 — login_histories.
 *
 * Written by the auth listeners (RecordSuccessfulLogin / RecordFailedLogin /
 * RecordLogout). `user_id` is nullable because failed attempts may not resolve to
 * a user; `email` captures what was typed. No soft deletes, no blameable (§1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('login_histories')) {
            return;
        }

        Schema::create('login_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('email', 255)->nullable();
            $table->string('status', 16)->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device', 64)->nullable();
            $table->string('platform', 64)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('session_id', 255)->nullable()->index();
            $table->timestamp('logged_in_at')->nullable();
            $table->timestamp('logged_out_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_histories');
    }
};
