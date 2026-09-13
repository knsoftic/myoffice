<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 · §1 — per-user UI state.
 *
 * One nullable json column holding dashboard widget order and hidden widgets, table column
 * choices and the sidebar collapsed flag. It is read and written only through
 * `User::preference(key, default)` / `User::setPreference(key, value)` — never raw in a view, and
 * never mass-assigned from a request payload.
 *
 * Additive only, guarded with hasColumn, no foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'preferences')) {
                $table->json('preferences')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'preferences')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('preferences');
        });
    }
};
